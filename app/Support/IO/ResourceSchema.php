<?php

namespace App\Support\IO;

use App\Models\User;
use App\Security\Screens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A module's single source of truth for bulk import/export: the columns (template +
 * export + import mapping), validation rules, relationship resolution, and how a row
 * maps to/from a model. Every module reuses the same framework by extending this — no
 * module-specific importer/exporter classes.
 *
 * ONE WRITER PER TABLE (CLAUDE.md). The generic ImportProcessor writes with the model's
 * own create()/update(), so it may only serve a table that has no designated writer
 * (reference data). A resource whose table HAS a writer — stock, ledger, documents —
 * returns a customImporter() that goes through that writer, never the generic upsert.
 */
abstract class ResourceSchema
{
    /** Registry key + URL segment, e.g. "titles". */
    abstract public function key(): string;

    /** Human label, e.g. "Titles". */
    abstract public function label(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** The screen (config/screens.php) whose permissions gate this resource. */
    abstract public function permissionModule(): string;

    /**
     * The permission that gates an import/export verb: 'view' (template, export,
     * listing) needs `{screen}.view`; anything that writes (preview, import) needs
     * `{screen}.edit`. A preview writes nothing, but it is the first half of an import.
     */
    public function permissionFor(string $verb): string
    {
        return Screens::permission($this->permissionModule(), $verb === 'view' ? 'view' : 'edit');
    }

    /** @return Field[] ordered columns */
    abstract public function fields(): array;

    /** Column key used to detect create-vs-update (upsert), or null for create-only. */
    abstract public function uniqueKey(): ?string;

    /**
     * Model columns (post-resolution) that together identify an existing record for
     * upsert + in-file duplicate detection. Defaults to the single uniqueKey; override
     * to return a composite key (e.g. ['edition_id','location_id'] for an opening count).
     *
     * @return array<string>
     */
    public function uniqueKeys(): array
    {
        return $this->uniqueKey() ? [$this->uniqueKey()] : [];
    }

    /**
     * Last word on identity, asked ONLY when the generic unique-key lookup found nothing
     * — because the key was incomplete, or because it matched no visible row.
     *
     * Return a model to update it, null to create it, or throw a RuntimeException to
     * REFUSE the row (reported like any other row-level error, leaving the rest of the
     * file to import). Default: null, which is exactly today's behaviour, so no existing
     * schema changes by this hook existing.
     *
     * It exists because "no row matched" and "no row may be created" are different
     * answers that the generic lookup cannot tell apart. A model with SoftDeletes hides
     * trashed rows from that lookup while the database's unique index still counts them,
     * so the create it would otherwise fall through to is an integrity violation —
     * thrown from create(), outside the per-row guards, taking the whole import with it.
     * A schema that knows its own soft-delete rules answers here instead.
     *
     * @param  array<string,mixed>  $attrs  resolved model attributes, as toAttributes() returned them
     */
    public function resolveExisting(array $attrs): ?Model
    {
        return null;
    }

    /** Laravel validation rules keyed by field key, for a single row. */
    abstract public function rules(): array;

    /**
     * Map a validated CSV row to model attributes, resolving relations (e.g. a
     * warehouse name → id). Throw \RuntimeException(<message>) for an unresolvable
     * reference so the row is reported as a row-level error.
     */
    abstract public function toAttributes(array $row): array;

    /** Map a model to an export row keyed by field key. */
    abstract public function toExportRow(Model $model): array;

    /**
     * Export rows for a model — one by default, but a multi-line document (e.g. an
     * invoice with its lines) overrides this to emit several rows per model.
     *
     * @return array<int,array<string,mixed>>
     */
    public function toExportRows(Model $model): array
    {
        return [$this->toExportRow($model)];
    }

    /**
     * The importer for a table the generic per-row upsert must not write (see the class
     * docblock): multi-line documents, anything with a designated writer. Must expose
     * import(array $rows, bool $commit): ImportResult. Null = the generic ImportProcessor.
     */
    public function customImporter(): ?object
    {
        return null;
    }

    /**
     * A JSON-file importer for resources whose richest source isn't a flat CSV/XLSX.
     * When set and a .json file is uploaded, the controller decodes it and calls
     * import(array $json, bool $commit): ImportResult instead of the row pipeline.
     * Null = JSON not accepted.
     */
    public function jsonImporter(): ?object
    {
        return null;
    }

    /** File extensions the import upload accepts (drives the file picker + validation). */
    public function uploadAccept(): string
    {
        return '.csv,.txt,.xlsx,.xls';
    }

    /** Eager loads for export. @return array<string> */
    public function exportWith(): array
    {
        return [];
    }

    /**
     * Resolve a related record id by a column (e.g. a warehouse name → id). Throws so
     * the row is reported as a row-level error.
     */
    protected function requireByColumn(string $modelClass, string $column, string $value, string $label): int
    {
        $id = $modelClass::where($column, $value)->value('id');
        if (! $id) {
            throw new \RuntimeException("{$label} '{$value}' not found.");
        }

        return (int) $id;
    }

    /** Resolve a User id by email, for owner assignment. */
    protected function requireUserByEmail(string $email, string $label): int
    {
        $id = User::where('email', $email)->value('id');
        if (! $id) {
            throw new \RuntimeException("{$label} with email '{$email}' not found.");
        }

        return (int) $id;
    }

    /**
     * Module-owned side effects for a row that has just been written, when (and only
     * when) the import is committing. Default: nothing.
     *
     * The generic pipeline knows how to map a row onto columns; it does not know what a
     * COLUMN CHANGE means to the module that owns it. Putting that in the schema keeps
     * the rule with the module instead of teaching this shared processor about one
     * resource's domain.
     *
     * Runs per row, after the create/update, inside the same loop — so it sees a
     * persisted model with its id. Throwing is reported as a row-level error and does
     * not abort the rest of the import.
     *
     * `$row` is the VALIDATED row keyed by field key, which is how a schema reads a
     * column that is not a model attribute at all: toAttributes() never sees it, so it
     * must arrive here instead.
     *
     * @param  array<string,string>  $row  validated row, keyed by field key
     * @param  bool  $created  true on insert, false on upsert-update
     */
    public function afterPersist(Model $model, array $row, bool $created): void {}

    /**
     * Called once before the first row of a run (preview or commit). A schema that keeps
     * per-file state for inspectRow() resets it here. Default: nothing.
     */
    public function beginImport(): void {}

    /**
     * Notes a person should see about a VALID row — in the preview and the real run
     * alike — that are NOT errors: "this row updates title #212", "possible duplicate
     * of …". Returned strings are reported as notices beside the row number; the row
     * imports exactly as it would have. Called after the existing record (if any) is
     * resolved and before anything is written. Default: none, so no schema changes by
     * this hook existing.
     *
     * @param  array<string,mixed>  $attrs  resolved model attributes, as toAttributes() returned them
     * @param  Model|null  $existing  the record this row will UPDATE, or null when it creates
     * @return list<string>
     */
    public function inspectRow(array $attrs, ?Model $existing, int $rowNum): array
    {
        return [];
    }

    /**
     * Narrow an export query to what $user may read. Default: every row — the screen
     * permission already decided the user may read this resource; override where a
     * module hides some rows (or cost columns, spec I10) from some roles.
     */
    public function scopeExportQuery(Builder $query, User $user): Builder
    {
        return $query;
    }
}
