<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An employee's own allowance of a leave type for a year (spec §5.7). Append-only; the latest counts. Written only by App\Services\Hr\LeaveService. */
class LeaveAllowance extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'year' => 'integer'];
    }
}
