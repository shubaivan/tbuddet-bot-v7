<?php

namespace App\Telegram\Product\Ring\Command;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Service\LocalizationService;
use App\Service\ProductService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class PriceRingConversation extends Conversation
{
    protected ?string $step = 'askParameters';

    public ?int $productId;
    public ?int $categoryId;
    public ?int $quantity;
    public ?bool $confirmPhone = false;

    public function __construct(
        private LocalizationService $localizationService,
        private FilesystemOperator $defaultStorage,
        private TelegramUserService $telegramUserService,
        private ProductService $productService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
        private string $projectDir
    )
    {
        $this->confirmPhone = false;
    }

    public function askParameters(Nutgram $bot)
    {
        $bot->sendMessage(
            text: 'Оберіть категорію:',
        );

        foreach ($this->productService->getCategories() as $categorySet) {
            foreach ($categorySet->getCategory()->getFiles() as $file) {
                $url = $this->defaultStorage->readStream($file->getPath());

                /** @var Message $message */
                $message = $bot->sendPhoto(
                    photo: InputFile::make($url)
                );
            }

            $bot->sendMessage(
                text: sprintf('
Категорія продуктів: %s, кількість продуктів %s',
                    $categorySet->getCategory()->getCategoryName($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                    $categorySet->getTotalProduct(),
                ),
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(
                        InlineKeyboardButton::make(
                            'Обрати категорію', callback_data: 'category_' . $categorySet->getCategory()->getId()
                        ),
                    )
            );
        }

        $this->next('askCategory');
    }

    public function askCategory(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_contains($bot->callbackQuery()->data, 'category_')) {
            $this->askParameters($bot);

            return;
        }
        $this->categoryId = str_replace('category_', '', $bot->callbackQuery()->data);

        foreach ($this->productService->getProductsForBot($this->categoryId) as $group => $products) {
            if ($products) {
                $bot->sendMessage(
                    sprintf('<b>Группа продуктів: %s</b>',
                        $group
                    ),
                    parse_mode: ParseMode::HTML
                );
            }

            foreach ($products as $product) {
                foreach ($product->getFiles() as $file) {
                    $url = $this->defaultStorage->readStream($file->getPath());

                    /** @var Message $message */
                    $message = $bot->sendPhoto(
                        photo: InputFile::make($url)
                    );
                }

                $bot->sendMessage(
                    text: sprintf('
Продукт: %s, ціна: %s грн;%s
%s
                        ',
                        $product->getProductName($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                        $product->getPrice($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                        PHP_EOL,
                        $product->getProductPropertiesMessage($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode()))
                    ),
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make()
                        ->addRow(
                            InlineKeyboardButton::make(
                                'Обрати', callback_data: 'product_' . $product->getId()
                            ),
                        )
                );
            }
        }

        $this->next('askProduct');
    }

    public function askProduct(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_contains($bot->callbackQuery()->data, 'product_')) {
            $this->askParameters($bot);

            return;
        }
        $this->productId = str_replace('product_', '', $bot->callbackQuery()->data);

        $product = $this->productService->getProduct($this->productId);
        $bot->sendMessage(
            text: sprintf('
<b>Ви ОБРАЛИ</b>            
Продукт: %s, ціна: %s грн;%s
%s 
                        ',
                $product->getProductName($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                $product->getPrice($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                PHP_EOL,
                $product->getProductPropertiesMessage($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode()))
            ),
            parse_mode: ParseMode::HTML,
        );

        $bot->sendMessage('Введіть кількість');
        $this->next('quantity');
    }

    public function quantity(Nutgram $bot)
    {
        $text = $bot->message()->text;
        if ($bot->isCallbackQuery()) {
            $bot->sendMessage('По черзі: продукт - кількість');
            $this->askProduct($bot);

            return;
        }

        if (!preg_match_all('/^[0-9]*$/', $text)) {
            $bot->sendMessage('Тількт цирфи');
            $this->askProduct($bot);

            return;
        }

        $this->quantity = (int)$text;

        $product = $this->productService->getProduct($this->productId);
        $bot->sendMessage(
            sprintf('<b>Ваше замовлення</b>: <strong>%s</strong>: в <b>кількості</b>: <u>%s одиниць</u>',
                $product->getProductName($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
                $this->quantity
            ),
            parse_mode: ParseMode::HTML
        );

        $totalAmount = $product->getPrice($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())) * $this->quantity;
        $bot->sendMessage(
            '<b>Кінцева ціна</b>: ' . $totalAmount . ' грн',
            parse_mode: ParseMode::HTML
        );

        if (!$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $this->confirmPhone = true;
            $bot->sendMessage(
                text: 'Ваш Номер',
                reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                    KeyboardButton::make('Підтвердіть ВАШ телефон', true),
                )
            );
        } else {
            $bot->sendMessage(
                text: 'Оформлення покупки',
                parse_mode: ParseMode::MARKDOWN,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(text: 'Далі', callback_data: 1),
                    InlineKeyboardButton::make(text: 'Назад', callback_data: 0),
                )
            );
        }

        $this->next('approveAction');
    }

    public function approveAction(Nutgram $bot)
    {
        if ($bot->isCallbackQuery() && $bot->callbackQuery()->data !== "1") {
            $this->askParameters($bot);

            return;
        }

        if ($this->confirmPhone
            && !$this->telegramUserService->getCurrentUser()->getPhoneNumber()
            && $bot->message()
        ) {
            if ($bot->message()->contact && $bot->message()->contact->phone_number) {
                $phone_number = $bot->message()->contact->phone_number;
                $bot->sendMessage(
                    text: 'Removing keyboard...',
                    reply_markup: ReplyKeyboardRemove::make(true),
                )?->delete();
            } else {
                $this->confirmPhone = true;
                $bot->sendMessage(
                    text: 'Подтрібно натиснути',
                    reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                        KeyboardButton::make('Підтвердіть ВАШ телефон', true),
                    )
                );
                $file = sprintf(
                    '%s/assets/img/share_contact.jpeg',
                    $this->projectDir
                );
                if (is_file($file) && is_readable($file)) {
                    $photo = fopen($file, 'r+');

                    /** @var Message $message */
                    $message = $bot->sendPhoto(
                        photo: InputFile::make($photo)
                    );
                }
                $this->next('approveAction');

                return;
            }
            $this->confirmPhone = false;
            $this->telegramUserService->savePhone($phone_number);

            $this->em->flush();
        }

        $bot->sendMessage(
            text: 'Якщо згодні натисніть *Підтверджую*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                InlineKeyboardButton::make(text: 'Назад', callback_data: 0),
            )
        );

        $this->next('liqPay');
    }

    public function liqPay(Nutgram $bot)
    {
        if ($bot->callbackQuery()->data !== "1") {
            $this->askParameters($bot);

            return;
        }

        $userOrder = new UserOrder();
        $product = $this->productService->getProduct($this->productId);
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode()), $this->quantity);
        $userOrder->setTelegramUserid($this->telegramUserService->getCurrentUser());

        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName($this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode())),
            $this->quantity
        );
        $userOrder->setDescription($description);

        $this->em->persist($userOrder);
        $this->em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $userOrder->getTelegramUserid()->getPhoneNumber(),
            'amount' => $userOrder->getTotalAmount(),
            'currency' => $this->localizationService->getLanguage($this->telegramUserService->getCurrentUser()->getLanguageCode()) === UserLanguageEnum::UA ? 'UAH' : 'USD',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $this->em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $cnb_form_raw = $liqpay->cnb_form_raw($params);
        $link = sprintf(
            '%s?%s&%s',
            $cnb_form_raw['url'],
            'data=' . $cnb_form_raw['data'],
            'signature=' . $cnb_form_raw['signature'],
        );
        $bot->sendMessage(
            text: 'Перевірте оповіщення в телефоні або перейдіть на посилання: <a href="' . $link . '">URL</a>',
            parse_mode: ParseMode::HTML
        );

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, чекайте повідомлення як буде готове <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }
}