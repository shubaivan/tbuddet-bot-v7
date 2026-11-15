<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Service\Analytics\TelegramNotifier;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Health-check ІІ-консультанта: найдешевший виклик Anthropic (max_tokens 1)
 * кожні 15 хв + тривога в тему «Консультант» лише на ЗМІНІ стану
 * (працює → не працює і назад).
 *
 * Стан живе в тому ж пулі, що й бюджет консультанта (var/support-chat-cache,
 * поза var/cache — деплой його не скидає). Цей самий стан оновлює й
 * ConsultantAlert, коли реальний чат отримує «баланс»/«ключ», — тож про один
 * і той самий збій група дізнається один раз, а «знову працює» пише лише цей клас.
 */
final class ConsultantHealth
{
    private const STATE_KEY = 'consultant_health_state';

    public function __construct(
        #[\SensitiveParameter] private readonly string $anthropicApiKey,
        private readonly string $supportChatModel,
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly TelegramNotifier $notifier,
        private readonly string $alertMention = '',
    ) {
    }

    /** Один виклик Anthropic; повертає ok | balance | key | other. */
    public function probe(): string
    {
        if ('' === trim($this->anthropicApiKey)) {
            return ConsultantHealthState::KEY;
        }

        try {
            (new Client(apiKey: $this->anthropicApiKey))->messages->create(
                maxTokens: 1,
                messages: [['role' => 'user', 'content' => 'ok']],
                model: $this->supportChatModel,
                requestOptions: RequestOptions::with(timeout: 20.0, maxRetries: 1),
            );

            return ConsultantHealthState::OK;
        } catch (\Throwable $e) {
            return ConsultantHealthState::classify($e);
        }
    }

    /** @return array{state:string, pending:int}|null */
    public function current(): ?array
    {
        $item = $this->supportChatCache->getItem(self::STATE_KEY);
        $value = $item->isHit() ? $item->get() : null;

        return \is_array($value) && isset($value['state'], $value['pending']) ? $value : null;
    }

    /**
     * Застосовує спостереження: зберігає новий стан і, якщо це перехід, пише в групу.
     * Якщо Telegram не прийняв повідомлення — стан не зберігається, повтор на наступному запуску.
     *
     * @return array{previous:?string, state:string, notified:?string, sent:bool}
     */
    public function observe(string $observed, bool $send = true): array
    {
        $current = $this->current();
        $next = ConsultantHealthState::next($current, $observed);
        $notify = $next['notify'];

        $sent = false;
        if (null !== $notify && $send) {
            $sent = $this->notifier->send($this->withMention($notify, ConsultantHealthState::message($notify)), TelegramNotifier::TOPIC_CONSULTANT);
            if (!$sent) {
                return ['previous' => $current['state'] ?? null, 'state' => $current['state'] ?? $observed, 'notified' => $notify, 'sent' => false];
            }
        }

        $this->save($next['state']);

        return ['previous' => $current['state'] ?? null, 'state' => $next['state']['state'], 'notified' => $notify, 'sent' => $sent];
    }

    /** Група вже знає про збій (стан не ok) — ConsultantAlert не дублює. */
    public function isFailing(): bool
    {
        $current = $this->current();

        return null !== $current && ConsultantHealthState::OK !== $current['state'];
    }

    /** @param array{state:string, pending:int} $state */
    private function save(array $state): void
    {
        $item = $this->supportChatCache->getItem(self::STATE_KEY);
        $item->set($state);
        $this->supportChatCache->save($item);
    }

    private function withMention(string $kind, string $text): string
    {
        // Тегаємо платника лише на збої; відновлення — без тегу.
        if (ConsultantHealthState::OK !== $kind && '' !== trim($this->alertMention)) {
            $text .= "\n".TelegramNotifier::esc(trim($this->alertMention));
        }

        return $text;
    }
}
