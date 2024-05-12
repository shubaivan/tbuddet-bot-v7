<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Оберіть питання:',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Продукія', callback_data: 'type:product'),
                InlineKeyboardButton::make('Мої покупки', callback_data: 'type:order'),
                InlineKeyboardButton::make('Як доїхати на завод?', callback_data: 'type:route'),
            )
        );
    }
}