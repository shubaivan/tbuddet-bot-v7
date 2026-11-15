<?php

declare(strict_types=1);

namespace App\Tests\Service\SupportChat;

use App\Service\SupportChat\ConsultantHealthState as S;
use PHPUnit\Framework\TestCase;

class ConsultantHealthStateTest extends TestCase
{
    /** @dataProvider errors */
    public function testClassify(string $error, string $expected): void
    {
        self::assertSame($expected, S::classify($error));
        self::assertSame($expected, S::classify(new \RuntimeException($error)));
    }

    public static function errors(): iterable
    {
        yield 'balance' => ['{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}', S::BALANCE];
        yield 'invalid key' => ['{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}', S::KEY];
        yield 'permission' => ['{"type":"error","error":{"type":"permission_error","message":"Your API key does not have permission"}}', S::KEY];
        yield 'overloaded' => ['{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', S::OTHER];
        yield 'network' => ['cURL error 28: Operation timed out', S::OTHER];
        yield '500' => ['{"type":"error","error":{"type":"api_error","message":"Internal server error"}}', S::OTHER];
    }

    public function testFirstRunInitialisesSilently(): void
    {
        self::assertSame(['state' => ['state' => S::OK, 'pending' => 0], 'notify' => null], S::next(null, S::OK));
        self::assertNull(S::next(null, S::BALANCE)['notify']);
        self::assertSame(S::BALANCE, S::next(null, S::BALANCE)['state']['state']);
    }

    public function testOkStaysQuiet(): void
    {
        self::assertNull(S::next(['state' => S::OK, 'pending' => 0], S::OK)['notify']);
    }

    public function testBalanceAndKeyAlertImmediatelyOnce(): void
    {
        $r = S::next(['state' => S::OK, 'pending' => 0], S::BALANCE);
        self::assertSame(S::BALANCE, $r['notify']);
        self::assertNull(S::next($r['state'], S::BALANCE)['notify'], 'no repeat while still failing');

        $r = S::next($r['state'], S::KEY);
        self::assertSame(S::KEY, $r['notify'], 'failure kind change is a new alert');
    }

    public function testOtherNeedsTwoConsecutiveFailures(): void
    {
        $r = S::next(['state' => S::OK, 'pending' => 0], S::OTHER);
        self::assertNull($r['notify']);
        self::assertSame(['state' => S::OK, 'pending' => 1], $r['state']);

        $r2 = S::next($r['state'], S::OTHER);
        self::assertSame(S::OTHER, $r2['notify']);
        self::assertSame(S::OTHER, $r2['state']['state']);
    }

    public function testSingleOtherBlipIsForgottenAfterOk(): void
    {
        $r = S::next(['state' => S::OK, 'pending' => 0], S::OTHER);
        $r = S::next($r['state'], S::OK);
        self::assertNull($r['notify']);
        self::assertSame(['state' => S::OK, 'pending' => 0], $r['state']);
        self::assertNull(S::next($r['state'], S::OTHER)['notify']);
    }

    public function testOtherDuringKnownOutageChangesNothing(): void
    {
        $r = S::next(['state' => S::BALANCE, 'pending' => 0], S::OTHER);
        self::assertNull($r['notify']);
        self::assertSame(S::BALANCE, $r['state']['state']);
    }

    public function testOtherThenDefinitiveFailureAlerts(): void
    {
        self::assertSame(S::BALANCE, S::next(['state' => S::OTHER, 'pending' => 0], S::BALANCE)['notify']);
    }

    /** @dataProvider failing */
    public function testRecoveryAlertsOnce(string $from): void
    {
        $r = S::next(['state' => $from, 'pending' => 0], S::OK);
        self::assertSame(S::OK, $r['notify']);
        self::assertNull(S::next($r['state'], S::OK)['notify']);
    }

    public static function failing(): iterable
    {
        yield [S::BALANCE];
        yield [S::KEY];
        yield [S::OTHER];
    }

    public function testMessages(): void
    {
        self::assertStringContainsString('закінчився баланс Anthropic', S::message(S::BALANCE));
        self::assertStringContainsString('https://platform.claude.com/settings/billing', S::message(S::BALANCE));
        self::assertStringContainsString('недійсний ключ API', S::message(S::KEY));
        self::assertStringContainsString('https://platform.claude.com/settings/keys', S::message(S::KEY));
        self::assertStringContainsString('збій Anthropic', S::message(S::OTHER));
        self::assertSame('✅ <b>Консультант знову працює.</b> Баланс/ключ у порядку, відповідає покупцям.', S::message(S::OK));
    }
}
