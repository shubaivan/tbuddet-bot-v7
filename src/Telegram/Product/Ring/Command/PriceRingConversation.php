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

    public ?int $productId;
    public ?int $quantity;

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

        $this->productId = $bot->callbackQuery()->data;

        $bot->sendMessage('Введіть кількість');
        $this->next('quantity');
    }

    public function quantity(Nutgram $bot)
    {
        $this->quantity = (int)$bot->message()->text;

        $bot->sendMessage(
            '<b>Ваше замовлення</b>: <strong>кільця</strong>: <u>'.$this->getProduct()->getProductName().'</u> діаметром, в <b>кількості</b>: <u>'.$this->quantity.' штук</u>',
            parse_mode: ParseMode::HTML
        );

        $totalAmount = $this->getProduct()->getPrice() * $this->quantity;
        $bot->sendMessage(
            '<b>Кінцева ціна</b>: '. $totalAmount . ' грн',
            parse_mode: ParseMode::HTML
        );

        if (!$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $bot->sendMessage(
                text: 'Ваш Номер',
                reply_markup: ReplyKeyboardMarkup::make()->addRow(
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

        if ($bot->message() && !$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $phone_number = $bot->message()->contact->phone_number;
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
        $userOrder->setProductId($this->getProduct());
        $userOrder->setQuantityProduct($this->quantity);
        $userOrder->setTelegramUserId($this->telegramUserService->getCurrentUser());
        $userOrder->setTotalAmount($this->getProduct()->getPrice() * $this->quantity);
        $description = 'Ваше замовлення: кільця: ' . $this->getProduct()->getProductName() . ' діаметром, в кількості: ' . $this->quantity . ' штук';
        $userOrder->setDescription($description);

        $this->em->persist($userOrder);
        $this->em->flush();

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());
        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $userOrder->getTelegramUserId()->getPhoneNumber(),
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayResponse(json_encode($res));
        $userOrder->setLiqPayOrderId($liqPayOrderID);
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
            'data='.$cnb_form_raw['data'],
            'signature='.$cnb_form_raw['signature'],
        );
        $bot->sendMessage(
            text: 'Перевірте оповіщення в телефоні або перейдіть на посилання: <a href="'.$link.'">URL</a>',
            parse_mode: ParseMode::HTML
        );

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, чекайте повідомлення як буде готове <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }

    private function getProduct(): Product
    {
        return $this->productRepository->find($this->productId);
    }
}