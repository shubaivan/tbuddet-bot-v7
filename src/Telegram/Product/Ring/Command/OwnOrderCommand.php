<?php

namespace App\Telegram\Product\Ring\Command;

use App\Service\OrderService;
use App\Service\ProductService;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class OwnOrderCommand extends Command
{
    protected string $command = 'own-order';
    protected ?string $description = 'Продукія';
    private OrderService $service;

    public function __construct(
        TelegramUserService $telegramUserService,
        OrderService $service,
        $callable = null,
        ?string $command = null)
    {
        parent::__construct($callable, $command);
        $this->service = $service;
        $this->telegramUserService = $telegramUserService;
    }

    public function handle(Nutgram $bot): void
    {
        $ownOrders = $this->service->getOwnOrders($this->telegramUserService->getCurrentUser());
        if (!$ownOrders) {
            $bot->sendMessage('Відсутні покупки:');
            return;
        }
        $bot->sendMessage('Ващі покупки:');
        foreach ($ownOrders as $info) {
            $bot->sendMessage(
                text: implode(PHP_EOL, $info),
                parse_mode: ParseMode::HTML,
            );
        }
    }
}