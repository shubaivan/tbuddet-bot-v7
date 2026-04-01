<?php

namespace App\Telegram\Start\Command;

use App\Service\TelegramUserService;
use App\Telegram\BotTranslations as T;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class LanguageToggleCommand
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();

        if (!$user) {
            $bot->answerCallbackQuery(text: 'Error');
            return;
        }

        $current = $user->getPreferredLanguage();
        $new = $current === 'ua' ? 'en' : 'ua';
        $user->setPreferredLanguage($new);
        $this->em->flush();

        $bot->answerCallbackQuery();

        $label = $new === 'ua' ? T::t('lang.changed_ua', $new) : T::t('lang.changed_en', $new);
        $langFlag = $new === 'ua' ? '🇺🇦 UA' : '🇬🇧 EN';

        $bot->sendMessage(
            text: $label,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make(T::t('menu.products', $new), callback_data: 'type:product'),
                    InlineKeyboardButton::make(T::t('menu.my_orders', $new), callback_data: 'type:order'),
                )
                ->addRow(
                    InlineKeyboardButton::make(T::t('menu.route', $new), callback_data: 'type:route'),
                    InlineKeyboardButton::make($langFlag . ' ' . T::t('menu.language', $new), callback_data: 'type:lang:toggle'),
                )
        );
    }
}
