<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Global daily spend ceiling for the AI consultant (in UAH). When the day's
 * spend exceeds the cap, the consultant is temporarily disabled so we don't
 * "burn" the client's budget, and the managers get a one-time Telegram alert
 * in the «Консультант» topic (ConsultantAlert).
 *
 * Accumulation lives in a persistent cache pool (survives deploy/cache:clear),
 * bucketed by calendar day.
 */
final class BudgetGuard
{
    public function __construct(
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly float $dailyBudgetUah,
        private readonly ConsultantAlert $alert,
    ) {
    }

    public function limitUah(): float
    {
        return $this->dailyBudgetUah;
    }

    /** One-time (per day) manager alert that the ceiling was reached. */
    public function notifyExceededOnce(): void
    {
        $this->alert->budgetExceeded(number_format($this->dailyBudgetUah, 0, '.', ' '));
    }

    private function key(): string
    {
        return 'ai_spend_uah_'.date('Ymd');
    }

    public function spentUah(): float
    {
        $item = $this->supportChatCache->getItem($this->key());

        return $item->isHit() ? (float) $item->get() : 0.0;
    }

    public function add(float $uah): void
    {
        if ($uah <= 0) {
            return;
        }
        $item = $this->supportChatCache->getItem($this->key());
        $value = ($item->isHit() ? (float) $item->get() : 0.0) + $uah;
        $item->set($value);
        $item->expiresAfter(172800); // 2 days — keep the daily bucket alive
        $this->supportChatCache->save($item);
    }

    /** Budget exhausted for today? (0 or less — limit disabled). */
    public function isOverBudget(): bool
    {
        return $this->dailyBudgetUah > 0 && $this->spentUah() >= $this->dailyBudgetUah;
    }
}
