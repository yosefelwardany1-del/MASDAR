<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up task, optionally about a party. Written only by App\Services\Crm\CrmService.
 */
class CrmTask extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_on->lt(today());
    }

    public function isDueToday(): bool
    {
        return ! $this->isDone() && $this->due_on->isSameDay(today());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * Tasks $user may see: their own (assigned or created), those about no party, and those
     * about a party they may open (Party::scopeVisibleTo).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('assigned_to', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhereNull('party_id')
            ->orWhereIn('party_id', Party::query()->visibleTo($user)->select('id')));
    }
}
