<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Відправка повідомлень у Telegram-групу менеджерів.
 *
 * Група — «АртБетон • Робоча» з темами: події магазину йдуть у тему «Магазин»,
 * погодинний дайджест — в «Аналітика», усе про ІІ-консультанта (збої, бюджет,
 * запити оператора з чату) — у «Консультант». Тема не задана — повідомлення падає в
 * General, як було до тем.
 *
 * Пише бот заявок (@artbeton_zayavky_bot, TELEGRAM_MANAGER_BOT_TOKEN): він уже
 * адмін групи, і вся група говорить одним голосом. Порожній — запасний адмін-бот
 * @artbeton_admin_bot (TELEGRAM_ADMIN_TOKEN), як було для старої групи.
 */
final class TelegramNotifier
{
    public const TOPIC_SHOP = 'shop';
    public const TOPIC_ANALYTICS = 'analytics';
    public const TOPIC_CONSULTANT = 'consultant';

    private readonly string $managerBotToken;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $managerBotToken,
        private readonly string $managerChatId,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] string $groupBotToken = '',
        private readonly string $shopTopicId = '',
        private readonly string $analyticsTopicId = '',
        private readonly string $consultantTopicId = '',
    ) {
        $this->managerBotToken = '' !== $groupBotToken ? $groupBotToken : $managerBotToken;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->managerBotToken && '' !== $this->managerChatId;
    }

    /**
     * @param string $htmlText готовий HTML (parse_mode=HTML). Динамічні дані ескейпити через self::esc()
     * @param string $topic    self::TOPIC_SHOP, self::TOPIC_ANALYTICS або self::TOPIC_CONSULTANT
     */
    public function send(string $htmlText, string $topic = self::TOPIC_SHOP): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Manager notifier not configured (missing token or chat id)');

            return false;
        }

        try {
            $response = $this->httpClient->request('POST', \sprintf('https://api.telegram.org/bot%s/sendMessage', $this->managerBotToken), [
                'json' => array_filter([
                    'chat_id' => $this->managerChatId,
                    'message_thread_id' => $this->topicId($topic),
                    'text' => $htmlText,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ], static fn ($v) => null !== $v),
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

    /** null — General: Telegram відкидає повідомлення з неіснуючою темою, тож сміття не передаємо. */
    private function topicId(string $topic): ?int
    {
        $id = match ($topic) {
            self::TOPIC_ANALYTICS => $this->analyticsTopicId,
            self::TOPIC_CONSULTANT => $this->consultantTopicId,
            default => $this->shopTopicId,
        };

        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
