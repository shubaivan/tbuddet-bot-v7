<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Location\Command\LocationCommand;
use App\Telegram\Location\Command\RouteCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;

Conversation::refreshOnDeserialize();
$bot->setRunningMode(Webhook::class);

$bot->registerCommand(StartCommand::class);
$bot->registerCommand(RouteCommand::class);

$bot->onCallbackQueryData('type:a', RouteCommand::class);

$bot->onLocation(LocationCommand::class);