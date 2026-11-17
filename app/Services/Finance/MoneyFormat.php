<?php

namespace App\Services\Finance;

/**
 * Shows numeric(18,4) money strings without going through a float: thousands separators, at
 * least two decimals, and the third and fourth only when they are not zero ("1,250.50",
 * "-12.125"). Display only; sums stay in SQL.
 */
final class MoneyFormat
{
    public static function amount(string|int|null $value, int $minDecimals = 2): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) < $minDecimals) {
            $fraction = str_pad($fraction, $minDecimals, '0');
        }
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));
        $isZero = trim($whole.$fraction, '0') === '';

        return ($negative && ! $isZero ? '-' : '').$grouped.($fraction !== '' ? '.'.$fraction : '');
    }

    /** A rate as stored, without trailing zeros ("7.1", "0.138889"). */
    public static function rate(string|int|null $value): string
    {
        return self::amount($value, 0);
    }
}
