<?php

declare(strict_types=1);

namespace App\Tests\Service\Campaign;

use App\Service\Campaign\BotDetector;
use PHPUnit\Framework\TestCase;

class BotDetectorTest extends TestCase
{
    /**
     * @dataProvider humans
     */
    public function testRealPhonesAreHumans(string $ua): void
    {
        $this->assertNull((new BotDetector())->detect($ua, 'GET'));
    }

    public static function humans(): iterable
    {
        yield 'iPhone camera → Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'];
        yield 'Android Chrome' => ['Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'];
        yield 'Telegram in-app browser' => ['Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36 Telegram-Android/11.2.3'];
        yield 'Instagram in-app' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 345.0.0'];
        yield 'scanner app okhttp' => ['okhttp/4.12.0'];
    }

    /**
     * @dataProvider bots
     */
    public function testBotsAndPreviewsAreFlagged(string $ua, string $method, array $headers, string $reason): void
    {
        $this->assertSame($reason, (new BotDetector())->detect($ua, $method, $headers));
    }

    public static function bots(): iterable
    {
        $chrome = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
        yield 'Telegram unfurl' => ['TelegramBot (like TwitterBot)', 'GET', [], 'preview'];
        yield 'WhatsApp unfurl' => ['WhatsApp/2.24.21.80 A', 'GET', [], 'preview'];
        yield 'Facebook/Instagram unfurl' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'GET', [], 'preview'];
        yield 'Viber unfurl' => ['Mozilla/5.0 (compatible; Viber/22.5)', 'GET', [], 'preview'];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'GET', [], 'crawler'];
        yield 'curl' => ['curl/8.5.0', 'GET', [], 'crawler'];
        yield 'python' => ['python-requests/2.32', 'GET', [], 'crawler'];
        yield 'headless' => ['Mozilla/5.0 HeadlessChrome/120.0', 'GET', [], 'crawler'];
        yield 'HEAD probe' => [$chrome, 'HEAD', [], 'head'];
        yield 'Chrome prefetch' => [$chrome, 'GET', ['sec-purpose' => 'prefetch;prerender'], 'prefetch'];
        yield 'Safari preview' => [$chrome, 'GET', ['purpose' => 'preview'], 'prefetch'];
        yield 'no UA' => ['', 'GET', [], 'empty_ua'];
    }
}
