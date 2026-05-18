<?php

namespace App\Service\Promocode;

use App\Repository\PromocodeRepository;

/**
 * Generates human-readable single-use codes in the format ABM-XXXX-XXXX.
 * The XXXX blocks use an alphabet without visually-ambiguous characters
 * (no 0/O, no 1/I/L) so a user typing the code into a phone keyboard rarely misreads it.
 */
class PromocodeCodeGenerator
{
    private const PREFIX = 'ABM';
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const BLOCK_LENGTH = 4;
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly PromocodeRepository $promocodeRepository)
    {
    }

    /**
     * Returns a unique code not yet present in the promocode table.
     *
     * Collision probability per attempt at 30^8 ≈ 6.56e11 is negligible at our
     * scale; the MAX_ATTEMPTS loop is purely defensive.
     */
    public function generate(): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = sprintf(
                '%s-%s-%s',
                self::PREFIX,
                $this->randomBlock(),
                $this->randomBlock(),
            );

            if ($this->promocodeRepository->findActiveByCode($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique promocode after ' . self::MAX_ATTEMPTS . ' attempts');
    }

    private function randomBlock(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::BLOCK_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
