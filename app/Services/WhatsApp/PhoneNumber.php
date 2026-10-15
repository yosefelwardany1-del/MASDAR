<?php

namespace App\Services\WhatsApp;

/**
 * One way of writing a phone number, so a number typed as "010 1234 5678" on the
 * account page and "+20 10 1234 5678" on the sign-in page are the same number.
 *
 * E.164: a plus, the country code, the national number, no spaces. A number with no
 * country code is assumed to be from config('whatsapp.default_country_code') (20,
 * Egypt), with the trunk zero dropped — "0101…" becomes "+20101…". Arabic-Indic and
 * Persian digits typed on an Arabic keyboard are read as the digits they are.
 */
final class PhoneNumber
{
    /** U+0660–U+0669 (Arabic-Indic) and U+06F0–U+06F9 (Persian) → 0–9. */
    private const EASTERN_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function normalise(?string $raw): ?string
    {
        $raw = trim(strtr((string) $raw, self::EASTERN_DIGITS));
        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus) {
            $country = preg_replace('/\D+/', '', (string) config('whatsapp.default_country_code', '')) ?? '';
            if ($country === '') {
                return null;
            }
            // Already carries the country code (typed "2010…" without a plus).
            if (! str_starts_with($digits, $country) || strlen($digits) < strlen($country) + 8) {
                $digits = $country.ltrim($digits, '0');
            }
        }

        // 7 digits is the shortest real national number plus a code; 15 is E.164's cap.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /** "+962 ••• ••• 8883": enough to recognise your own number, not enough to dial it. */
    public static function masked(?string $e164): string
    {
        $e164 = (string) $e164;
        if (strlen($e164) < 8) {
            return '•••';
        }

        // The country code is one to three digits; showing four characters after the
        // plus covers all of them without exposing the number itself.
        return substr($e164, 0, 4).' ••• ••• '.substr($e164, -4);
    }
}
