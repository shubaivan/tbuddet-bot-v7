<?php

declare(strict_types=1);

namespace App\Service\Campaign;

/** Anonymous visitor id: 32 lower-case hex chars (128 random bits). */
final class VisitorId
{
    public const COOKIE = 'abm_vid';
    public const HEADER = 'X-Abm-Visitor';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValid(?string $id): bool
    {
        return null !== $id && 1 === preg_match('/^[a-f0-9]{32}$/', $id);
    }

    public static function normalize(?string $id): ?string
    {
        $id = null === $id ? null : strtolower(trim($id));

        return self::isValid($id) ? $id : null;
    }
}
