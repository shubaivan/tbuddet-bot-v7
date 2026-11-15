<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use App\Service\Analytics\TelegramNotifier;
use Psr\Cache\CacheItemPoolInterface;

/**
 * «ІІ-консультант не працює» — у тему «Консультант» групи менеджерів.
 *
 * Коли Anthropic відмовляє, покупець бачить лише заглушку з кнопкою оператора,
 * і без цього сигналу менеджери дізнаються про проблему від покупців.
 * 09.10.2026 так і сталося: закінчився баланс, консультант мовчки відповідав
 * заглушкою на кожне питання.
 *
 * «Баланс» і «ключ» — однозначні збої: їх віддаємо в спільний стан
 * ConsultantHealth, який пише лише на зміні стану (і сам повідомить «знову
 * працює»). Інші помилки (можуть бути разовими чи специфічними для запиту) —
 * як і раніше, не частіше разу на добу, і лише якщо health-check ще не
 * повідомив про збій.
 */
final class ConsultantAlert
{
    public function __construct(
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly TelegramNotifier $notifier,
        private readonly ConsultantUsage $usage,
        private readonly ConsultantHealth $health,
        private readonly string $alertMention = '',
    ) {
    }

    public function failed(\Throwable $e): void
    {
        $this->usage->failure();
        $error = $e->getMessage();

        $kind = ConsultantHealthState::classify($e);
        if (ConsultantHealthState::OTHER !== $kind) {
            $this->health->observe($kind);

            return;
        }

        if ($this->health->isFailing()) {
            return;
        }

        $this->onceADay('alert_other', '⚠️ <b>ІІ-консультант не відповідає</b> — помилка Anthropic:'
            ."\n<i>".TelegramNotifier::esc(mb_substr($error, 0, 300)).'</i>');
    }

    /** Денна стеля витрат досягнута — консультанта вимкнено до завтра. */
    public function budgetExceeded(string $limitUah): void
    {
        $this->onceADay('alert_budget', '⚠️ Досягнуто денну стелю витрат на ІІ-консультанта ('.TelegramNotifier::esc($limitUah).' грн).'
            ."\nКонсультант тимчасово вимкнено до завтра. Заявки та дзвінки працюють як звичайно.");
    }

    private function onceADay(string $key, string $text): void
    {
        $flag = $this->supportChatCache->getItem($key.'_'.date('Ymd'));
        if ($flag->isHit()) {
            return;
        }

        // Тривогу має побачити той, хто платить, — тегаємо його (TELEGRAM_MANAGER_ALERT_MENTION).
        if ('' !== trim($this->alertMention)) {
            $text .= "\n".TelegramNotifier::esc(trim($this->alertMention));
        }

        if ($this->notifier->send($text, TelegramNotifier::TOPIC_CONSULTANT)) {
            $flag->set(1)->expiresAfter(172800);
            $this->supportChatCache->save($flag);
        }
    }
}
