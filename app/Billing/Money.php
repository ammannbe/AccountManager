<?php

namespace App\Billing;

/**
 * Amounts in cents as shown on invoices and in the portal (Swiss format: CHF 1'234.50).
 */
final class Money
{
    public static function format(int $cents, string $currency): string
    {
        return strtoupper($currency).' '.self::amount($cents);
    }

    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', "'");
    }
}
