<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RingCommand extends Command
{
    protected string $command = 'price-ring';
    protected ?string $description = 'Show price ring';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Ціни',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('1.5м 50грн', null, null, 'type:product:ring:buy'),
                InlineKeyboardButton::make('2м 100грн', null, null, 'type:product:ring:buy'),
            )
        );
    }
}