<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Скільки ІІ-консультант відповів і скільки це коштувало — по днях.
 *
 * Залишок балансу Anthropic звичайним API-ключем не дізнатись, тож рахуємо
 * самі з токенів кожної відповіді (ціни — PricingService). Цифри орієнтовні:
 * курс і тарифи з .env, а не з рахунку Anthropic.
 *
 * Лежить у тому ж постійному пулі, що й BudgetGuard (переживає деплой), але
 * живе 62 дні — щоб місячна сума збиралась навіть наприкінці місяця.
 */
final class ConsultantUsage
{
    private const TTL = 62 * 86400;

    public function __construct(
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly PricingService $pricing,
    ) {
    }

    /** Відповідь консультанта (або довідка для оператора) з її токенами. */
    public function reply(int $inputTokens, int $outputTokens): void
    {
        $this->bump(static function (array $d) use ($inputTokens, $outputTokens): array {
            ++$d['replies'];
            $d['input'] += $inputTokens;
            $d['output'] += $outputTokens;

            return $d;
        });
    }

    public function failure(): void
    {
        $this->bump(static function (array $d): array {
            ++$d['failures'];

            return $d;
        });
    }

    public function operatorRequest(): void
    {
        $this->bump(static function (array $d): array {
            ++$d['operator'];

            return $d;
        });
    }

    /** @return array{replies:int, input:int, output:int, failures:int, operator:int, usd:float, uah:float} */
    public function day(\DateTimeInterface $date): array
    {
        $item = $this->supportChatCache->getItem($this->key($date));
        $d = $item->isHit() && \is_array($item->get()) ? $item->get() + self::empty() : self::empty();

        return $d + [
            'usd' => $this->pricing->costUsd($d['input'], $d['output']),
            'uah' => $this->pricing->costUah($d['input'], $d['output']),
        ];
    }

    /** @param callable(array<string,int>): array<string,int> $change */
    private function bump(callable $change): void
    {
        $item = $this->supportChatCache->getItem($this->key(new \DateTimeImmutable()));
        $data = $item->isHit() && \is_array($item->get()) ? $item->get() + self::empty() : self::empty();
        $item->set($change($data))->expiresAfter(self::TTL);
        $this->supportChatCache->save($item);
    }

    private function key(\DateTimeInterface $date): string
    {
        return 'ai_usage_'.$date->format('Ymd');
    }

    /** @return array{replies:int, input:int, output:int, failures:int, operator:int} */
    private static function empty(): array
    {
        return ['replies' => 0, 'input' => 0, 'output' => 0, 'failures' => 0, 'operator' => 0];
    }
}
