<?php

declare(strict_types=1);

namespace App\Tests\Service\Delivery;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\UserOrder;
use App\Service\Delivery\CityDelivery;
use PHPUnit\Framework\TestCase;

class CityDeliveryTest extends TestCase
{
    public function testOptionsComeFromConfig(): void
    {
        $d = new CityDelivery(800, 'Черкаси');
        self::assertSame(
            ['available' => true, 'price' => 800, 'currency' => 'UAH', 'city' => 'Черкаси', 'label' => 'Доставка по місту (Черкаси)'],
            $d->options(UserLanguageEnum::UA),
        );
        self::assertFalse($d->isAvailable(UserLanguageEnum::EN), 'USD orders: no UAH-priced courier');
        self::assertFalse((new CityDelivery(0))->isAvailable(UserLanguageEnum::UA), 'price 0 hides the option');
        self::assertSame(950, (new CityDelivery(950))->price());
    }

    /** @dataProvider addresses */
    public function testAddressValidation(?string $address, bool $ok): void
    {
        self::assertSame($ok, null === (new CityDelivery(800))->validateAddress($address));
    }

    public static function addresses(): iterable
    {
        yield 'full' => ['вул. Смілянська, 120, кв. 5', true];
        yield 'short house' => ['Гоголя 7', true];
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
        yield 'no house number' => ['вулиця Шевченка', false];
        yield 'too long' => [str_repeat('а', 260).' 12', false];
    }

    public function testOrderSummaryAndTotalLine(): void
    {
        $o = (new UserOrder())
            ->setDeliveryMethod(CityDelivery::METHOD)
            ->setDeliveryAddress('вул. Гоголя, 7')
            ->setDeliveryAmount(800);
        $o->setDeliveryCity('Черкаси');

        self::assertTrue($o->isCityDelivery());
        self::assertSame('Доставка по місту (Черкаси): вул. Гоголя, 7 — 800 грн', $o->getDeliverySummary());

        $np = (new UserOrder())->setDeliveryMethod('nova_poshta');
        $np->setDeliveryCity('Київ');
        $np->setDeliveryDepartment('Відділення №1');
        self::assertSame('Нова Пошта: Київ, Відділення №1', $np->getDeliverySummary());
        self::assertSame(0, $np->getDeliveryAmount());

        self::assertSame('Самовивіз', (new UserOrder())->setDeliveryMethod('pickup')->getDeliverySummary());
    }

    /** «Купити зараз»: серіалізатор має заповнювати поля доставки (раніше губились мовчки). */
    public function testQuickBuyPayloadFieldsAreWritable(): void
    {
        $acc = \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor();
        $dto = new \App\Controller\API\Request\Purchase\PurchaseProduct();
        foreach (['delivery_method' => 'city_courier', 'delivery_address' => 'вул. Гоголя, 7', 'delivery_city' => 'Київ',
            'delivery_city_ref' => 'r', 'delivery_department' => 'd', 'delivery_department_ref' => 'dr',
            'contact_phone' => '0501112233', 'promocode' => 'X'] as $field => $value) {
            self::assertTrue($acc->isWritable($dto, $field), $field);
            $acc->setValue($dto, $field, $value);
        }
        self::assertSame('city_courier', $dto->getDeliveryMethod());
        self::assertSame('вул. Гоголя, 7', $dto->getDeliveryAddress());
        self::assertSame('0501112233', $dto->getContactPhone());
    }
}
