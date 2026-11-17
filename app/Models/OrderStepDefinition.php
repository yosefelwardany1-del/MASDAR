<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of a supplier order (S2's columns), in order. `kind` says what completes it:
 * manual, a payment, the goods receipt, the booking or the loading. Editable on the Lists &
 * options screen; written only by App\Services\Configuration\OrderStepDefinitionService.
 */
class OrderStepDefinition extends Model
{
    use HasLocalizedName;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_attachment' => 'boolean', 'active' => 'boolean', 'sort' => 'integer', 'due_offset_days' => 'integer'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
