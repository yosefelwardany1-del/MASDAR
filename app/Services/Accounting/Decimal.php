<?php

namespace App\Services\Accounting;

/**
 * Exact decimal arithmetic on numeric strings (bcmath), for money (scale 4) and rates (scale 6).
 * Rounding is half away from zero, the same as PostgreSQL's round(numeric), so a book amount
 * computed here equals one computed by a generated column. Never a PHP float.
 */
final class Decimal
{
    public static function normalize(string|int|null $value, int $scale = 4): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '' || ! preg_match('/^-?\d*(\.\d*)?$/', $value)) {
            throw new \InvalidArgumentException("Not a decimal number: [{$value}].");
        }
        if (str_starts_with($value, '.') || str_starts_with($value, '-.')) {
            $value = str_replace('.', '0.', $value);
        }

        return self::clean(bcadd($value, '0', $scale));
    }

    /** Round half away from zero to $scale decimals. */
    public static function round(string|int|null $value, int $scale = 4): string
    {
        $value = self::normalize($value, $scale + 8);
        $half = '0.'.str_repeat('0', $scale).'5';

        return self::clean(str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale));
    }

    public static function add(string|int|null $a, string|int|null $b, int $scale = 4): string
    {
        return self::clean(bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale));
    }

    public static function sub(string|int|null $a, string|int|null $b, int $scale = 4): string
    {
        return self::clean(bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale));
    }

    /** $a × $b rounded to $scale (the amount × rate of a line in the book currency). */
    public static function mul(string|int|null $a, string|int|null $b, int $scale = 4): string
    {
        return self::round(bcmul(self::normalize($a, 8), self::normalize($b, 8), 16), $scale);
    }

    public static function neg(string|int|null $a, int $scale = 4): string
    {
        return self::sub('0', $a, $scale);
    }

    public static function cmp(string|int|null $a, string|int|null $b, int $scale = 4): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function isZero(string|int|null $a, int $scale = 4): bool
    {
        return self::cmp($a, '0', $scale) === 0;
    }

    public static function isPositive(string|int|null $a, int $scale = 4): bool
    {
        return self::cmp($a, '0', $scale) > 0;
    }

    /** @param iterable<string|int|null> $values */
    public static function sum(iterable $values, int $scale = 4): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = self::add($total, $value, $scale);
        }

        return $total;
    }

    public static function abs(string|int|null $a, int $scale = 4): string
    {
        $a = self::normalize($a, $scale);

        return str_starts_with($a, '-') ? substr($a, 1) : $a;
    }

    /** "1234567.8912" → "1,234,567.89" for display (storage keeps 4 decimals). */
    public static function format(string|int|null $value, int $decimals = 2): string
    {
        $value = self::round($value ?? '0', $decimals);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$whole.($decimals > 0 ? '.'.str_pad($fraction, $decimals, '0') : '');
    }

    /** "-0.0000" is "0.0000". */
    private static function clean(string $value): string
    {
        return preg_match('/^-0(\.0*)?$/', $value) ? ltrim($value, '-') : $value;
    }
}
