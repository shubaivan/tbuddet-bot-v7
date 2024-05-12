<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class OrderCommand extends Command
{
    protected string $command = 'order';
    protected ?string $description = 'Покупки';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: '*Переглянути мої покупки*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: 'Вперед', callback_data: 'type:order'),
            )
        );
    }
}