<?php

declare(strict_types=1);

namespace App\Tests\Service\Telegram;

use App\Entity\TelegramUser;
use App\Entity\User;
use App\Repository\TelegramUserRepository;
use App\Repository\UserMergeRepository;
use App\Repository\UserRepository;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A bot user who shares their phone is linked to a web account only when that account
 * already belongs to the same Telegram user — never because a typed-in profile phone
 * happens to match.
 */
final class TelegramUserServicePhoneMergeTest extends TestCase
{
    private function service(?User $matched, EntityManagerInterface $em, ?UserMergeRepository $merges = null): TelegramUserService
    {
        $telegramUsers = $this->createMock(TelegramUserRepository::class);
        $telegramUsers->method('getByTelegramId')->willReturn((new TelegramUser())->setTelegramId('5551'));
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($matched);
        $users->expects(self::never())->method('matchUserByPhone');

        $service = new TelegramUserService($telegramUsers, $users, $merges ?? $this->createMock(UserMergeRepository::class), $em);
        $service->initUser(['id' => '5551']);

        return $service;
    }

    public function testNoMergeIntoAStrangersAccountWithTheSamePhone(): void
    {
        $stranger = (new User())->setTelegramChatId(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $service = $this->service($stranger, $em);
        $service->savePhone('+380671234567');

        self::assertSame('+380671234567', $service->getCurrentUser()?->getPhoneNumber());
        self::assertNotNull($service->getCurrentUser()?->getPhoneVerifiedAt());
    }

    public function testNoMergeIntoAnAccountOfAnotherTelegramUser(): void
    {
        $other = (new User())->setTelegramChatId(9999);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $this->service($other, $em)->savePhone('+380671234567');
    }

    public function testMergeIntoTheOwnAccount(): void
    {
        $own = (new User())->setTelegramChatId(5551);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $merges = $this->createMock(UserMergeRepository::class);
        $merges->method('getByUser')->willReturn(null);

        $this->service($own, $em, $merges)->savePhone('+380671234567');
    }
}
