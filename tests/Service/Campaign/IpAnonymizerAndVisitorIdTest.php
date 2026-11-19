<?php

declare(strict_types=1);

namespace App\Tests\Service\Campaign;

use App\Entity\Campaign;
use App\Service\Campaign\IpAnonymizer;
use App\Service\Campaign\VisitorId;
use PHPUnit\Framework\TestCase;

class IpAnonymizerAndVisitorIdTest extends TestCase
{
    public function testIpv4KeepsSlash24(): void
    {
        $this->assertSame('93.170.45.0', IpAnonymizer::anonymize('93.170.45.123'));
    }

    public function testIpv6KeepsSlash48(): void
    {
        $this->assertSame('2a02:2378:1234::', IpAnonymizer::anonymize('2a02:2378:1234:5678:9abc:def0:1:2'));
    }

    public function testGarbageIpIsDropped(): void
    {
        $this->assertNull(IpAnonymizer::anonymize('not-an-ip'));
        $this->assertNull(IpAnonymizer::anonymize(null));
    }

    public function testVisitorIdFormat(): void
    {
        $id = VisitorId::generate();
        $this->assertTrue(VisitorId::isValid($id));
        $this->assertNotSame($id, VisitorId::generate());
        $this->assertSame($id, VisitorId::normalize(' '.strtoupper($id).' '));
        $this->assertNull(VisitorId::normalize('../../etc/passwd'));
        $this->assertNull(VisitorId::normalize(str_repeat('a', 31)));
    }

    public function testSlugRules(): void
    {
        $this->assertSame('ch27', (new Campaign())->setSlug(' CH27 ')->getSlug());
        $this->assertTrue(Campaign::isValidSlug('ch27'));
        $this->assertFalse(Campaign::isValidSlug('ч27'));
        $this->assertFalse(Campaign::isValidSlug('-x'));
        $this->assertFalse(Campaign::isValidSlug(''));
    }

    public function testTargetPathCannotBecomeAnOpenRedirect(): void
    {
        $c = new Campaign();
        $this->assertSame('/uk/product', $c->setTargetPath('/uk/product')->getTargetPath());
        $this->assertSame('/uk/', $c->setTargetPath('https://evil.example')->getTargetPath());
        $this->assertSame('/uk/', $c->setTargetPath('//evil.example')->getTargetPath());
        $this->assertSame('/uk/', $c->setTargetPath('/\\evil.example')->getTargetPath());
    }
}
