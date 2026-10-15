<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records one change in audit_logs, called EXPLICITLY by the writer that made it — never
 * from a model event (CLAUDE.md standing rule). Sub-project 2 replaces this with the
 * database-trigger audit (spec §4.9), which catches every write whoever makes it.
 *
 * Fail-closed: the writer calls this inside its own transaction, so if the audit row
 * cannot be written the change is rolled back with it — no unattributed mutation.
 */
class AuditTrail
{
    /** Never written to the trail, whatever the caller passes. */
    private const EXCLUDE = ['password', 'remember_token', 'created_at', 'updated_at'];

    public static function record(string $action, Model $model, array $old = [], array $new = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'old_values' => array_diff_key($old, array_flip(self::EXCLUDE)),
            'new_values' => array_diff_key($new, array_flip(self::EXCLUDE)),
            // varchar(255): an over-long value is a hard error on PostgreSQL.
            'ip_address' => self::clip(request()?->ip()),
            'user_agent' => self::clip(request()?->userAgent()),
            'url' => self::clip(request()?->fullUrl()),
        ]);
    }

    private static function clip(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
