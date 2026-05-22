<?php

namespace App\Telegram\Start\Command;

use App\Service\Promocode\FirstOrderPromocodeService;
use App\Service\TelegramLinkService;
use App\Service\TelegramUserService;
use App\Telegram\BotTranslations as T;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class StartCommand
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private TelegramLinkService $telegramLinkService,
        private FirstOrderPromocodeService $firstOrderPromocodeService,
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

            $this->sendFirstOrderOffer($bot, $chatId, $lang);
            $this->promptForPhone($bot, $chatId, $lang);
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

    /**
     * Show the buyer their rolling personal "first order" promocode under the menu.
     * Skipped silently for buyers who already converted (a paid order) — and any
     * failure here is swallowed so it can never break the /start menu itself.
     */
    private function sendFirstOrderOffer(Nutgram $bot, ?int $chatId, string $lang): void
    {
        if ($chatId === null) {
            return;
        }

        try {
            $tgUser = $this->telegramUserService->getCurrentUser();
            if ($tgUser === null) {
                return;
            }

            $offer = $this->firstOrderPromocodeService->getActiveOfferForTelegramUser($tgUser);
            if ($offer === null) {
                return;
            }

            $bot->sendMessage(
                text: sprintf(
                    T::t('promocode.first_order_offer', $lang),
                    $offer->getCode(),
                    $offer->getValue(),
                    $offer->getValidTo()?->format('d.m.Y') ?? '',
                ),
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->error('First-order offer in /start failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * Ask the buyer to share their phone number once — Telegram only hands over
     * the phone via a request_contact button (a plain /start never carries it),
     * so without this step a bot user is stored with no phone. Skipped once we
     * already have it; the contact reply is saved by {@see SaveContactCommand}.
     */
    private function promptForPhone(Nutgram $bot, ?int $chatId, string $lang): void
    {
        if ($chatId === null) {
            return;
        }

        try {
            $tgUser = $this->telegramUserService->getCurrentUser();
            if ($tgUser === null || $tgUser->getPhoneNumber()) {
                return;
            }

            $bot->sendMessage(
                text: $lang === 'ua'
                    ? "📱 Поділіться номером телефону, щоб ми могли зв'язатися з вами щодо замовлень."
                    : '📱 Share your phone number so we can reach you about your orders.',
                chat_id: $chatId,
                reply_markup: ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
                    ->addRow(
                        KeyboardButton::make(
                            $lang === 'ua' ? '📱 Поділитися номером' : '📱 Share phone number',
                            true,
                        ),
                    ),
            );
        } catch (\Throwable $e) {
            $this->logger->error('promptForPhone in /start failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}
