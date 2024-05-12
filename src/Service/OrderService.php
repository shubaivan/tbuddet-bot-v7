<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Repository\UserOrderRepository;

class OrderService
{
    public function __construct(private UserOrderRepository $repository) {}

    public function getOwnOrders(TelegramUser $user): array
    {
        $info = [];
        foreach ($this->repository->getOwnOrders($user) as $order) {
            $id = $order->getId();
            $info[$id][] = '<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>';
            $info[$id][] = '<b>Номер ордеру</b>: ' . $order->getLiqOrderId();
            $info[$id][] = '<b>Загальна сума</b>: ' . $order->getTotalAmount() . ' грн';
            $info[$id][] = '<b>Кількість</b>: ' . $order->getQuantityProduct() . ' одиниць';
            $status = $order->getLiqPaystatus();
            if (is_null($order->getLiqPaystatus())) {
                $status = 'Чекає оплати';
            }
            if ($order->getLiqPaystatus() == 'success') {
                $status = 'Оплачено';
            }

            $info[$id][] = '<b><i><u>Статус покупки</u></i></b>: <b><i>' . $status . '</i></b>';
            $product = $order->getProductId();
            $info[$id][] = '<b>Назва продукту</b>: ' . $product->getProductName();
            $info[$id][] = '<b>Ціна продукту за одинцу</b>: ' . $product->getPrice() . ' грн';
            $info[$id][] = PHP_EOL;
            $info[$id][] = '<b>Опис продукту</b>: ' . PHP_EOL . $product->getProductPropertiesMessage();
        }

        return $info;
    }
}