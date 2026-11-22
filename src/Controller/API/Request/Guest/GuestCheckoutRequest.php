<?php

declare(strict_types=1);

namespace App\Controller\API\Request\Guest;

use App\Controller\API\Request\Purchase\CartCheckoutPayload;
use App\Controller\API\Request\Purchase\CartDeliveryFields;
use App\Controller\API\Request\Purchase\InvoicePaymentFields;
use App\Validator\UserLanguage;
use Symfony\Component\Validator\Constraints as Assert;

/** One-page checkout of a guest cart: contacts, delivery, payment, promocode. */
#[UserLanguage]
class GuestCheckoutRequest implements CartCheckoutPayload
{
    use GuestCartItems;
    use CartDeliveryFields;
    use InvoicePaymentFields;

    /** 380XXXXXXXXX — the same format as the guest quick buy. */
    #[Assert\NotBlank(message: 'Вкажіть телефон.')]
    #[Assert\Regex(pattern: '/^380\d{9}$/', message: 'Телефон у форматі +380 XX XXX XX XX.')]
    private ?string $phone = null;

    #[Assert\NotBlank(message: 'Вкажіть імʼя.')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Вкажіть імʼя.', maxMessage: 'Імʼя задовге (до {{ limit }} символів).')]
    private ?string $name = null;

    #[Assert\Email(message: 'Перевірте email.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Honeypot: hidden from people, bots fill it in. */
    private ?string $website = null;

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = null !== $phone ? (string) preg_replace('/\D+/', '', $phone) : null;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = null !== $name ? trim((string) preg_replace('/\s+/u', ' ', $name)) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $email = null !== $email ? mb_strtolower(trim($email)) : null;
        $this->email = '' === $email ? null : $email;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }
}
