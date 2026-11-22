<?php

namespace App\Tests\Service\Promocode;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Enum\PromocodeErrorEnum;
use App\Entity\Promocode;
use App\Entity\User;
use App\Repository\PromocodeRedemptionRepository;
use App\Repository\PromocodeRepository;
use App\Entity\Enum\PromocodePurposeEnum;
use App\Service\Buyer\BuyerHistory;
use App\Service\Promocode\PromocodeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the validate() path matrix. redeem() is covered separately
 * by an integration test (since it needs a real DB to exercise the UNIQUE
 * constraint atomicity guarantee).
 */
class PromocodeServiceTest extends TestCase
{
    private PromocodeRepository&MockObject $promocodeRepository;
    private PromocodeRedemptionRepository&MockObject $redemptionRepository;
    private EntityManagerInterface&MockObject $em;
    private BuyerHistory&MockObject $buyerHistory;
    private PromocodeService $service;

    protected function setUp(): void
    {
        $this->promocodeRepository = $this->createMock(PromocodeRepository::class);
        $this->redemptionRepository = $this->createMock(PromocodeRedemptionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->buyerHistory = $this->createMock(BuyerHistory::class);

        $this->service = new PromocodeService(
            $this->promocodeRepository,
            $this->redemptionRepository,
            $this->em,
            $this->buyerHistory,
        );
    }

    public function testReturnsNotFoundWhenCodeDoesNotExist(): void
    {
        $this->promocodeRepository->method('findActiveByCode')->willReturn(null);

        $result = $this->service->validate('NOPE', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertFalse($result->ok);
        $this->assertSame(PromocodeErrorEnum::NOT_FOUND, $result->error);
        $this->assertNull($result->promocode);
        $this->assertSame(0, $result->discount);
    }

    public function testReturnsInactiveWhenCodeIsDeactivated(): void
    {
        $p = $this->makePromocode()->setIsActive(false);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertFalse($result->ok);
        $this->assertSame(PromocodeErrorEnum::INACTIVE, $result->error);
    }

    public function testReturnsCurrencyMismatchWhenCartCurrencyDiffers(): void
    {
        $p = $this->makePromocode()->setCurrency(CurrencyEnum::UAH);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::USD, null, null, null);

        $this->assertFalse($result->ok);
        $this->assertSame(PromocodeErrorEnum::CURRENCY_MISMATCH, $result->error);
    }

    public function testReturnsNotYetValidBeforeValidFrom(): void
    {
        $p = $this->makePromocode()->setValidFrom(new \DateTime('+1 day'));
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertSame(PromocodeErrorEnum::NOT_YET_VALID, $result->error);
    }

    public function testReturnsExpiredAfterValidTo(): void
    {
        $p = $this->makePromocode()->setValidTo(new \DateTime('-1 day'));
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertSame(PromocodeErrorEnum::EXPIRED, $result->error);
    }

    public function testReturnsBelowMinOrderWhenSubtotalTooSmall(): void
    {
        $p = $this->makePromocode()->setMinOrderAmount(1000);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 500, CurrencyEnum::UAH, null, null, null);

        $this->assertSame(PromocodeErrorEnum::BELOW_MIN_ORDER, $result->error);
    }

    public function testReturnsNotAssignedToYouWhenAnotherUserOwns(): void
    {
        $assignee = $this->makeUser(42);
        $other = $this->makeUser(43);

        $p = $this->makePromocode()->setAssignedUser($assignee);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, $other, null, null);

