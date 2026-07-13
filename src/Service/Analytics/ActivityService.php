<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Сповіщення менеджерам про активність на сайті artbeton.market.
 * - Значущі кліки (телефон / Telegram / онлайн-консультант) → одразу в групу «Заявки ArtBeton»
 *   (з throttle per-IP+тип, щоб один активний відвідувач не флудив).
 * - Візити та решта подій → агрегуються й шлються дайджестом (команда app:activity:digest, cron щогодини).
 */
final class ActivityService
{
    /** Значущі події: тип → підпис. Тільки ці шлемо одразу. */
    private const IMMEDIATE = [
        'click_phone' => '📞 Клікнув по телефону',
        'click_telegram' => '✈️ Клікнув «Написати в Telegram»',
        'open_chat' => '💬 Відкрив онлайн-консультанта',
    ];

    /** Усі типи, що враховуємо в дайджесті. */
    private const COUNTED = [
        'visit',
        'view_catalog',
        'view_product',
        'add_to_cart',
        'begin_checkout',
        'click_phone',
        'click_telegram',
        'open_chat',
    ];

    /** Підписи для рядків погодинного дайджесту (порядок = порядок у повідомленні). */
    private const DIGEST_LABELS = [
        'view_catalog' => '📂 Каталог',
        'view_product' => '🛍 Перегляди товару',
        'add_to_cart' => '🛒 Додали в кошик',
        'begin_checkout' => '🧾 Почали оформлення',
        'click_phone' => '📞 Клік по телефону',
        'click_telegram' => '✈️ Клік Telegram',
        'open_chat' => '💬 Відкрили консультанта',
    ];

    private const THROTTLE_TTL = 600;   // одна миттєва нотифікація на IP+тип за 10 хв
    private const COUNTER_TTL = 7200;   // лічильники живуть 2 год

    public function __construct(
        private readonly TelegramNotifier $notifier,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isKnownType(string $type): bool
    {
        return \in_array($type, self::COUNTED, true);
    }

    /**
     * Зафіксувати подію: збільшити лічильник для дайджесту й (для значущих) сповістити одразу.
     */
    public function record(string $type, string $ip, ?string $page = null): void
    {
        if (!$this->isKnownType($type)) {
            return;
        }

        $this->incr($this->counterKey($type));

        if (isset(self::IMMEDIATE[$type]) && !$this->throttled($type, $ip)) {
            $text = self::IMMEDIATE[$type];
            if (null !== $page && '' !== $page) {
                $text .= "\n🔗 ".TelegramNotifier::esc(mb_substr($page, 0, 300));
            }
            $this->notifier->send($text);
        }
    }

    /**
     * Сформувати й надіслати погодинний дайджест; скинути лічильники.
     * Повертає true, якщо було що надсилати.
     */
    public function sendDigest(): bool
    {
        $counts = [];
        foreach (self::COUNTED as $type) {
            $counts[$type] = $this->readAndReset($this->counterKey($type));
        }

        $visits = $counts['visit'];
        $meaningful = array_sum(array_diff_key($counts, ['visit' => 0]));
        if ($visits <= 0 && $meaningful <= 0) {
            return false; // нічого не сталося — не спамимо
        }

        $lines = ['📊 Активність на сайті за годину', '👀 Візити: '.$visits];
        foreach (self::DIGEST_LABELS as $type => $label) {
            if (($counts[$type] ?? 0) > 0) {
                $lines[] = $label.': '.$counts[$type];
            }
        }

        return $this->notifier->send(implode("\n", $lines));
    }

    /**
     * Миттєве сповіщення про нове (щойно створене) замовлення — прямо з бекенду,
     * не через /api/event. Не заходить у дайджест.
     */
    public function orderPlaced(int $orderId, string $amountLine, ?string $customer = null, ?int $items = null): void
    {
        $lines = ['🆕 <b>Нове замовлення #'.$orderId.'</b>'];
        if (null !== $customer && '' !== trim($customer)) {
            $lines[] = '👤 '.TelegramNotifier::esc($customer);
        }
        if (null !== $items && $items > 0) {
            $lines[] = '📦 Позицій: '.$items;
        }
        $lines[] = '💰 '.TelegramNotifier::esc($amountLine);
        $this->notifier->send(implode("\n", $lines));
    }

    /**
     * Миттєве сповіщення про підтверджену оплату замовлення.
     * $extraHtml — готовий HTML-хвіст (сума/промокод/клієнт/лінк), ескейпиться на місці виклику.
     */
    public function orderPaid(int $orderId, string $extraHtml): void
    {
        $this->notifier->send('💰 <b>Оплата отримана — замовлення #'.$orderId.'</b>'."\n".$extraHtml);
    }

    private function counterKey(string $type): string
    {
        return 'activity_'.$type;
    }

    private function throttled(string $type, string $ip): bool
    {
        $item = $this->cache->getItem('act_thr_'.$type.'_'.md5($ip));
        if ($item->isHit()) {
            return true;
        }
        $item->set(1)->expiresAfter(self::THROTTLE_TTL);
        $this->cache->save($item);

        return false;
    }

    private function incr(string $key): void
    {
        try {
            $item = $this->cache->getItem($key);
            $val = ($item->isHit() ? (int) $item->get() : 0) + 1;
            $item->set($val)->expiresAfter(self::COUNTER_TTL);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->error('Activity incr failed', ['error' => $e->getMessage()]);
        }
    }

    private function readAndReset(string $key): int
    {
        $item = $this->cache->getItem($key);
        $val = $item->isHit() ? (int) $item->get() : 0;
        if ($val > 0) {
            $this->cache->deleteItem($key);
        }

        return $val;
    }
}
