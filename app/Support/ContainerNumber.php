<?php

namespace App\Support;

/**
 * B28: a container number as ISO 6346 has it — owner code (3 letters), category (U, J or Z),
 * 6-digit serial and a check digit computed from the other ten characters.
 */
final class ContainerNumber
{
    public static function isValid(string $number): bool
    {
        if (! preg_match('/^[A-Z]{3}[UJZ]\d{7}$/', $number)) {
            return false;
        }

        return self::checkDigit(substr($number, 0, 10)) === (int) $number[10];
    }

    public static function checkDigit(string $first10): int
    {
        $values = [];
        $v = 10;
        foreach (range('A', 'Z') as $letter) {
            if ($v % 11 === 0) {
                $v++;
            }
            $values[$letter] = $v++;
        }
        $sum = 0;
        foreach (str_split($first10) as $i => $char) {
            $sum += (ctype_alpha($char) ? $values[$char] : (int) $char) * (2 ** $i);
        }

        return $sum % 11 % 10;
    }
}
