<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Відправка повідомлень у Telegram-групу менеджерів («Заявки ArtBeton»).
 * Використовує токен адмін-бота @artbeton_admin_bot (TELEGRAM_ADMIN_TOKEN) —
 * він і так у групі як адміністратор і не сидить на webhook.
 */
final class TelegramNotifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $managerBotToken,
        private readonly string $managerChatId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->managerBotToken && '' !== $this->managerChatId;
    }

    /**
     * @param string $htmlText готовий HTML (parse_mode=HTML). Динамічні дані ескейпити через self::esc()
     */
    public function send(string $htmlText): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Manager notifier not configured (missing token or chat id)');

            return false;
        }

        try {
            $response = $this->httpClient->request('POST', \sprintf('https://api.telegram.org/bot%s/sendMessage', $this->managerBotToken), [
                'json' => [
                    'chat_id' => $this->managerChatId,
                    'text' => $htmlText,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 10,
            ]);

            $ok = 200 === $response->getStatusCode();
            if (!$ok) {
                $this->logger->error('Manager notifier sendMessage failed', ['status' => $response->getStatusCode(), 'body' => $response->getContent(false)]);
            }

            return $ok;
        } catch (\Throwable $e) {
            $this->logger->error('Manager notifier sendMessage exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
