<?php

declare(strict_types=1);

namespace App\Service\Campaign;

final class ScanResult
{
    public function __construct(
        public readonly string $redirectUrl,
        /** Visitor id to (re)set in the abm_vid cookie; null = leave cookies alone (bots, unknown campaign). */
        public readonly ?string $cookieVisitorId = null,
        public readonly int $cookieDays = 60,
    ) {
    }
}
