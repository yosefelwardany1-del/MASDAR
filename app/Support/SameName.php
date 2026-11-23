<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * B23: two names are the same when they differ only in letter case or spacing ("TEST-Client B"
 * and "test-client  b"). Used to warn before a duplicate client, supplier, shipping company or
 * product is saved, and to keep money account names unique within a currency.
 */
final class SameName
{
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    /** $query narrowed to rows whose $column is the same name as $name. */
    public static function scope(Builder $query, string $name, string $column = 'name'): Builder
    {
        return $query->whereRaw("lower(regexp_replace(btrim({$column}), '\\s+', ' ', 'g')) = ?", [self::normalise($name)]);
    }
}
