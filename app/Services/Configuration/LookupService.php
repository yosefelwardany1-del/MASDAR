<?php

namespace App\Services\Configuration;

use App\Models\Lookup;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of lookups. A code never changes once created — documents store it — so an
 * entry is renamed or switched off, never re-coded or deleted.
 */
class LookupService
{
    /** @param array<string, mixed> $data validated: list, code, name, name_ar, name_zh, sort, meta */
    public function create(array $data): Lookup
    {
        return DB::transaction(function () use ($data) {
            $lookup = Lookup::create($data + ['active' => true]);
            AuditTrail::record('created', $lookup, [], $lookup->only(['list', 'code', 'name', 'name_ar', 'name_zh', 'sort', 'meta']));

            return $lookup;
        });
    }

    /** @param array<string, mixed> $data validated: name, name_ar, name_zh, sort, meta, active */
    public function update(Lookup $lookup, array $data): Lookup
    {
        return DB::transaction(function () use ($lookup, $data) {
            $old = $lookup->only(array_keys($data));
            $lookup->update($data);
            AuditTrail::record('updated', $lookup, $old, $lookup->only(array_keys($data)));

            return $lookup;
        });
    }
}
