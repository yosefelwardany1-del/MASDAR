<?php

namespace App\Support\IO;

use App\Models\User;

/**
 * Exports a resource to CSV or XLSX. The query is the model's own query, narrowed by
 * the schema's scopeExportQuery(). Optional filters are applied by the caller.
 */
class ResourceExporter
{
    /** Build header + data rows for the export (narrowed by the schema's scope). */
    public function rows(ResourceSchema $schema, User $user, ?\Closure $filter = null): array
    {
        $modelClass = $schema->model();
        $query = $modelClass::query()->with($schema->exportWith());
        $query = $schema->scopeExportQuery($query, $user);

        if ($filter) {
            $filter($query);
        }

        $fieldKeys = array_map(fn ($f) => $f->key, $schema->fields());
        $rows = [$fieldKeys]; // header

        foreach ($query->cursor() as $model) {
            foreach ($schema->toExportRows($model) as $exportRow) {
                $rows[] = array_map(fn ($k) => $exportRow[$k] ?? null, $fieldKeys);
            }
        }

        return $rows;
    }

    public function toCsv(ResourceSchema $schema, User $user, ?\Closure $filter = null): string
    {
        return Csv::write($this->rows($schema, $user, $filter));
    }

    public function toXlsx(ResourceSchema $schema, User $user, ?\Closure $filter = null): string
    {
        return Spreadsheet::write($this->rows($schema, $user, $filter));
    }

    public function filename(ResourceSchema $schema, string $format = 'csv'): string
    {
        return $schema->key().'_export_'.now()->format('Ymd_His').'.'.$format;
    }
}
