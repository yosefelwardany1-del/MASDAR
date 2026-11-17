<?php

namespace App\Services\SupplierOrders;

/**
 * Shows a numeric(18,4) string without trailing zeros ("12.5000" → "12.5", "10.0000" → "10"),
 * and with thousands separators when asked. Strings in, strings out: never a float.
 */
final class Quantity
{
    public static function trim(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $value = (string) $value;
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' || $value === '-0' ? '0' : $value;
    }

    /** "1234567.5000" → "1,234,567.50" with $decimals places (money), from the string. */
    public static function money(string|int|null $value, int $decimals = 2): string
    {
        $value = bcadd((string) ($value ?? '0'), '0', $decimals);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$whole.($decimals > 0 ? '.'.$fraction : '');
    }
}
