<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'A lovely start command';
    protected string $projectDir;

    public function __construct(string $projectDir, $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
        $this->projectDir = $projectDir;
    }

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage('Вітаю!');
        $bot->sendMessage(
            text: 'Оберіть питання..',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Як доїхати на Завод БудДеталь?', null, null, 'type:a'),
            )
        );

        $file = sprintf(
            '%s/assets/img/5526593977_w640_h640_betonnoe-koltso-dlya.webp',
            $this->projectDir
        );
        if (is_file($file) && is_readable($file)) {
            $photo = fopen($file, 'r+');

            $bot->sendMessage(
                text: 'Кільце',
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('Ціна', null, null, 'type:product:price:ring'),
                )
            );

            /** @var Message $message */
            $message = $bot->sendPhoto(
                photo: InputFile::make($photo)
            );
        }
//        $fileId = $bot->message()->sticker->file_id;
//
//        /** @var Message $message */
//        $message = $bot->sendSticker(
//            sticker: $fileId
//        );
    }
}