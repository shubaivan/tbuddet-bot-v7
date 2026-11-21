<?php

declare(strict_types=1);

namespace App\Controller\API\Request\Guest;

use App\Validator\UserLanguage;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Prices of a guest cart from the catalogue + an optional promocode preview —
 * the numbers the checkout will charge (the guest analogue of /promocode/validate).
 */
#[UserLanguage]
class GuestQuoteRequest
{
    use GuestCartItems;

    #[Assert\Type('string')]
    #[Assert\Length(max: 64)]
    private ?string $promocode = null;

    /** For the per-buyer promocode limit; optional (not typed in yet). */
    #[Assert\Type('string')]
    private ?string $phone = null;

    /** card | invoice — an invoice is priced in hryvnia on /en too. */
    #[Assert\Choice(choices: ['card', 'invoice'])]
    private string $paymentMethod = 'card';

    public function getPromocode(): ?string
    {
        return $this->promocode;
    }

    public function setPromocode(?string $promocode): self
    {
        $this->promocode = null !== $promocode ? strtoupper(trim($promocode)) : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $digits = null !== $phone ? (string) preg_replace('/\D+/', '', $phone) : '';
        $this->phone = 1 === preg_match('/^380\d{9}$/', $digits) ? $digits : null;

        return $this;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function isInvoice(): bool
    {
        return 'invoice' === $this->paymentMethod;
    }
}
