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
 * Не частіше разу на добу на кожен вид збою — консультант смикає API на кожне
 * повідомлення, і без цього група отримала б по тривозі на кожне питання.
 */
final class ConsultantAlert
{
    public function __construct(
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly TelegramNotifier $notifier,
        private readonly ConsultantUsage $usage,
        private readonly string $alertMention = '',
    ) {
    }

    public function failed(\Throwable $e): void
    {
        $this->usage->failure();
        $error = $e->getMessage();

        [$kind, $text] = match (true) {
            str_contains($error, 'credit balance') => ['balance', '⚠️ <b>ІІ-консультант не працює: закінчився баланс Anthropic.</b>'
                ."\nПокупці бачать заглушку «зв'яжіться з оператором»."
                ."\nПоповнити: https://console.anthropic.com/settings/billing"],
            str_contains($error, 'authentication') || str_contains($error, 'invalid x-api-key') => ['auth', '⚠️ <b>ІІ-консультант не працює: ключ Anthropic недійсний.</b>'
                ."\nПотрібен новий API-ключ: https://console.anthropic.com/settings/keys"],
            default => ['other', '⚠️ <b>ІІ-консультант не відповідає</b> — помилка Anthropic:'
                ."\n<i>".TelegramNotifier::esc(mb_substr($error, 0, 300)).'</i>'],
        };

        $this->onceADay('alert_'.$kind, $text);
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
