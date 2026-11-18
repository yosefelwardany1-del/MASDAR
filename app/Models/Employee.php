<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * An employee of MASDAR (spec §5.7). The code never changes (trigger). Salaries, documents,
 * leave and attendance are rows of their own, append-only. Written only by
 * App\Services\Hr\EmployeeService. Scans of documents (passport, residence permit, contract)
 * are its attachments, seen only by those who edit the employee file.
 */
class Employee extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'left_on' => 'date', 'birth_date' => 'date'];
    }

    public function attachmentModule(): string
    {
        return 'employees';
    }

    /** Scans of passports and contracts: HR only, whatever the level. */
    public function attachmentAllows(User $user, string $level): bool
    {
        return $user->can('employees.edit');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** On the staff on $date: joined by then and not yet left. */
    public function scopeOnStaff(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->where('joined_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date));
    }

    /** On the staff for at least one day between $from and $to. */
    public function scopeOnStaffBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('joined_on', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $from));
    }

    public function isOnStaff(?string $date = null): bool
    {
        $date ??= now()->toDateString();

        return $this->joined_on->toDateString() <= $date && ($this->left_on === null || $this->left_on->toDateString() >= $date);
    }

    /** The salary in effect on $date: the latest row on or before it (a correction is a later row). */
    public function salaryOn(string $date): ?EmployeeSalary
    {
        return $this->salaries()->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** The newest document of each kind: a renewal supersedes the one before. @return Collection<string, EmployeeDocument> */
    public function currentDocuments(): Collection
    {
        return $this->documents->sortByDesc(fn (EmployeeDocument $d) => [$d->issued_on?->toDateString() ?? '', $d->id])
            ->unique('kind')->keyBy('kind');
    }
}
