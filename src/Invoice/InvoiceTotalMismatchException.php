<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * The order total is not what the invoice lines add up to (e.g. a total in USD
 * next to hryvnia lines). Never issue such an invoice: it would be paid — and
 * charged by «Оплатити онлайн» — for a different sum than it shows.
 */
class InvoiceTotalMismatchException extends \LogicException
{
}
