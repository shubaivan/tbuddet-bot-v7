<?php

namespace App\Telegram\Product\Ring\Command;

use App\Liqpay\LiqPay;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class BuyRingCommand extends Command
{
    protected string $command = 'ring-buy';
    protected ?string $description = 'Ring buy';
    protected string $liqpayPublicKey;
    protected string $liqpayPrivateKey;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, string $liqpayPublicKey, string $liqpayPrivateKey, $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
        $this->liqpayPrivateKey = $liqpayPrivateKey;
        $this->liqpayPublicKey = $liqpayPublicKey;
        $this->logger = $logger;
    }

    public function handle(Nutgram $bot): void
    {
        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);
        $res = $liqpay->api("request", array(
            'action'    => 'invoice_send',
            'version'   => '3',
            'email'     => 'shuba.ivan.vikt@gmail.com',
            'amount'    => '2',
            'currency'  => 'UAH',
            'order_id'  => 'order_id_2',
            'server_url' => 'https://shuba-chalova-26-2.tplinkdns.com/liq/pay'
        ));

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, чекайте повідомлення як буде готове <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );
    }
}