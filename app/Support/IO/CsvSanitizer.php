<?php

namespace App\Support\IO;

/**
 * Neutralizes spreadsheet formula injection (a.k.a. CSV injection).
 *
 * When a CSV/XLSX cell value begins with =, +, -, @, TAB or CR, Excel/Google Sheets
 * interpret it as a formula on open — enabling data exfiltration (HYPERLINK/WEBSERVICE)
 * or command execution (DDE). A user-controlled field (customer name, description,
 * imported cell …) can therefore "detonate" later in a finance user's spreadsheet.
 *
 * Defense (OWASP): prefix a dangerous leading character with a single quote so the
 * value is rendered as literal text. Applied centrally at every export writer.
 */
class CsvSanitizer
{
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /** Neutralize a single cell value. Non-strings pass through unchanged. */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($value[0], self::DANGEROUS, true)) {
            return "'".$value;
        }

        return $value;
    }

    /** Neutralize a flat row of cells. */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
