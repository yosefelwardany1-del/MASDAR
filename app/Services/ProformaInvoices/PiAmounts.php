<?php

namespace App\Services\ProformaInvoices;

/**
 * Arithmetic and display of PI money and quantities on numeric(18,4) strings, with bcmath —
 * never through a PHP float (CLAUDE.md). Rounding is half away from zero.
 */
final class PiAmounts
{
    /** $value rounded to $scale decimals, e.g. round('75.60005', 4) = '75.6001'. */
    public static function round(string $value, int $scale = 4): string
    {
        $value = self::normalise($value);
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 2) < 0
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    /** What the client pays before production: total × deposit % ÷ 100, to the cent (B09). */
    public static function depositRequired(string $total, string $depositPercent): string
    {
        return bcadd(self::round(bcdiv(bcmul(self::normalise($total), self::normalise($depositPercent), 8), '100', 8), 2), '0', 4);
    }

    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::normalise($a), self::normalise($b), 4);
    }

    /**
     * A number with thousands separators and between $min and $max decimals: trailing zeros
     * beyond $min are dropped, more than $max are rounded. number('1234.5000', 2) = '1,234.50'.
     */
    public static function number(?string $value, int $min = 0, int $max = 4): string
    {
        $value = self::normalise((string) ($value ?? '0'));
        [, $frac] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($frac) > $max) {
            $value = self::round($value, $max);
        }
        $negative = str_starts_with($value, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        $frac = str_pad(rtrim($frac, '0'), $min, '0');
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($int, '0') ?: '0');
        $out = $frac === '' ? $int : "{$int}.{$frac}";

        return $negative && preg_match('/[1-9]/', $out) ? "-{$out}" : $out;
    }

    /** A value for a form field: "12.5000" gives "12.5", null stays null. */
    public static function plain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $value = self::normalise($value);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** An amount with its currency: USD as "$1,234.50", any other currency as "CNY 1,234.50". */
    public static function money(?string $amount, string $currency): string
    {
        $number = self::number($amount, 2, 4);
        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '-');

        $text = strtoupper($currency) === 'USD' ? '$'.$number : strtoupper($currency).' '.$number;

        return $negative ? "-{$text}" : $text;
    }

    /** Accepts "12", "12.5", "-3.25", " 7 " and gives what bcmath expects. */
    private static function normalise(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException("Not a decimal number: [{$value}].");
        }
        $int = $m[2] === '' ? '0' : $m[2];
        $frac = $m[3] ?? '';

        return ($m[1] === '-' ? '-' : '').$int.($frac === '' ? '' : ".{$frac}");
    }
}
