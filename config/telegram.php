<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Location\Command\LocationCommand;
use App\Telegram\Location\Command\RouteCommand;
use \App\Telegram\Product\Ring\Command\ProductCommand;
use \App\Telegram\Product\Ring\Command\RingCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use \App\Telegram\Start\Command\StartCommand;
use \App\Telegram\Product\Ring\Command\PriceRingConversation;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

$bot->registerCommand(StartCommand::class);
$bot->registerCommand(ProductCommand::class);

$bot->onCommand('route', RouteCommand::class);
$bot->onCommand('product-ring', RingCommand::class);
$bot->onCommand('checkout-ring', PriceRingConversation::class);


$bot->onCallbackQueryData('type:route', RouteCommand::class);
$bot->onCallbackQueryData('type:product', ProductCommand::class);
$bot->onCallbackQueryData('type:product:ring', RingCommand::class);
$bot->onCallbackQueryData('type:product:ring:price', PriceRingConversation::class);

$bot->onLocation(LocationCommand::class);