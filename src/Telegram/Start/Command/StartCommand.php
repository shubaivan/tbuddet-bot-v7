<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'A lovely start command';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage('Вітаю!');
        $bot->sendMessage('Оберіть питання..', [
            'reply_markup' => InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Як доїхати на Завод БудДеталь?', null, null, 'type:a'),
            )
        ]);
    }
}