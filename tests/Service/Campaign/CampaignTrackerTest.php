<?php

declare(strict_types=1);

namespace App\Tests\Service\Campaign;

use App\Entity\User;
use App\Entity\UserOrder;
use App\Service\Campaign\CampaignStore;
use App\Service\Campaign\CampaignTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CampaignTrackerTest extends TestCase
{
    private const VID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private CampaignStore&MockObject $store;
    private RequestStack $requests;
    private CampaignTracker $tracker;

    protected function setUp(): void
    {
        $this->store = $this->createMock(CampaignStore::class);
        $this->requests = new RequestStack();
        $this->tracker = new CampaignTracker($this->store, $this->requests, new NullLogger());
    }

    public function testRegistrationFromQrVisitorLinksAccountAndRecordsEvent(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->with(self::VID)->willReturn($this->visitor());
        $this->store->expects($this->once())->method('linkVisitorToUser')->with(self::VID, 42);
        $this->store->expects($this->once())->method('insertEvent')->with(7, self::VID, 'register', 42, null);

        $this->tracker->registered($this->user(42));
    }

    public function testNoHeaderFallsBackToVisitorLinkedToAccount(): void
    {
        $this->withHeader(null);
        $this->store->expects($this->never())->method('findAttributableVisitor');
        $this->store->method('findAttributableVisitorForUser')->with(42)->willReturn($this->visitor(42));
        $this->store->expects($this->once())->method('insertEvent')->with(7, self::VID, 'add_to_cart', 42, null);

        $this->tracker->addedToCart($this->user(42));
    }

    public function testExpiredOrUnknownHeaderFallsBackToAccount(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->willReturn(null);
        $this->store->method('findAttributableVisitorForUser')->willReturn(null);
        $this->store->expects($this->never())->method('insertEvent');

        $this->tracker->loggedIn($this->user(42));
    }

    public function testForgedHeaderIsIgnored(): void
    {
        $this->withHeader("x' OR 1=1 --");
        $this->store->expects($this->never())->method('findAttributableVisitor');
        $this->store->method('findAttributableVisitorForUser')->willReturn(null);

        $this->tracker->loggedIn($this->user(42));
        $this->addToAssertionCount(1);
    }

    public function testOrderFromQrVisitorIsSnapshottedAsQr(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->willReturn($this->visitor());
        $this->store->expects($this->once())->method('setOrderCampaign')->with(555, 7, self::VID, 'qr')->willReturn(true);
        $this->store->expects($this->once())->method('insertEvent')->with(7, self::VID, 'order', 42, 555);

        $this->tracker->attributeOrder($this->order(555, 'ARTBETON15'), $this->user(42));
    }

    public function testGuestOrderWithHeaderIsAttributed(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->willReturn($this->visitor());
        $this->store->expects($this->never())->method('linkVisitorToUser');
        $this->store->expects($this->once())->method('setOrderCampaign')->with(556, 7, self::VID, 'qr')->willReturn(true);

        $this->tracker->attributeOrder($this->order(556, null), null);
    }

    public function testOrderWithCampaignCodeButNoScanIsAttributedAsPromocode(): void
    {
        $this->withHeader(null);
        $this->store->method('findAttributableVisitorForUser')->willReturn(null);
        $this->store->method('findCampaignIdByPromocodeCode')->with('ARTBETON15')->willReturn(7);
        $this->store->expects($this->once())->method('setOrderCampaign')->with(557, 7, null, 'promocode')->willReturn(true);
        $this->store->expects($this->once())->method('insertEvent')->with(7, null, 'order', 42, 557);

        $this->tracker->attributeOrder($this->order(557, 'ARTBETON15'), $this->user(42));
    }

    public function testOrderWithoutCampaignTouchesNothing(): void
    {
        $this->withHeader(null);
        $this->store->method('findAttributableVisitorForUser')->willReturn(null);
        $this->store->method('findCampaignIdByPromocodeCode')->willReturn(null);
        $this->store->expects($this->never())->method('setOrderCampaign');
        $this->store->expects($this->never())->method('insertEvent');

        $this->tracker->attributeOrder($this->order(558, 'WELCOME10'), $this->user(42));
    }

    public function testAlreadyAttributedOrderRecordsNoSecondEvent(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->willReturn($this->visitor());
        $this->store->method('setOrderCampaign')->willReturn(false);
        $this->store->expects($this->never())->method('insertEvent');

        $this->tracker->attributeOrder($this->order(559, null), $this->user(42));
    }

    public function testStoreFailureNeverBubblesUp(): void
    {
        $this->withHeader(self::VID);
        $this->store->method('findAttributableVisitor')->willThrowException(new \RuntimeException('db down'));

        $this->tracker->attributeOrder($this->order(560, null), $this->user(42));
        $this->tracker->registered($this->user(42));
        $this->tracker->landed(self::VID, 7);
        $this->addToAssertionCount(1);
    }

    public function testLandingOnlyForTheVisitorsOwnCampaign(): void
    {
        $this->store->method('findAttributableVisitor')->willReturn($this->visitor());
        $this->store->expects($this->once())->method('insertEvent')->with(7, self::VID, 'landing', null, null);

        $this->tracker->landed(self::VID, 7);
        $this->tracker->landed(self::VID, 8); // other campaign's page — ignored
    }

    private function withHeader(?string $vid): void
    {
        $request = new Request();
        if (null !== $vid) {
            $request->headers->set('X-Abm-Visitor', $vid);
        }
        $this->requests->push($request);
    }

    /** @return array{visitor_id: string, campaign_id: int, user_id: ?int} */
    private function visitor(?int $userId = null): array
    {
        return ['visitor_id' => self::VID, 'campaign_id' => 7, 'user_id' => $userId];
    }

    private function user(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private function order(int $id, ?string $code): UserOrder
    {
        $o = new UserOrder();
        (new \ReflectionProperty(UserOrder::class, 'id'))->setValue($o, $id);
        $o->setPromocodeCodeUsed($code);

        return $o;
    }
}
