<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of accounting_periods. Periods never overlap, so a date falls in at most one;
 * once a period is closed nothing is posted into it (I3, the journal trigger). Closing and
 * reopening need `accounting.approve` (spec §6: closing a period is a manager's approval).
 */
class PeriodService
{
    /** @param array{name: string, starts_on: string, ends_on: string} $data */
    public function create(array $data, User $by): AccountingPeriod
    {
        $this->authorize($by, 'accounting.edit');
        if ($data['ends_on'] < $data['starts_on']) {
            throw new AccountingException(__('A period ends on or after the day it starts.'));
        }

        return DB::transaction(function () use ($data) {
            // Serialise period creation so two overlapping periods cannot slip in side by side.
            DB::statement('LOCK TABLE accounting_periods IN SHARE ROW EXCLUSIVE MODE');
            $overlap = AccountingPeriod::query()
                ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])
                ->first();
            if ($overlap) {
                throw new AccountingException(__('The dates overlap the period :name (:from – :to).', [
                    'name' => $overlap->name, 'from' => $overlap->starts_on->toDateString(), 'to' => $overlap->ends_on->toDateString(),
                ]));
            }

            $period = AccountingPeriod::create([
                'name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            ]);
            AuditTrail::record('created', $period, [], $period->only(['name', 'starts_on', 'ends_on']));

            return $period;
        });
    }

    /** Close a period. Draft entries dated in it are posted or deleted first. */
    public function close(AccountingPeriod $period, User $by): AccountingPeriod
    {
        $this->authorize($by, 'accounting.approve');

        return DB::transaction(function () use ($period, $by) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->closed_at) {
                throw new AccountingException(__('The period :name is already closed.', ['name' => $period->name]));
            }
            $drafts = JournalEntry::query()->where('status', 'draft')
                ->whereBetween('entry_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->count();
            if ($drafts > 0) {
                throw new AccountingException(trans_choice('{1} One draft entry is dated in :name: post or delete it first.|[2,*] :count draft entries are dated in :name: post or delete them first.', $drafts, [
                    'count' => $drafts, 'name' => $period->name,
                ]));
            }

            $period->update(['closed_at' => now(), 'closed_by' => $by->id]);
            AuditTrail::record('closed', $period, ['closed_at' => null], ['closed_at' => $period->closed_at?->toIso8601String(), 'closed_by' => $by->id]);

            return $period;
        });
    }

    public function reopen(AccountingPeriod $period, User $by): AccountingPeriod
    {
        $this->authorize($by, 'accounting.approve');

        return DB::transaction(function () use ($period) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! $period->closed_at) {
                throw new AccountingException(__('The period :name is open.', ['name' => $period->name]));
            }
            $old = ['closed_at' => $period->closed_at->toIso8601String(), 'closed_by' => $period->closed_by];

            $period->update(['closed_at' => null, 'closed_by' => null]);
            AuditTrail::record('reopened', $period, $old, ['closed_at' => null, 'closed_by' => null]);

            return $period;
        });
    }

    private function authorize(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
