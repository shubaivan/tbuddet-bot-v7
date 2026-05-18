<?php

namespace App\Tests\Entity;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Promocode;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the discount math and description helper on Promocode.
 * Doesn't touch the DB — the validation/redemption flow that depends on
 * repositories is exercised separately via {@see PromocodeServiceTest}.
 */
class PromocodeTest extends TestCase
{
    public function testPercentDiscountIsRoundedHalfUp(): void
    {
        $p = (new Promocode())
            ->setCode('TEST')
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(10)
            ->setCurrency(CurrencyEnum::UAH);

        $this->assertSame(0, $p->computeDiscount(0));
        $this->assertSame(10, $p->computeDiscount(100));
        $this->assertSame(20, $p->computeDiscount(200));
        // 333 * 0.10 = 33.3 → rounds to 33 (PHP_ROUND_HALF_UP, ties go away from zero)
        $this->assertSame(33, $p->computeDiscount(333));
        // 335 * 0.10 = 33.5 → rounds to 34
        $this->assertSame(34, $p->computeDiscount(335));
    }

    public function testFixedAmountDiscountIsClampedToSubtotal(): void
    {
        $p = (new Promocode())
            ->setCode('TEST')
            ->setDiscountType(DiscountTypeEnum::FIXED_AMOUNT)
            ->setValue(500)
            ->setCurrency(CurrencyEnum::UAH);

        $this->assertSame(500, $p->computeDiscount(1000));
        $this->assertSame(500, $p->computeDiscount(500));
        // A 500-UAH discount on a 200-UAH order must not produce a negative total.
        $this->assertSame(200, $p->computeDiscount(200));
        $this->assertSame(0, $p->computeDiscount(0));
    }

    public function testCodeIsNormalizedToUppercase(): void
    {
        $p = (new Promocode())->setCode('  spring20  ');

        $this->assertSame('SPRING20', $p->getCode());
    }

    public function testDescribeDiscountForPercentCode(): void
    {
        $p = (new Promocode())
            ->setCode('SPRING20')
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(20)
            ->setCurrency(CurrencyEnum::UAH);

        $this->assertSame('Промокод SPRING20: -20% (-200 грн)', $p->describeDiscount(200));
    }

    public function testDescribeDiscountForFixedAmountCode(): void
    {
        $p = (new Promocode())
            ->setCode('ABM-7G4K-9PXM')
            ->setDiscountType(DiscountTypeEnum::FIXED_AMOUNT)
            ->setValue(500)
            ->setCurrency(CurrencyEnum::UAH);

        $this->assertSame('Промокод ABM-7G4K-9PXM: -500 грн', $p->describeDiscount(500));
    }

    public function testDescribeDiscountUsesCurrencyLabel(): void
    {
        $p = (new Promocode())
            ->setCode('USD10')
            ->setDiscountType(DiscountTypeEnum::FIXED_AMOUNT)
            ->setValue(10)
            ->setCurrency(CurrencyEnum::USD);

        $this->assertSame('Промокод USD10: -10 $', $p->describeDiscount(10));
    }
}
