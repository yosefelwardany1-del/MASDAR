<?php

namespace App\Support\IO;

/** Outcome of an import (preview or commit). */
class ImportResult
{
    /**
     * @param  array<int,array{row:int,messages:array<string>}>  $errors
     * @param  array<int,array{row:int,messages:array<string>}>  $notices  row-level notes that
     *                                                                     are NOT failures (a row that updated an existing record, a possible duplicate
     *                                                                     it flagged) — reported beside the errors, never counted as one
     */
    public function __construct(
        public int $totalRows = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public array $errors = [],
        public array $notices = [],
    ) {}

    public function failed(): int
    {
        return count($this->errors);
    }

    /** B33: "1 new, 2 updates, 1 error (4 rows)" — each count in its singular or plural, in the reader's language. */
    public static function summary(int $created, int $updated, int $failed, int $total): string
    {
        return __(':created, :updated, :failed (:total)', [
            'created' => trans_choice('{1} :count new|[0,*] :count new', $created, ['count' => $created]),
            'updated' => trans_choice('{1} :count update|[0,*] :count updates', $updated, ['count' => $updated]),
            'failed' => trans_choice('{1} :count error|[0,*] :count errors', $failed, ['count' => $failed]),
            'total' => trans_choice('{1} :count row|[0,*] :count rows', $total, ['count' => $total]),
        ]);
    }

    public function toArray(): array
    {
        return [
            'summary' => self::summary($this->created, $this->updated, $this->failed(), $this->totalRows),
            'total_rows' => $this->totalRows,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed(),
            'errors' => $this->errors,
            'notices' => $this->notices,
        ];
    }
}
