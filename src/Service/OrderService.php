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
            $info[$id][] = 'Загальна сума: ' . $order->getTotalAmount() . ' грн';
            $info[$id][] = 'Кількість: ' . $order->getQuantityProduct() . ' одиниць';
            $info[$id][] = 'Статус покупки: ' . ($order->getLiqPaystatus() == 'success' ? 'Оплачено' : $order->getLiqPaystatus());
            $product = $order->getProductId();
            $info[$id][] = 'Назва продукту: ' . $product->getProductName();
            $info[$id][] = 'Ціна продукту за одинцу: ' . $product->getPrice() . ' грн';
            $info[$id][] = 'Опис продукту: ' . PHP_EOL . $product->getProductPropertiesMessage();
        }

        return $info;
    }
}