<?php

namespace App\Telegram\Start\Command;

use App\Service\TelegramUserService;
use App\Telegram\BotTranslations as T;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public function __construct(
        private TelegramUserService $telegramUserService,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $lang = $this->telegramUserService->getCurrentUser()?->getPreferredLanguage() ?? 'ua';
        $langFlag = $lang === 'ua' ? '🇺🇦 UA' : '🇬🇧 EN';

        $bot->sendMessage(
            text: T::t('menu.choose', $lang),
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
    }
}
