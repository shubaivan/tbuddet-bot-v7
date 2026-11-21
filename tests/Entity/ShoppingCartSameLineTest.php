<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\PurchaseProduct;
use App\Entity\ShoppingCart;
use App\Entity\UserOrder;
use PHPUnit\Framework\TestCase;

class ShoppingCartSameLineTest extends TestCase
{
    private function product(int $id): Product
    {
        $p = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($p, $id);

        return $p;
    }

    private static function opts(string $color, string $size = '700'): array
    {
        return [
            ['property_name' => 'Колір', 'property_value' => $color, 'property_price_impact' => 0],
            ['property_name' => 'Розмір', 'property_value' => $size, 'property_price_impact' => 0],
        ];
    }

    private function line(ShoppingCart $cart, Product $product, array $opts, ?UserOrder $order = null): PurchaseProduct
    {
        $l = (new PurchaseProduct())->setProduct($product)->setProductProperties($opts)->setQuantity(1);
        $l->setUserOrder($order);
        $cart->addPurchaseProduct($l);

        return $l;
    }

    public function testFindsTheSameProductWithTheSameOptionsInAnyOrder(): void
    {
        $cart = new ShoppingCart();
        $cube = $this->product(178);
        $natural = $this->line($cart, $cube, self::opts('Натуральний'));

        self::assertSame($natural, $cart->findSameUnpurchasedLine($cube, array_reverse(self::opts('Натуральний'))));
        self::assertSame($natural, $cart->findSameUnpurchasedLine($this->product(178), self::opts('Натуральний')));
    }

    public function testOtherOptionsOrProductsOrOrderedLinesAreSeparate(): void
    {
        $cart = new ShoppingCart();
        $cube = $this->product(178);
        $this->line($cart, $cube, self::opts('Натуральний'), new UserOrder()); // already ordered

        self::assertNull($cart->findSameUnpurchasedLine($cube, self::opts('Натуральний')));

        $this->line($cart, $cube, self::opts('Білий'));
        self::assertNull($cart->findSameUnpurchasedLine($cube, self::opts('Натуральний')));
        self::assertNull($cart->findSameUnpurchasedLine($cube, self::opts('Білий', '500')));
        self::assertNull($cart->findSameUnpurchasedLine($this->product(179), self::opts('Білий')));
        self::assertNotNull($cart->findSameUnpurchasedLine($cube, self::opts('Білий')));
    }

    public function testNoOptions(): void
    {
        $cart = new ShoppingCart();
        $ball = $this->product(119);
        $line = $this->line($cart, $ball, []);

        self::assertSame($line, $cart->findSameUnpurchasedLine($ball, []));
    }
}
