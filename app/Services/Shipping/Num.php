<?php

namespace App\Services\Shipping;

/**
 * Shows a numeric(18,4) string as people read it — "1,234.5" for "1234.5000" — without ever
 * turning it into a PHP float. Rounding, where wanted, is done in SQL before it gets here.
 */
final class Num
{
    public static function show(mixed $value, string $empty = '0'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        $value = trim((string) $value);
        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $value, $m)) {
            return $value;
        }
        [, $sign, $whole, $fraction] = $m + [3 => ''];
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));
        $sign = ($whole === '0' && $fraction === '') ? '' : $sign;

        return $sign.$grouped.($fraction !== '' ? '.'.$fraction : '');
    }

    /** "10.5000" → "10.5", "100" → "100": for a form field (no thousands separators). */
    public static function plain(mixed $value): string
    {
        $value = trim((string) $value);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** True when $value is a plain decimal greater than zero ("3", "2.5"; not "0", "-1", "1e3"). */
    public static function isPositive(mixed $value): bool
    {
        $value = trim((string) $value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && preg_match('/[1-9]/', $value) === 1;
    }
}
