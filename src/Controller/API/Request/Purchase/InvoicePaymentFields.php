<?php

declare(strict_types=1);

namespace App\Controller\API\Request\Purchase;

use App\Entity\Enum\PaymentMethodEnum;
use App\Invoice\InvoicePayer;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * «Оплатити карткою / Отримати рахунок» — shared by the cart checkout and both
 * quick-buy requests (logged-in and guest), so every entry point validates the
 * payer the same way.
 */
trait InvoicePaymentFields
{
    /** card (LiqPay, default) | invoice («Рахунок на оплату» for legal entities). */
    #[Choice(choices: ['card', 'invoice'])]
    private string $paymentMethod = 'card';

    /** Payer requisites, required for paymentMethod=invoice. Shape: see InvoicePayer::fromArray(). */
    #[Type('array')]
    private ?array $payer = null;

    /** Save the payer requisites on the account to prefill the next invoice (logged-in only). */
    #[Type('bool')]
    private bool $rememberPayer = false;

    #[Callback]
    public function validatePayer(ExecutionContextInterface $context): void
    {
        if (!$this->isInvoice()) {
            return;
        }

        foreach (InvoicePayer::fromArray($this->payer ?? [])->validate() as $field => $message) {
            $context->buildViolation($message)->atPath('payer.' . $field)->addViolation();
        }
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function isInvoice(): bool
    {
        return $this->paymentMethod === PaymentMethodEnum::INVOICE->value;
    }

    public function getPayer(): ?array
    {
        return $this->payer;
    }

    public function setPayer(?array $payer): static
    {
        $this->payer = $payer;

        return $this;
    }

    public function isRememberPayer(): bool
    {
        return $this->rememberPayer;
    }

    public function setRememberPayer(bool $rememberPayer): static
    {
        $this->rememberPayer = $rememberPayer;

        return $this;
    }
}
