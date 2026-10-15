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

    public function toArray(): array
    {
        return [
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
