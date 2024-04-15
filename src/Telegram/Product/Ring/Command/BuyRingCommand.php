<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BuyRingCommand extends Command
{
    protected string $command = 'ring-buy';
    protected ?string $description = 'Ring buy';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: "*Вітаю, чекайте повідомлення як буде готове*",
            parse_mode: ParseMode::MARKDOWN
        );
    }
}