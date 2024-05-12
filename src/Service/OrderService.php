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
            $info[$id][] = '<b>Номер ордеру</b>: ' . $order->getLiqOrderId();
            $info[$id][] = '<b>Загальна сума</b>: ' . $order->getTotalAmount() . ' грн';
            $info[$id][] = '<b>Кількість</b>: ' . $order->getQuantityProduct() . ' одиниць';
            $info[$id][] = '<b>Статус покупки</b>: ' . ($order->getLiqPaystatus() == 'success' ? 'Оплачено' : $order->getLiqPaystatus());
            $product = $order->getProductId();
            $info[$id][] = '<b>Назва продукту</b>: ' . $product->getProductName();
            $info[$id][] = '<b>Ціна продукту за одинцу</b>: ' . $product->getPrice() . ' грн';
            $info[$id][] = PHP_EOL;
            $info[$id][] = '<b>Опис продукту</b>: ' . PHP_EOL . $product->getProductPropertiesMessage();
        }

        return $info;
    }
}