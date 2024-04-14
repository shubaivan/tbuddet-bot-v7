<?php

namespace App\Telegram\Location\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class RouteCommand extends Command
{
    // Called on command "/start"
    protected string $command = 'route';

    // It's possible to set a description for the current command
    // this WILL be automatically registered
    protected ?string $description = 'Show route';

    public function handle(Nutgram $bot): void
    {
        $str = "Будьласка, відправте Ваше місцезнаходження:\n• Натисніть \xF0\x9F\x93\x8E\n• Виберіть \"Location\"\n• Натисніть \"Send my current location\"";
        $bot->sendMessage($str);
    }
}