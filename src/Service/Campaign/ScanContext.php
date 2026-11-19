<?php

declare(strict_types=1);

namespace App\Service\Campaign;

use Symfony\Component\HttpFoundation\Request;

/** What we know about one hit on /q/{slug}. */
final class ScanContext
{
    /**
     * @param array<string, string|null> $headers lower-case name => value (only the few the bot check reads)
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $ip,
        public readonly string $userAgent,
        public readonly ?string $referer,
        public readonly ?string $acceptLanguage,
        public readonly ?string $cookieVisitorId,
        public readonly array $headers = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $headers = [];
        foreach (['purpose', 'sec-purpose', 'x-purpose', 'x-moz'] as $h) {
            $headers[$h] = $request->headers->get($h);
        }

        return new self(
            $request->getMethod(),
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
            $request->headers->get('Referer'),
            $request->headers->get('Accept-Language'),
            $request->cookies->get(VisitorId::COOKIE),
            $headers,
        );
    }
}
