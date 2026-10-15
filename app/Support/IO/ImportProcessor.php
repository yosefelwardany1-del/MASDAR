<?php

namespace App\Support\IO;

use Illuminate\Support\Facades\Validator;

/**
 * Validates and (optionally) persists an import. Row-level: each
 * row is validated, its relations resolved, and create-vs-update decided by the
 * schema's uniqueKey. Invalid rows are reported (row number + messages) and skipped;
 * valid rows still import. Run with $commit=false for a preview (no writes).
 *
 * It writes with the model's own create()/update(), so it serves only tables with no
 * designated writer — see ResourceSchema's class docblock.
 *
 * THIS CLASS KNOWS NOTHING ABOUT QUEUES. Large imports are asynchronous because
 * RunResourceImportJob wraps it, not because it learned to dispatch: the row
 * semantics — validate, resolve, upsert, report and keep going — are identical whether
 * a request or a worker is driving, and one test suite still covers both. The only
 * concession is the optional $onProgress callback, which lets a caller that CAN be
 * observed (a queued run writing to import_runs) report how far it has got. A caller
 * that cannot observe anything passes nothing and behaves exactly as before.
 */
class ImportProcessor
{
    /** Convenience: parse CSV content then process. */
    public function process(ResourceSchema $schema, string $csvContent, bool $commit, ?callable $onProgress = null): ImportResult
    {
        return $this->processRows($schema, Csv::parseAssoc($csvContent), $commit, $onProgress);
    }

    /**
     * Process already-parsed rows (header-keyed). Used for both CSV and XLSX.
     *
     * @param  callable(int $processed, int $total):void|null  $onProgress  called every
     *                                                                      config('io.progress_every') rows and once at the end. Optional, and never
     *                                                                      allowed to break the import: a progress reporter that throws (a lost
     *                                                                      database connection mid-run, say) must not destroy the work already done,
     *                                                                      so it is swallowed and reported. Progress is a courtesy; the import is not.
     */
    public function processRows(ResourceSchema $schema, array $rows, bool $commit, ?callable $onProgress = null): ImportResult
    {
        $result = new ImportResult(totalRows: count($rows));
        $schema->beginImport();

        $modelClass = $schema->model();
        $keys = $schema->uniqueKeys();           // model columns (post-resolution) for upsert/dedup
        $fieldKeys = array_map(fn ($f) => $f->key, $schema->fields());
        $seen = [];

        $total = count($rows);
        $processed = 0;
        $every = max(1, (int) config('io.progress_every', 25));

        foreach ($rows as $i => $raw) {
            $rowNum = $i + 2; // 1 = header

            // Counted BEFORE the row is judged, so a file of nothing but invalid rows
            // still advances the bar. "Processed" means looked at, not imported.
            $processed++;
            if ($processed % $every === 0) {
                $this->report($onProgress, $processed, $total);
            }

            $data = [];
            foreach ($fieldKeys as $k) {
                $data[$k] = array_key_exists($k, $raw) ? trim((string) $raw[$k]) : '';
            }

            $validator = Validator::make($data, $schema->rules());
            if ($validator->fails()) {
                $result->errors[] = ['row' => $rowNum, 'messages' => $validator->errors()->all()];

                continue;
            }

            try {
                $attrs = $schema->toAttributes($data);
            } catch (\RuntimeException $e) {
                $result->errors[] = ['row' => $rowNum, 'messages' => [$e->getMessage()]];

                continue;
            }

            // Resolve the (possibly composite) unique key from the RESOLVED attributes so
            // it works for foreign keys (e.g. employee_id, date) not just raw CSV columns.
            $existing = null;
            if (! empty($keys) && $this->keyComplete($attrs, $keys)) {
                $signature = $this->signature($attrs, $keys);
                if (isset($seen[$signature])) {
                    $result->errors[] = ['row' => $rowNum, 'messages' => ['Duplicate '.implode('+', $keys)." in file (first seen on row {$seen[$signature]})."]];

                    continue;
                }
                $seen[$signature] = $rowNum;

                $query = $modelClass::query();
                foreach ($keys as $k) {
                    $query->where($k, $attrs[$k]);
                }
                $existing = $query->first();
            }

            // Asked only when the generic lookup came back empty — see
            // ResourceSchema::resolveExisting(). A refusal here is a row-level error
            // like any other, so the rest of the file still imports.
            if ($existing === null) {
                try {
                    $existing = $schema->resolveExisting($attrs);
                } catch (\RuntimeException $e) {
                    $result->errors[] = ['row' => $rowNum, 'messages' => [$e->getMessage()]];

                    continue;
                }
            }

            // Notes for a person, not failures — see ResourceSchema::inspectRow(). Never
            // allowed to stop the row: a note that cannot be worked out is just not shown.
            try {
                $notes = $schema->inspectRow($attrs, $existing, $rowNum);
            } catch (\Throwable $e) {
                report($e);
                $notes = [];
            }
            if ($notes !== []) {
                $result->notices[] = ['row' => $rowNum, 'messages' => array_values($notes)];
            }

            if ($commit) {
                if ($existing) {
                    $existing->update($attrs);
                    $model = $existing;
                } else {
                    $model = $modelClass::create($attrs);
                }

                // Module-owned side effects (see ResourceSchema::afterPersist). Guarded so
                // one row's hook can't abort an import whose other rows are already
                // written — the same per-row error handling toAttributes() gets. The row
                // still counts as imported, because it WAS: reporting it as skipped would
                // send somebody looking for a record that exists.
                try {
                    $schema->afterPersist($model, $data, $existing === null);
                } catch (\Throwable $e) {
                    report($e);
                    $result->errors[] = ['row' => $rowNum, 'messages' => ['Imported, but a follow-up action failed: '.$e->getMessage()]];
                }
            }

            $existing ? $result->updated++ : $result->created++;
        }

        $this->report($onProgress, $processed, $total);

        return $result;
    }

    /**
     * Hand progress to the caller's reporter, never letting it derail the import.
     * See the $onProgress note on processRows().
     */
    private function report(?callable $onProgress, int $processed, int $total): void
    {
        if ($onProgress === null) {
            return;
        }

        try {
            $onProgress($processed, $total);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** True when every unique-key column is present and non-empty in the resolved attributes. */
    private function keyComplete(array $attrs, array $keys): bool
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $attrs) || $attrs[$k] === null || $attrs[$k] === '') {
                return false;
            }
        }

        return true;
    }

    private function signature(array $attrs, array $keys): string
    {
        return implode('|', array_map(fn ($k) => (string) $attrs[$k], $keys));
    }
}
