<?php

namespace App\Telegram\Product\Ring\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class RingCommand extends Command
{
    protected string $command = '/product-ring';
    protected ?string $description = 'Продукт кільця';

    protected string $projectDir;

    public function __construct(string $projectDir, $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
        $this->projectDir = $projectDir;
    }

    public function handle(Nutgram $bot): void
    {
        $file = sprintf(
            '%s/assets/img/5526593977_w640_h640_betonnoe-koltso-dlya.webp',
            $this->projectDir
        );
        if (is_file($file) && is_readable($file)) {
            $photo = fopen($file, 'r+');

            $bot->sendMessage(
                text: 'Кільце',
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('Ціна', null, null, 'type:product:ring:price'),
                )
            );

            /** @var Message $message */
            $message = $bot->sendPhoto(
                photo: InputFile::make($photo)
            );
        }
    }
}