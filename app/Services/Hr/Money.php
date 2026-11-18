<?php

namespace App\Services\Hr;

use Illuminate\Validation\ValidationException;

/** Decimal input for HR amounts and days: strings in, normalised strings out (never floats). */
final class Money
{
    /** An amount ≥ 0 with up to 4 decimals (or $max decimals), or a validation error on $field. */
    public static function amount(mixed $value, string $field, bool $positive = false, int $decimals = 4): string
    {
        $value = trim(str_replace([',', ' '], '', (string) ($value ?? '')));
        if ($value === '') {
            $value = '0';
        }
        if (! preg_match('/^\d{1,14}(\.\d{1,'.$decimals.'})?$/', $value)) {
            throw ValidationException::withMessages([$field => __('Enter a number of up to :n decimals, without a minus sign.', ['n' => $decimals])]);
        }
        if ($positive && bccomp($value, '0', $decimals) <= 0) {
            throw ValidationException::withMessages([$field => __('Enter an amount above zero.')]);
        }

        return bcadd($value, '0', $decimals);
    }

    /** $a × $b rounded half up to 2 decimals. */
    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 8));
    }

    /** $a ÷ $b rounded half up to 2 decimals ($b > 0). */
    public static function div(string $a, string $b): string
    {
        return self::round(bcdiv($a, $b, 8));
    }

    public static function round(string $value, int $places = 2): string
    {
        $shift = '0.'.str_repeat('0', $places).'5';

        return bccomp($value, '0', 8) >= 0 ? bcadd($value, $shift, $places) : bcsub($value, $shift, $places);
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 8) <= 0 ? $a : $b;
    }

    public static function max(string $a, string $b): string
    {
        return bccomp($a, $b, 8) >= 0 ? $a : $b;
    }
}
