<?php

declare(strict_types=1);

namespace App\Service\Cart;

/** Who placed a guest order (no account): what the buyer typed on the checkout page. */
final class GuestContact
{
    public function __construct(
        /** 380XXXXXXXXX — the same format as a quick buy and client_user.phone. */
        public readonly string $phone,
        public readonly string $name,
        public readonly ?string $email = null,
    ) {
    }

    /** «Ім'я, 380…» — for the manager notifications. */
    public function label(): string
    {
        return trim($this->name.', '.$this->phone, ', ');
    }
}
