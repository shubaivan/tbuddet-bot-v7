<?php

declare(strict_types=1);

namespace App\Service\Campaign;

/**
 * Tells link-preview fetchers, crawlers and prefetches apart from a person who
 * scanned the QR. Such hits are still logged (is_bot + reason) but get no
 * visitor id and never count in the funnel.
 *
 * Deliberately conservative: generic app HTTP stacks (okhttp, CFNetwork, Dalvik)
 * are NOT flagged — QR-scanner apps and in-app browsers use them for real people.
 * The definitive human signal is the "landing" event the site's JS sends.
 */
final class BotDetector
{
    /** Messenger / social link unfurlers (someone pasted the link into a chat). */
    private const PREVIEW = [
        'telegrambot', 'whatsapp', 'facebookexternalhit', 'facebot', 'viber', 'slackbot',
        'slack-imgproxy', 'discordbot', 'twitterbot', 'linkedinbot', 'skypeuripreview',
        'pinterest', 'redditbot', 'embedly', 'iframely', 'snapchat', 'signal',
        'applebot', 'google-pagerenderer', 'googleother', 'bitlybot', 'outlook', 'microsoftpreview',
    ];

    /** Search engines, monitors, scripts and headless browsers. */
    private const CRAWLER = [
        'bot/', 'bot;', 'bot ', 'crawler', 'spider', 'slurp', 'bingpreview', 'headlesschrome',
        'phantomjs', 'curl/', 'wget/', 'python-requests', 'python-urllib', 'aiohttp', 'httpx',
        'go-http-client', 'java/', 'libwww-perl', 'scrapy', 'node-fetch', 'axios/', 'postmanruntime',
        'uptimerobot', 'pingdom', 'statuscake', 'lighthouse', 'chrome-lighthouse', 'semrush', 'ahrefs',
        'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'gptbot', 'claudebot', 'ccbot', 'amazonbot',
    ];

    /**
     * @param array<string, string|null> $headers lower-case header name => value
     *
     * @return string|null reason ("head", "prefetch", "empty_ua", "preview", "crawler") or null for a human
     */
    public function detect(?string $userAgent, string $method, array $headers = []): ?string
    {
        if ('HEAD' === strtoupper($method)) {
            return 'head';
        }

        foreach (['purpose', 'sec-purpose', 'x-purpose', 'x-moz'] as $h) {
            $v = strtolower((string) ($headers[$h] ?? ''));
            if (str_contains($v, 'prefetch') || str_contains($v, 'preview') || str_contains($v, 'prerender')) {
                return 'prefetch';
            }
        }

        $ua = strtolower(trim((string) $userAgent));
        if ('' === $ua) {
            return 'empty_ua';
        }

        foreach (self::PREVIEW as $needle) {
            if (str_contains($ua, $needle)) {
                return 'preview';
            }
        }
        foreach (self::CRAWLER as $needle) {
            if (str_contains($ua, $needle)) {
                return 'crawler';
            }
        }
        if (str_ends_with($ua, 'bot')) {
            return 'crawler';
        }

        return null;
    }
}
