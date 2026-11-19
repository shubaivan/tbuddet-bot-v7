<?php

declare(strict_types=1);

namespace App\Service\Campaign;

/**
 * Retention: IPv4 keeps /24 (last octet zeroed), IPv6 keeps /48.
 * Anything unparsable is dropped (null).
 */
final class IpAnonymizer
{
    public static function anonymize(?string $ip): ?string
    {
        if (null === $ip || '' === $ip) {
            return null;
        }
        $packed = @inet_pton($ip);
        if (false === $packed) {
            return null;
        }
        if (4 === \strlen($packed)) {
            return inet_ntop(substr($packed, 0, 3)."\0");
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));
    }
}
