<?php

declare(strict_types=1);

namespace App\Tests\Service\Campaign;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use App\Service\Campaign\BotDetector;
use App\Service\Campaign\CampaignStore;
use App\Service\Campaign\QrScanService;
use App\Service\Campaign\ScanContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class QrScanServiceTest extends TestCase
{
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 Version/18.1 Mobile/15E148 Safari/604.1';

    private CampaignRepository&MockObject $campaigns;
    private CampaignStore&MockObject $store;
    private QrScanService $service;
    /** @var list<array<string, mixed>> */
    private array $scans = [];

    protected function setUp(): void
    {
        $this->campaigns = $this->createMock(CampaignRepository::class);
        $this->store = $this->createMock(CampaignStore::class);
        $this->store->method('insertScan')->willReturnCallback(function (array $scan): void {
            $this->scans[] = $scan;
        });
        $this->service = new QrScanService($this->campaigns, $this->store, new BotDetector(), new NullLogger(), 'https://artbeton.market');
    }

    public function testFirstScanMintsVisitorAndRedirectsWithIt(): void
    {
        $this->campaigns->method('findBySlug')->with('ch27')->willReturn($this->campaign());
        $this->store->expects($this->once())->method('createVisitor')->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'), 7);

        $result = $this->service->handle('CH27', $this->ctx(self::PHONE));

        $this->assertNotNull($result->cookieVisitorId);
        $this->assertSame(
            'https://artbeton.market/uk/?abm=ch27&abm_v='.$result->cookieVisitorId.'&utm_source=qr&utm_medium=offline&utm_campaign=ch27',
            $result->redirectUrl,
        );
        $this->assertSame(60, $result->cookieDays);
        $this->assertCount(1, $this->scans);
        $this->assertSame(7, $this->scans[0]['campaign_id']);
        $this->assertSame('93.170.45.123', $this->scans[0]['ip']);
        $this->assertSame(self::PHONE, $this->scans[0]['user_agent']);
        $this->assertFalse($this->scans[0]['is_bot']);
        $this->assertFalse($this->scans[0]['is_repeat']);
    }

    public function testRescanFromSameDeviceKeepsVisitor(): void
    {
        $vid = str_repeat('ab', 16);
        $this->campaigns->method('findBySlug')->willReturn($this->campaign());
        $this->store->method('findVisitor')->with($vid)->willReturn(['visitor_id' => $vid, 'campaign_id' => 7, 'first_seen_at' => '2026-11-27 10:00:00', 'user_id' => null]);
        $this->store->expects($this->never())->method('createVisitor');
        $this->store->expects($this->once())->method('touchVisitor')->with($vid);

        $result = $this->service->handle('ch27', $this->ctx(self::PHONE, $vid));

        $this->assertSame($vid, $result->cookieVisitorId);
        $this->assertTrue($this->scans[0]['is_repeat']);
    }

    public function testForgedCookieIsIgnored(): void
    {
        $this->campaigns->method('findBySlug')->willReturn($this->campaign());
        $this->store->method('findVisitor')->willReturn(null);
        $this->store->expects($this->once())->method('createVisitor');

        $result = $this->service->handle('ch27', $this->ctx(self::PHONE, str_repeat('cd', 16)));

        $this->assertNotSame(str_repeat('cd', 16), $result->cookieVisitorId);
    }

    public function testLinkPreviewIsLoggedButGetsNoVisitor(): void
    {
        $this->campaigns->method('findBySlug')->willReturn($this->campaign());
        $this->store->expects($this->never())->method('createVisitor');

        $result = $this->service->handle('ch27', $this->ctx('TelegramBot (like TwitterBot)'));

        $this->assertNull($result->cookieVisitorId);
        $this->assertStringNotContainsString('abm_v=', $result->redirectUrl);
        $this->assertStringContainsString('abm=ch27', $result->redirectUrl);
        $this->assertTrue($this->scans[0]['is_bot']);
        $this->assertSame('preview', $this->scans[0]['bot_reason']);
    }

    public function testUnknownSlugStillLandsOnTheShop(): void
    {
        $this->campaigns->method('findBySlug')->willReturn(null);

        $result = $this->service->handle('nope', $this->ctx(self::PHONE));

        $this->assertSame('https://artbeton.market/uk/?utm_source=qr&utm_medium=offline&utm_campaign=nope', $result->redirectUrl);
        $this->assertNull($result->cookieVisitorId);
        $this->assertNull($this->scans[0]['campaign_id']);
        $this->assertSame('nope', $this->scans[0]['slug']);
    }

    public function testInactiveCampaignIsLoggedWithoutPromo(): void
    {
        $this->campaigns->method('findBySlug')->willReturn($this->campaign()->setIsActive(false));
        $this->store->expects($this->never())->method('createVisitor');

        $result = $this->service->handle('ch27', $this->ctx(self::PHONE));

        $this->assertStringNotContainsString('abm=', $result->redirectUrl);
        $this->assertSame(7, $this->scans[0]['campaign_id']);
    }

    public function testDatabaseDownStillRedirects(): void
    {
        $this->campaigns->method('findBySlug')->willThrowException(new \RuntimeException('db down'));
        $store = $this->createMock(CampaignStore::class);
        $store->method('insertScan')->willThrowException(new \RuntimeException('db down'));
        $service = new QrScanService($this->campaigns, $store, new BotDetector(), new NullLogger(), 'https://artbeton.market/');

        $result = $service->handle('ch27', $this->ctx(self::PHONE));

        $this->assertStringStartsWith('https://artbeton.market/uk/?', $result->redirectUrl);
    }

    public function testTargetPathWithQueryIsKept(): void
    {
        $this->campaigns->method('findBySlug')->willReturn($this->campaign()->setTargetPath('/uk/product?sort=new'));

        $result = $this->service->handle('ch27', $this->ctx(self::PHONE));

        $this->assertStringStartsWith('https://artbeton.market/uk/product?sort=new&abm=ch27&abm_v=', $result->redirectUrl);
    }

    private function campaign(): Campaign
    {
        $c = (new Campaign())->setSlug('ch27')->setName('Черкаси');
        (new \ReflectionProperty(Campaign::class, 'id'))->setValue($c, 7);

        return $c;
    }

    private function ctx(string $ua, ?string $cookie = null): ScanContext
    {
        return new ScanContext('GET', '93.170.45.123', $ua, null, 'uk-UA,uk;q=0.9', $cookie);
    }
}
