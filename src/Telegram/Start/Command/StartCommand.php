<?php

namespace App\Telegram\Start\Command;

use App\Service\TelegramLinkService;
use App\Service\TelegramUserService;
use App\Telegram\BotTranslations as T;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private TelegramLinkService $telegramLinkService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Nutgram $bot, ?string $payload = null): void
    {
        $chatId = $bot->message()?->chat?->id ?? $bot->chatId();

        try {
            $lang = $this->telegramUserService->getCurrentUser()?->getPreferredLanguage() ?? 'ua';

            // Account-link deep link: t.me/<bot>?start=link_<token>
            // Note: Nutgram's onCommand pattern only injects $payload when the command is registered
            // as `start {payload}`. Plain `onCommand('start', ...)` matches `/start` only and will
            // call __invoke with $payload = null.
            if ($payload !== null && str_starts_with($payload, TelegramLinkService::PAYLOAD_PREFIX) && $chatId !== null) {
                $linkedUser = $this->telegramLinkService->consumeLinkToken($payload, (int) $chatId);
                $text = $linkedUser !== null
                    ? ($lang === 'ua'
                        ? sprintf("✅ Готово! Цей чат прив'язано до акаунту <b>%s</b>.\nВи будете отримувати оновлення замовлень тут.", htmlspecialchars($linkedUser->getEmail()))
                        : sprintf("✅ Linked! This chat is now connected to <b>%s</b>.\nYou'll receive order updates here.", htmlspecialchars($linkedUser->getEmail())))
                    : ($lang === 'ua'
                        ? "❌ Посилання застаріло або недійсне. Запитайте нове в особистому кабінеті."
                        : '❌ Link expired or invalid. Generate a new one from your account page.');
                $bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
                return;
            }

            $langFlag = $lang === 'ua' ? '🇺🇦 UA' : '🇬🇧 EN';

            $bot->sendMessage(
                text: T::t('menu.choose', $lang),
                chat_id: $chatId,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(
                        InlineKeyboardButton::make(T::t('menu.products', $lang), callback_data: 'type:product'),
                        InlineKeyboardButton::make(T::t('menu.my_orders', $lang), callback_data: 'type:order'),
                    )
                    ->addRow(
                        InlineKeyboardButton::make(T::t('menu.route', $lang), callback_data: 'type:route'),
                        InlineKeyboardButton::make($langFlag . ' ' . T::t('menu.language', $lang), callback_data: 'type:lang:toggle'),
                    )
            );
        } catch (\Throwable $e) {
            $this->logger->error('StartCommand failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
            try {
                if ($chatId !== null) {
                    $bot->sendMessage(text: '⚠️ Помилка обробки команди. Спробуйте ще раз або напишіть менеджеру.', chat_id: $chatId);
                }
            } catch (\Throwable) {}
        }
    }
}
