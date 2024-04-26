<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PriceRingConversation extends Conversation
{
    protected ?string $step = 'askParameters';

    public $askDiameter;

    public function askParameters(Nutgram $bot)
    {
        $bot->sendMessage(
            text: 'Який діаметр?',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('1м', callback_data: '1м'), InlineKeyboardButton::make('1.5м', callback_data: '1.5м'))
                ->addRow(InlineKeyboardButton::make('2м', callback_data: '2м'), InlineKeyboardButton::make('3м', callback_data: '3м')),
        );
        $this->next('askDiameter');
    }

    public function askDiameter(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->askCupSize($bot);
            return;
        }

        $this->askDiameter = $bot->callbackQuery()->data;

        $bot->sendMessage('Введіть кількість');
        $this->next('quantity');
    }

    public function quantity(Nutgram $bot)
    {
        $quantity = $bot->message()->text;
        $bot->sendMessage(
            '<b>Ваше замовлення</b>: <strong>кільця</strong>: <u>'.$this->askDiameter.'</u> діаметром, в <b>кількості</b>: <u>'.$quantity.'штук</u>',
            parse_mode: ParseMode::HTML
        );

        $bot->sendMessage(
            text: 'Якщо згодні натисніть *Підтверджую*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Підтверджую', null, null, 'type:product:ring:buy'),
            )
        );

        $this->end();
    }
}