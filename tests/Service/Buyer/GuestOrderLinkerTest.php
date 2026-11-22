<?php

declare(strict_types=1);

namespace App\Tests\Service\Buyer;

use App\Entity\User;
use App\Service\Buyer\GuestOrderLinker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The cabinet trusts only a verified phone: an account without its own Telegram has none. */
final class GuestOrderLinkerTest extends TestCase
{
    public function testAProfilePhoneAloneShowsNoGuestOrders(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');
        $user = (new User())->setPhone('380671234567')->setTelegramChatId(null);

        $linker = new GuestOrderLinker($em, new NullLogger());

        self::assertSame([], $linker->verifiedPhoneKeys($user));
        self::assertSame([], $linker->unlinkedOrdersOfVerifiedPhones($user));
    }
}
