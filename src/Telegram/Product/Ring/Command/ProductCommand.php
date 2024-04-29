<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class ProductCommand extends Command
{
    protected string $command = 'products';
    protected ?string $description = 'Продукія';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Оберіть продукт',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Кільце', callback_data: 'type:product:ring'))
        );
    }
}