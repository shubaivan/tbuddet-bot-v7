<?php

namespace App\Tests\Service\Promocode;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Enum\PromocodePurposeEnum;
use App\Entity\Promocode;
use App\Entity\User;
use App\Repository\PromocodeRepository;
use App\Repository\UserOrderRepository;
use App\Service\Promocode\FirstOrderPromocodeService;
use App\Service\Promocode\PromocodeCodeGenerator;
use App\Service\Promocode\PromocodeDeliveryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the get-or-rotate logic of the personal first-order offer.
 */
class FirstOrderPromocodeServiceTest extends TestCase
{
    private PromocodeRepository&MockObject $promocodeRepository;
    private UserOrderRepository&MockObject $userOrderRepository;
    private PromocodeCodeGenerator&MockObject $codeGenerator;
    private PromocodeDeliveryService&MockObject $deliveryService;
    private EntityManagerInterface&MockObject $em;
    private FirstOrderPromocodeService $service;

    protected function setUp(): void
    {
        $this->promocodeRepository = $this->createMock(PromocodeRepository::class);
        $this->userOrderRepository = $this->createMock(UserOrderRepository::class);
        $this->codeGenerator = $this->createMock(PromocodeCodeGenerator::class);
        $this->deliveryService = $this->createMock(PromocodeDeliveryService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->service = new FirstOrderPromocodeService(
            $this->promocodeRepository,
            $this->userOrderRepository,
            $this->codeGenerator,
            $this->deliveryService,
            $this->em,
        );
    }

    public function testNoOfferWhenUserAlreadyHasPaidOrder(): void
    {
        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(1);
        $this->promocodeRepository->expects($this->never())->method('findLatestFirstOrderFor');

        $this->assertNull($this->service->getActiveOfferForUser($this->makeUser()));
    }

    public function testReturnsExistingStillValidCode(): void
    {
        $existing = $this->makeFirstOrderCode()->setValidTo(new \DateTime('+10 days'));

        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(0);
        $this->promocodeRepository->method('findLatestFirstOrderFor')->willReturn($existing);
        $this->codeGenerator->expects($this->never())->method('generate');

        $this->assertSame($existing, $this->service->getActiveOfferForUser($this->makeUser()));
    }

    public function testMintsFreshCodeWhenNoneExists(): void
    {
        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(0);
        $this->promocodeRepository->method('findLatestFirstOrderFor')->willReturn(null);
        $this->codeGenerator->method('generate')->willReturn('ABM-AAAA-BBBB');
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');
        $this->deliveryService->expects($this->once())->method('deliver');

        $offer = $this->service->getActiveOfferForUser($this->makeUser());

        $this->assertNotNull($offer);
        $this->assertSame('ABM-AAAA-BBBB', $offer->getCode());
        $this->assertSame(PromocodePurposeEnum::FIRST_ORDER, $offer->getPurpose());
        $this->assertSame(DiscountTypeEnum::PERCENT, $offer->getDiscountType());
        $this->assertSame(10, $offer->getValue());
        $this->assertSame(CurrencyEnum::UAH, $offer->getCurrency());
        $this->assertSame(1, $offer->getMaxUses());
        $this->assertSame(1, $offer->getMaxUsesPerUser());
        $this->assertTrue($offer->isActive());
        $this->assertNotNull($offer->getValidTo());
    }

    public function testRotatesExpiredCode(): void
    {
        $expired = $this->makeFirstOrderCode()->setValidTo(new \DateTime('-1 day'));

        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(0);
        $this->promocodeRepository->method('findLatestFirstOrderFor')->willReturn($expired);
        $this->codeGenerator->method('generate')->willReturn('ABM-NEWC-ODE9');

        $offer = $this->service->getActiveOfferForUser($this->makeUser());

        $this->assertFalse($expired->isActive(), 'stale code should be retired');
        $this->assertSame('ABM-NEWC-ODE9', $offer->getCode());
        $this->assertTrue($offer->isActive());
    }

    public function testTelegramOfferIsNotAutoDelivered(): void
    {
        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(0);
        $this->promocodeRepository->method('findLatestFirstOrderFor')->willReturn(null);
        $this->codeGenerator->method('generate')->willReturn('ABM-TGTG-1234');
        $this->deliveryService->expects($this->never())->method('deliver');

        $tgUser = $this->createMock(\App\Entity\TelegramUser::class);
        $offer = $this->service->getActiveOfferForTelegramUser($tgUser);

        $this->assertNotNull($offer);
        $this->assertSame('ABM-TGTG-1234', $offer->getCode());
    }

    public function testWhitelistedUserGetsOfferDespitePaidOrder(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(12);

        $this->userOrderRepository->method('countPaidOrdersFor')->willReturn(5);
        $this->promocodeRepository->method('findLatestFirstOrderFor')->willReturn(null);
        $this->codeGenerator->method('generate')->willReturn('ABM-WLST-0001');

        $service = new FirstOrderPromocodeService(
            $this->promocodeRepository,
            $this->userOrderRepository,
            $this->codeGenerator,
            $this->deliveryService,
            $this->em,
            null,
            ['12'],
            [],
        );

        $offer = $service->getActiveOfferForUser($user);

        $this->assertNotNull($offer, 'whitelisted user should still get an offer');
        $this->assertSame('ABM-WLST-0001', $offer->getCode());
    }

    private function makeFirstOrderCode(): Promocode
    {
        return (new Promocode())
            ->setCode('ABM-OLD0-CODE')
            ->setPurpose(PromocodePurposeEnum::FIRST_ORDER)
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(10)
            ->setCurrency(CurrencyEnum::UAH)
            ->setMaxUses(1)
            ->setIsActive(true);
    }

    private function makeUser(): User
    {
        return $this->createMock(User::class);
    }
}
