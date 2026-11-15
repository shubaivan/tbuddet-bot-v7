<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use App\Service\Analytics\TelegramNotifier;

/**
 * «💬 Діалог завершено» у тему «Консультант»: хто, коли, скільки питань,
 * коротке резюме, які товари запропонував консультант, чи кликали оператора
 * і скільки коштував саме цей діалог.
 *
 * Кінець діалогу — закриття віджета/вкладки (beacon) або 20 хв тиші (крон
 * app:consultant:dialogs-sweep), що настане раніше. Звіт іде рівно один раз:
 * ChatDialogStore::markEnded() віддає дані лише першому.
 */
class ChatDialogReport
{
    public function __construct(
        private readonly ChatDialogStore $store,
        private readonly ChatAssistantService $assistant,
        private readonly PricingService $pricing,
        private readonly BudgetGuard $budget,
        private readonly ConsultantUsage $usage,
        private readonly TelegramNotifier $notifier,
    ) {
    }

    /**
     * @param list<mixed>|null $messages історія з віджета (beacon); null — беремо збережену
     *
     * @return bool чи надіслано звіт (false — вже завершено раніше або не було питань)
     */
    public function finish(string $id, string $reason, ?array $messages = null): bool
    {
        $d = $this->store->markEnded($id);
        if (null === $d || (int) $d['questions'] < 1) {
            return false;
        }

        $history = null !== $messages && [] !== $messages ? $messages : (array) $d['messages'];

        $summary = '';
        if (!$this->budget->isOverBudget()) {
            $res = $this->assistant->summarize($history);
            $summary = trim($res['summary']);
            $d['input'] += $res['usage']['input'];
            $d['output'] += $res['usage']['output'];
            $this->budget->add($this->pricing->costUah($res['usage']['input'], $res['usage']['output']));
            if ($res['usage']['input'] > 0) {
                $this->usage->reply($res['usage']['input'], $res['usage']['output']);
            }
        }

        return $this->notifier->send(
            self::render($d, $summary, $reason, $this->pricing->costUah($d['input'], $d['output']), $this->pricing->costUsd($d['input'], $d['output'])),
            TelegramNotifier::TOPIC_CONSULTANT,
        );
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function render(array $d, string $summary, string $reason, float $uah, float $usd): string
    {
        $tz = new \DateTimeZone('Europe/Kyiv');
        $start = (new \DateTimeImmutable('@'.(int) $d['startedAt']))->setTimezone($tz);
        $end = (new \DateTimeImmutable('@'.(int) ($d['lastAt'] ?? $d['startedAt'])))->setTimezone($tz);
        $minutes = max(1, (int) round(((int) $d['lastAt'] - (int) $d['startedAt']) / 60));

        $name = trim((string) $d['name']);
        $lines = [
            '💬 <b>Діалог завершено</b>',
            '👤 '.('' !== $name ? TelegramNotifier::esc($name) : '<i>без імені</i>').' · 📞 '.TelegramNotifier::esc((string) $d['phone']),
            '🕒 '.$start->format('d.m H:i').'–'.$end->format('H:i').' ('.$minutes.' хв) · '.('idle' === $reason ? '20 хв тиші' : 'закрив чат'),
            '❓ Питань: <b>'.(int) $d['questions'].'</b> · відповідей: '.(int) $d['replies'],
        ];
        if ('' !== $summary) {
            $lines[] = '📝 '.TelegramNotifier::esc($summary);
        }

        $links = array_slice(array_values(array_unique((array) $d['links'])), 0, 8);
        if ([] !== $links) {
            $lines[] = '🔗 Запропонував:';
            foreach ($links as $url) {
                $lines[] = '• '.TelegramNotifier::esc((string) $url);
            }
        }

        $lines[] = '🙋 Оператор: '.($d['operator'] ? '<b>так, просив звʼязатися</b>' : 'ні');
        $lines[] = '💰 Вартість діалогу: '.number_format($uah, 2, ',', ' ').' грн ($'.number_format($usd, 3, '.', '').')';
        if ('' !== trim((string) ($d['page'] ?? ''))) {
            $lines[] = '🌐 '.TelegramNotifier::esc((string) $d['page']);
        }

        return implode("\n", $lines);
    }
}
