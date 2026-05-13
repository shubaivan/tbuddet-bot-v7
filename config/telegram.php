<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Location\Command\LocationCommand;
use App\Telegram\Location\Command\RouteCommand;
use App\Telegram\Start\Command\LanguageToggleCommand;
use \App\Telegram\Product\Ring\Command\ProductCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use \App\Telegram\Start\Command\StartCommand;
use \App\Telegram\Product\Ring\Command\PriceRingConversation;
use \App\Telegram\Product\Ring\Command\OwnOrderCommand;
use \App\Telegram\Product\Ring\Command\OrderCommand;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

// Plain `/start` (menu) and `/start <payload>` (deep-link) need separate registrations:
// Nutgram anchors the regex with ^...$ so the no-arg form does NOT match the param form.
$bot->onCommand('start', StartCommand::class);
$bot->onCommand('start {payload}', StartCommand::class);
$bot->registerCommand(ProductCommand::class);
$bot->registerCommand(OrderCommand::class);

$bot->onCommand('route', RouteCommand::class);
$bot->onCommand('checkout-ring', PriceRingConversation::class);

$bot->onCallbackQueryData('type:lang:toggle', LanguageToggleCommand::class);
$bot->onCallbackQueryData('type:order', OwnOrderCommand::class);
$bot->onCallbackQueryData('type:route', RouteCommand::class);
$bot->onCallbackQueryData('type:product', ProductCommand::class);
$bot->onCallbackQueryData('type:product:ring:price', PriceRingConversation::class);

// Catch-all for callbacks that no other handler/conversation picked up —
// e.g. user clicks a stale button after a deploy wiped conversation state.
// Dismiss the Telegram spinner and nudge them to restart, instead of the
// click silently hanging until the 30s callback timeout.
$bot->onCallbackQuery(function (SergiX44\Nutgram\Nutgram $bot): void {
    try {
        $bot->answerCallbackQuery(
            text: 'Сесія застаріла. Надішліть /start, щоб почати заново.',
            show_alert: false,
        );
    } catch (\Throwable $e) {}
});

$bot->onLocation(LocationCommand::class);