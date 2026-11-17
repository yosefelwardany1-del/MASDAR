<?php

namespace App\Support\IO\Resources;

use App\Models\Lookup;
use App\Models\User;
use App\Support\IO\ResourceSchema;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A resource whose table has a writer (CLAUDE.md: one writer per table), so the generic upsert
 * may not touch it: rows go through ServiceImporter, which calls writeRow(). Rows are keyed by
 * the record's code. An empty code adds a new record, numbered by its writer; a code that is
 * not there is refused rather than created, because codes come from the numbering series and
 * a typed one would collide with the next number the series gives.
 */
abstract class ServiceBackedSchema extends ResourceSchema
{
    public function uniqueKey(): ?string
    {
        return 'code';
    }

    public function customImporter(): ?object
    {
        return new ServiceImporter($this);
    }

    /** The existing record with this code that $user may update, or null. */
    abstract public function findForImport(string $code, User $user): ?Model;

    /**
     * Write one row through the table's writer: create when $existing is null.
     *
     * @param  array<string, mixed>  $attrs  as toAttributes() returned them
     */
    abstract public function writeRow(array $attrs, ?Model $existing, User $user): Model;

    /**
     * Map a row to the writer's fields. Only the columns the file HAS are mapped, so a file
     * that leaves a column out leaves that field as it is on an update.
     *
     * @param  array<string, string>  $row  validated, trimmed, keyed by field key
     * @return array<string, mixed>
     */
    public function toAttributes(array $row): array
    {
        $attrs = [];
        foreach ($row as $key => $value) {
            if ($key === 'code') {
                continue;
            }
            $attrs = array_merge($attrs, $this->mapColumn($key, $value));
        }

        return $attrs;
    }

    /** @return array<string, mixed> the writer field(s) one column sets */
    abstract protected function mapColumn(string $key, string $value): array;

    /** A list entry by its code or its name in any language; empty is null. */
    protected function lookupCode(string $list, string $value, string $label): ?string
    {
        if ($value === '') {
            return null;
        }
        $match = Lookup::query()->where('list', $list)
            ->where(fn ($q) => $q->whereRaw('lower(code) = lower(?)', [$value])
                ->orWhereRaw('lower(name) = lower(?)', [$value])
                ->orWhere('name_ar', $value)->orWhere('name_zh', $value))
            ->value('code');

        return $match ?? throw new RuntimeException(__(':label ":value" is not on the list.', ['label' => $label, 'value' => $value]));
    }

    /** yes/no, 1/0, true/false; empty is null (keep as it is, or the default on create). */
    protected function flag(string $value): ?bool
    {
        if ($value === '') {
            return null;
        }

        return in_array(mb_strtolower($value), ['1', 'yes', 'y', 'true', 'active', 'نعم', '是'], true);
    }

    protected function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
