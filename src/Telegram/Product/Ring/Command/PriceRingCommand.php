<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class PriceRingCommand extends Command
{
    protected string $command = 'price-ring';
    protected ?string $description = 'Ціни';

    public function handle(Nutgram $bot): void
    {
        PriceRingConversation::begin($bot);
    }
}