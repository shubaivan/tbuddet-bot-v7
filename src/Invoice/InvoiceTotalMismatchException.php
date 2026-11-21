<?php

declare(strict_types=1);

namespace App\Invoice;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The order total is not what the invoice lines add up to (e.g. a total in USD
 * next to hryvnia lines). Never issue such an invoice: it would be paid — and
 * charged by «Оплатити онлайн» — for a different sum than it shows. The buyer
 * gets a 422 with a plain message; the numbers go to the log.
 */
class InvoiceTotalMismatchException extends UnprocessableEntityHttpException
{
    public const USER_MESSAGE = 'Не вдалося сформувати рахунок: сума не збігається. Оберіть оплату карткою або зверніться до менеджера.';

    public function __construct(public readonly string $details)
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
