<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * Seller requisites printed on every «Рахунок на оплату».
 *
 * ONE place to fill them: the INVOICE_SELLER_* / INVOICE_PAYMENT_TERM_DAYS
 * variables (defaults in .env, real values in .env.local on prod, then
 * `composer dump-env prod`). Until every field is present and valid, the
 * invoice option is hidden at checkout and the PDF refuses to render: a
 * customer must never receive placeholder requisites.
 */
final class SellerRequisites
{
    public const VAT_PAYER = 'payer';
    public const VAT_NON_PAYER = 'non_payer';

    /** Values that mean "not filled in yet". */
    private const PLACEHOLDERS = ['', '!changeme!', 'changeme', 'todo', 'xxx', '__fill_me__', 'заповнити'];

    public function __construct(
        private readonly string $name,
        private readonly string $edrpou,
        private readonly string $iban,
        private readonly string $bank,
        private readonly string $mfo,
        private readonly string $vatStatus,
        private readonly string $vatIpn,
        private readonly string $signatory,
        private readonly string $phone,
        private readonly string $email,
        private readonly string $address,
        private readonly string $paymentTermDays,
        private readonly string $numberPrefix = 'ABM',
        private readonly string $signatoryPosition = '',
    ) {
    }

    /**
     * Human-readable list of what is missing or invalid (Ukrainian, for the admin).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $required = [
            'INVOICE_SELLER_NAME (назва продавця)' => $this->name,
            'INVOICE_SELLER_EDRPOU (ЄДРПОУ / РНОКПП)' => $this->edrpou,
            'INVOICE_SELLER_IBAN (IBAN)' => $this->iban,
            'INVOICE_SELLER_BANK (банк)' => $this->bank,
            'INVOICE_SELLER_MFO (МФО)' => $this->mfo,
            'INVOICE_SELLER_VAT_STATUS (платник ПДВ: payer / non_payer)' => $this->vatStatus,
            'INVOICE_SELLER_SIGNATORY (хто підписує)' => $this->signatory,
            'INVOICE_SELLER_PHONE (телефон)' => $this->phone,
            'INVOICE_SELLER_EMAIL (email)' => $this->email,
            'INVOICE_SELLER_ADDRESS (адреса)' => $this->address,
            'INVOICE_PAYMENT_TERM_DAYS (строк оплати, банківських днів)' => $this->paymentTermDays,
        ];
        foreach ($required as $label => $value) {
            if (self::isPlaceholder($value)) {
                $problems[] = 'не заповнено ' . $label;
            }
        }

        if (!self::isPlaceholder($this->edrpou)
            && !UaRequisitesValidator::isValidEdrpou($this->edrpou)
            && !UaRequisitesValidator::isValidRnokpp($this->edrpou)
        ) {
            $problems[] = 'INVOICE_SELLER_EDRPOU не проходить перевірку контрольної суми';
        }
        if (!self::isPlaceholder($this->iban) && !UaRequisitesValidator::isValidUaIban($this->iban)) {
            $problems[] = 'INVOICE_SELLER_IBAN не є коректним українським IBAN';
        }
        if (!self::isPlaceholder($this->mfo) && !preg_match('/^\d{6}$/', $this->mfo)) {
            $problems[] = 'INVOICE_SELLER_MFO має містити 6 цифр';
        }
        if (!self::isPlaceholder($this->mfo) && UaRequisitesValidator::isValidUaIban($this->iban)
            && substr(UaRequisitesValidator::normalizeIban($this->iban), 4, 6) !== $this->mfo
        ) {
            $problems[] = 'INVOICE_SELLER_MFO не збігається з МФО у складі IBAN';
        }
        if (!self::isPlaceholder($this->vatStatus)
            && !in_array($this->vatStatus, [self::VAT_PAYER, self::VAT_NON_PAYER], true)
        ) {
            $problems[] = 'INVOICE_SELLER_VAT_STATUS має бути payer або non_payer';
        }
        if ($this->vatStatus === self::VAT_PAYER && !UaRequisitesValidator::isValidVatIpn($this->vatIpn)) {
            $problems[] = 'для платника ПДВ потрібен INVOICE_SELLER_VAT_IPN (12 цифр)';
        }
        if (!self::isPlaceholder($this->email) && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'INVOICE_SELLER_EMAIL некоректний';
        }
        if (!self::isPlaceholder($this->paymentTermDays)
            && (!ctype_digit($this->paymentTermDays) || (int) $this->paymentTermDays < 1 || (int) $this->paymentTermDays > 60)
        ) {
            $problems[] = 'INVOICE_PAYMENT_TERM_DAYS має бути числом від 1 до 60';
        }

        return $problems;
    }

    public function isComplete(): bool
    {
        return $this->problems() === [];
    }

    /** @throws InvoiceRequisitesMissingException */
    public function assertComplete(): void
    {
        $problems = $this->problems();
        if ($problems !== []) {
            throw new InvoiceRequisitesMissingException($problems);
        }
    }

    public function name(): string { return $this->name; }
    public function edrpou(): string { return $this->edrpou; }
    public function iban(): string { return UaRequisitesValidator::normalizeIban($this->iban); }
    public function bank(): string { return $this->bank; }
    public function mfo(): string { return $this->mfo; }
    public function isVatPayer(): bool { return $this->vatStatus === self::VAT_PAYER; }
    public function vatIpn(): string { return $this->vatIpn; }
    public function signatory(): string { return $this->signatory; }
    /** Optional, e.g. «Директор»; empty → only the name is printed. */
    public function signatoryPosition(): string { return trim($this->signatoryPosition); }
    public function phone(): string { return $this->phone; }
    public function email(): string { return $this->email; }
    public function address(): string { return $this->address; }
    /** Payment term in BANK (working) days, Mon–Fri. */
    public function paymentTermDays(): int { return (int) $this->paymentTermDays; }

    /** «3 банківські дні», «5 банківських днів», «1 банківський день». */
    public function paymentTermLabel(): string
    {
        $n = $this->paymentTermDays();
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $n . ' банківський день';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $n . ' банківські дні';
        }

        return $n . ' банківських днів';
    }
    public function numberPrefix(): string { return $this->numberPrefix !== '' ? $this->numberPrefix : 'ABM'; }

    /** Dummy requisites for the «ЗРАЗОК» sample PDF only. Never used for a real order. */
    public static function sample(): self
    {
        return new self(
            name: 'ТОВ «ЗРАЗОК ПРОДАВЦЯ»',
            edrpou: '00032129',
            iban: 'UA213223130000026007233566001',
            bank: 'АТ «Банк-Зразок»',
            mfo: '322313',
            vatStatus: self::VAT_PAYER,
            vatIpn: '000000000000',
            signatory: 'Зразковий З. З.',
            phone: '+380 00 000 00 00',
            email: 'sample@example.com',
            address: '00000, м. Зразкове, вул. Зразкова, 1',
            paymentTermDays: '5',
            signatoryPosition: 'Директор',
        );
    }

    private static function isPlaceholder(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::PLACEHOLDERS, true);
    }
}
