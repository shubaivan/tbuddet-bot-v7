<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Location\Command\LocationCommand;
use App\Telegram\Location\Command\RouteCommand;
use App\Telegram\Product\Ring\Command\RingCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use App\Telegram\Product\Ring\Command\BuyRingCommand;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

$bot->onCommand('start', StartCommand::class);
$bot->onCommand('route', RouteCommand::class);
$bot->onCommand('price-ring', RingCommand::class);

$bot->onCallbackQueryData('type:a', RouteCommand::class);
$bot->onCallbackQueryData('type:product:price:ring', RingCommand::class);
$bot->onCallbackQueryData('type:product:ring:buy', BuyRingCommand::class);

$bot->onLocation(LocationCommand::class);