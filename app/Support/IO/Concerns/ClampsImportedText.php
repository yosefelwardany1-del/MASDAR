<?php

namespace App\Support\IO\Concerns;

/**
 * Trims imported free text to the width of the column it is going into.
 *
 * External tools are far more permissive than these tables: Trello allows 16,384
 * characters for a card or check-item name, while the task-board title columns are
 * varchar(255). MySQL quietly truncates an over-long value, but Postgres rejects it
 * outright (SQLSTATE 22001) — which on production aborts the surrounding transaction and
 * takes the rest of the import with it. Clamping on the way in keeps that from ever
 * reaching the driver.
 *
 * Truncation loses the user's text, so it is counted rather than hidden: importers should
 * surface `truncatedCount()` in their summary.
 */
trait ClampsImportedText
{
    /** Default width of a Laravel `string()` column. */
    protected const MAX_COLUMN_TEXT = 255;

    private int $truncatedFields = 0;

    /** Trim to a column width without splitting a multi-byte character. */
    protected function clamp(string $value, int $max = self::MAX_COLUMN_TEXT): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        $this->truncatedFields++;

        return mb_substr($value, 0, $max);
    }

    /**
     * Clamp a value that doubles as a MATCH KEY — list and checklist titles are looked up
     * by name, so two distinct items sharing a 255-character prefix would collapse onto
     * one row. For checklists that is destructive (the first one's items are deleted and
     * replaced), so a short digest of the original keeps them apart.
     */
    protected function clampKey(string $value, int $max = self::MAX_COLUMN_TEXT): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        $this->truncatedFields++;

        return mb_substr($value, 0, $max - 9).'…'.substr(md5($value), 0, 8);
    }

    protected function resetTruncationCount(): void
    {
        $this->truncatedFields = 0;
    }

    protected function truncatedCount(): int
    {
        return $this->truncatedFields;
    }
}
