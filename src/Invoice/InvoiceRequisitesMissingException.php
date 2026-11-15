<?php

declare(strict_types=1);

namespace App\Invoice;

final class InvoiceRequisitesMissingException extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(private readonly array $problems)
    {
        parent::__construct(
            'Рахунок не сформовано: реквізити продавця не заповнені або некоректні — '
            . implode('; ', $problems)
            . '. Заповніть INVOICE_* у .env.local і виконайте composer dump-env prod.'
        );
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }
}