        $this->assertSame(PromocodeErrorEnum::NOT_ASSIGNED_TO_YOU, $result->error);
    }

    public function testReturnsNotAssignedToYouWhenAnonymousAttemptsAssignedCode(): void
    {
        $p = $this->makePromocode()->setAssignedUser($this->makeUser(42));
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, '380501234567');

        $this->assertSame(PromocodeErrorEnum::NOT_ASSIGNED_TO_YOU, $result->error);
    }

    public function testReturnsExhaustedWhenGlobalLimitReached(): void
    {
        $p = $this->makePromocode()->setMaxUses(5)->setTimesUsed(5);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertSame(PromocodeErrorEnum::EXHAUSTED, $result->error);
    }

    public function testReturnsUserLimitReachedWhenBuyerHitPerUserCap(): void
    {
        $user = $this->makeUser(42);
        $p = $this->makePromocode()->setMaxUsesPerUser(2);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory
            ->method('countRedemptions')
            ->willReturn(2);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, $user, null, null);

        $this->assertSame(PromocodeErrorEnum::USER_LIMIT_REACHED, $result->error);
    }

    public function testReturnsSuccessAndComputesDiscount(): void
    {
        $p = $this->makePromocode()
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(15);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 2000, CurrencyEnum::UAH, null, null, null);

        $this->assertTrue($result->ok);
        $this->assertSame(300, $result->discount);
        $this->assertSame($p, $result->promocode);
    }

    public function testAssignedTelegramUserMatches(): void
    {
        $tgUser = $this->createMock(\App\Entity\TelegramUser::class);
        $tgUser->method('getId')->willReturn(7);

        $p = $this->makePromocode()->setAssignedTelegramUser($tgUser);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, $tgUser, null);

        $this->assertTrue($result->ok);
    }

    public function testPerBuyerLimitCountsTheGuestPhone(): void
    {
        $p = $this->makePromocode()->setMaxUsesPerUser(1);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->expects($this->once())
            ->method('countRedemptions')
            ->with($p, null, null, '380671234567')
            ->willReturn(1);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, '380671234567');

        $this->assertSame(PromocodeErrorEnum::USER_LIMIT_REACHED, $result->error);
    }

    public function testWelcomeCodeNeedsAPhoneFromAGuest(): void
    {
        $p = $this->makePromocode()->setPurpose(PromocodePurposeEnum::WELCOME);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->expects($this->never())->method('hasPreviousOrders');

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null);

        $this->assertSame(PromocodeErrorEnum::PHONE_REQUIRED, $result->error);
    }

    public function testWelcomeCodeRefusedForAPhoneWithAnOrder(): void
    {
        $p = $this->makePromocode()->setPurpose(PromocodePurposeEnum::WELCOME);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->method('hasPreviousOrders')->with(null, null, '380671234567')->willReturn(true);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, '380671234567');

        $this->assertSame(PromocodeErrorEnum::FIRST_ORDER_ONLY, $result->error);
    }

    public function testWelcomeCodeWorksForAFirstTimeGuest(): void
    {
        $p = $this->makePromocode()->setPurpose(PromocodePurposeEnum::WELCOME)
            ->setDiscountType(DiscountTypeEnum::PERCENT)->setValue(10);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->method('hasPreviousOrders')->willReturn(false);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, '380671234567');

        $this->assertTrue($result->ok);
        $this->assertSame(100, $result->discount);
    }

    public function testPersonalFirstOrderCodeRefusedWhenTheAccountOrderedBefore(): void
    {
        $user = $this->makeUser(42);
        $p = $this->makePromocode()->setPurpose(PromocodePurposeEnum::FIRST_ORDER)->setAssignedUser($user);
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->method('hasPreviousOrders')->with($user, null, null)->willReturn(true);

        $result = $this->service->validate('TEST', 1000, CurrencyEnum::UAH, $user, null, null);

        $this->assertSame(PromocodeErrorEnum::FIRST_ORDER_ONLY, $result->error);
    }

    public function testGenericCodeIgnoresOrderHistory(): void
    {
        $p = $this->makePromocode();
        $this->promocodeRepository->method('findActiveByCode')->willReturn($p);
        $this->buyerHistory->expects($this->never())->method('hasPreviousOrders');

        $this->assertTrue($this->service->validate('TEST', 1000, CurrencyEnum::UAH, null, null, null)->ok);
    }

    private function makePromocode(): Promocode
    {
        return (new Promocode())
            ->setCode('TEST')
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(10)
            ->setCurrency(CurrencyEnum::UAH);
    }

    private function makeUser(int $id): User
    {
        $u = $this->createMock(User::class);
        $u->method('getId')->willReturn($id);

        return $u;
    }
}
