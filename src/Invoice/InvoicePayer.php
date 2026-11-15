<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * «Платник» block of a «Рахунок на оплату», entered by the buyer at checkout.
 * Stored as a JSON snapshot on the order (and optionally on the user, to prefill).
 */
final class InvoicePayer
{
    public function __construct(
        public readonly string $name,
        public readonly string $taxId,
        public readonly string $address,
        public readonly string $contactPerson,
        public readonly string $phone,
        public readonly string $email,
        public readonly bool $vatPayer = false,
        public readonly ?string $vatIpn = null,
        public readonly ?string $iban = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $str = static fn (string $key): string => trim((string) ($data[$key] ?? ''));
        $vatPayer = filter_var($data['vat_payer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $iban = UaRequisitesValidator::normalizeIban($str('iban'));

        return new self(
            name: (string) preg_replace('/\s+/u', ' ', $str('name')),
            taxId: (string) preg_replace('/\D+/', '', $str('tax_id')),
            address: (string) preg_replace('/\s+/u', ' ', $str('address')),
            contactPerson: (string) preg_replace('/\s+/u', ' ', $str('contact_person')),
            phone: self::normalizePhone($str('phone')),
            email: mb_strtolower($str('email')),
            vatPayer: $vatPayer,
            vatIpn: $vatPayer ? ((string) preg_replace('/\D+/', '', $str('vat_ipn')) ?: null) : null,
            iban: $iban !== '' ? $iban : null,
        );
    }

    /**
     * Field => Ukrainian error message. Empty array means valid.
     *
     * @return array<string, string>
     */
    public function validate(): array
    {
        $errors = [];

        $nameLength = mb_strlen($this->name);
        if ($nameLength < 2 || $nameLength > 255) {
            $errors['name'] = 'Вкажіть назву юрособи або ФОП (наприклад, ОСББ «Сіті Парк»).';
        }

        if (strlen($this->taxId) === 8) {
            if (!UaRequisitesValidator::isValidEdrpou($this->taxId)) {
                $errors['tax_id'] = 'Код ЄДРПОУ не проходить перевірку. Перевірте цифри.';
            }
        } elseif (strlen($this->taxId) === 10) {
            if (!UaRequisitesValidator::isValidRnokpp($this->taxId)) {
                $errors['tax_id'] = 'ІПН (РНОКПП) ФОП не проходить перевірку. Перевірте цифри.';
            }
        } else {
            $errors['tax_id'] = 'Вкажіть ЄДРПОУ (8 цифр) або ІПН ФОП (10 цифр).';
        }

        $addressLength = mb_strlen($this->address);
        if ($addressLength < 5 || $addressLength > 500) {
            $errors['address'] = 'Вкажіть юридичну адресу.';
        }

        $contactLength = mb_strlen($this->contactPerson);
        if ($contactLength < 2 || $contactLength > 255) {
            $errors['contact_person'] = 'Вкажіть контактну особу.';
        }

        if (!preg_match('/^380\d{9}$/', $this->phone)) {
            $errors['phone'] = 'Вкажіть телефон у форматі +380XXXXXXXXX.';
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL) || mb_strlen($this->email) > 180) {
            $errors['email'] = 'Вкажіть коректний email — на нього надійде рахунок.';
        }

        if ($this->vatPayer && !UaRequisitesValidator::isValidVatIpn((string) $this->vatIpn)) {
            $errors['vat_ipn'] = 'ІПН платника ПДВ — 12 цифр.';
        }

        if ($this->iban !== null && !UaRequisitesValidator::isValidUaIban($this->iban)) {
            $errors['iban'] = 'IBAN має бути українським: UA + 27 цифр.';
        }

        return $errors;
    }

    public function isFop(): bool
    {
        return strlen($this->taxId) === 10;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tax_id' => $this->taxId,
            'address' => $this->address,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'email' => $this->email,
            'vat_payer' => $this->vatPayer,
            'vat_ipn' => $this->vatIpn,
            'iban' => $this->iban,
        ];
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '38' . $digits;
        }

        return $digits;
    }
}
