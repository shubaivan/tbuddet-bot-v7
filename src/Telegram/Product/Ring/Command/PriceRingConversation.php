<?php

namespace App\Telegram\Product\Ring\Command;

use App\Entity\Product;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Repository\ProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class PriceRingConversation extends Conversation
{
    protected ?string $step = 'askParameters';

    private Product $product;
    private UserOrder $userOrder;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl
    ) {}


    public function askParameters(Nutgram $bot)
    {
        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();

        foreach ($this->productRepository->getAllByProductInternalName('Ring') as $ring) {
            $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(sprintf('%s, %s грн', $ring->getProductName(), $ring->getPrice()), callback_data: $ring->getId()));
        }

        $bot->sendMessage(
            text: 'Який діаметр?',
            reply_markup: $inlineKeyboardMarkup,
        );
        $this->next('askDiameter');
    }

    public function askDiameter(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->askParameters($bot);
            return;
        }

        $this->product = $this->productRepository->find($bot->callbackQuery()->data);
        $this->userOrder = new UserOrder();
        $this->userOrder->setProductId($this->product);

        $bot->sendMessage('Введіть кількість');
        $this->next('quantity');
    }

    public function quantity(Nutgram $bot)
    {
        $quantity = $bot->message()->text;
        $this->userOrder->setQuantityProduct($quantity);

        $bot->sendMessage(
            '<b>Ваше замовлення</b>: <strong>кільця</strong>: <u>'.$this->product->getProductName().'</u> діаметром, в <b>кількості</b>: <u>'.$quantity.' штук</u>',
            parse_mode: ParseMode::HTML
        );

        $bot->sendMessage(
            '<b>Кінцева ціна</b>: <strong>'.$this->userOrder->getTotalAmount().'</strong>',
            parse_mode: ParseMode::HTML
        );

        $bot->sendMessage(
            text: 'Ваш Номер',
            reply_markup: ReplyKeyboardMarkup::make()->addRow(
                KeyboardButton::make('Підтвердіть ВАШ телефон', true),
            )
        );

        $this->next('approveAction');
    }

    public function approveAction(Nutgram $bot)
    {
        $phone_number = $bot->message()->contact->phone_number;
        $this->telegramUserService->savePhone($phone_number);
        $this->userOrder->setTelegramUserId($this->telegramUserService->getCurrentUser());

        $this->em->persist($this->userOrder);
        $this->em->flush();

        $bot->sendMessage(
            text: 'Якщо згодні натисніть *Підтверджую*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Підтверджую'),
            )
        );

        $this->next('liqPay');
    }

    public function liqPay(Nutgram $bot)
    {
        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);
        $res = $liqpay->api("request", array(
            'action'    => 'invoice_send',
            'version'   => '3',
            'phone' => $this->userOrder->getTelegramUserId()->getPhoneNumber(),
            'amount'    => $this->userOrder->getTotalAmount(),
            'currency'  => 'UAH',
            'order_id'  => $this->userOrder->getId(),
            'server_url' => $this->liqpayServerUrl
        ));

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, чекайте повідомлення як буде готове <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }
}