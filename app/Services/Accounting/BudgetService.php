<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\User;
use App\Services\Hr\Money;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of budgets and budget_lines (spec §5.8): a plan, not the books — an amount per
 * income or expense account and month, edited freely by whoever has budgets.edit and audited.
 */
class BudgetService
{
    public function create(array $data, User $by): Budget
    {
        $this->authorize($by);
        $name = trim((string) ($data['name'] ?? ''));
        $year = (int) ($data['year'] ?? 0);
        if ($name === '' || $year < 2000 || $year > 2100) {
            throw ValidationException::withMessages(['name' => __('Give the budget a name and a year.')]);
        }
        if (Budget::query()->where('name', $name)->where('year', $year)->exists()) {
            throw ValidationException::withMessages(['name' => __('There is already a budget :name for :year.', ['name' => $name, 'year' => $year])]);
        }

        return DB::transaction(function () use ($name, $year, $data, $by) {
            $budget = Budget::create(['name' => mb_substr($name, 0, 120), 'year' => $year, 'notes' => $data['notes'] ?? null, 'created_by' => $by->id]);
            if (filled($data['copy_from'] ?? null) && ($source = Budget::query()->find((int) $data['copy_from']))) {
                foreach ($source->lines as $line) {
                    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $line->account_id, 'month' => $line->month, 'amount' => $line->amount]);
                }
            }
            AuditTrail::record('created', $budget, [], ['name' => $name, 'year' => $year]);

            return $budget;
        });
    }

    /**
     * Save the grid: $amounts[account_id][month] = amount ('' or 0 removes it).
     *
     * @param  array<int|string, array<int|string, mixed>>  $amounts
     */
    public function saveAmounts(Budget $budget, array $amounts, User $by): void
    {
        $this->authorize($by);
        $allowed = Account::query()->where('is_postable', true)->whereIn('type', ['income', 'expense'])->pluck('id')->all();
        $clean = [];
        foreach ($amounts as $accountId => $months) {
            if (! in_array((int) $accountId, $allowed, true)) {
                continue;
            }
            foreach ((array) $months as $month => $value) {
                $month = (int) $month;
                if ($month < 1 || $month > 12) {
                    continue;
                }
                $clean[(int) $accountId][$month] = filled($value) ? Money::amount($value, "amounts.{$accountId}.{$month}") : '0';
            }
        }

        DB::transaction(function () use ($budget, $clean) {
            $old = $budget->lines()->count();
            foreach ($clean as $accountId => $months) {
                foreach ($months as $month => $amount) {
                    if (bccomp($amount, '0', 4) === 0) {
                        BudgetLine::query()->where(['budget_id' => $budget->id, 'account_id' => $accountId, 'month' => $month])->delete();
                    } else {
                        BudgetLine::query()->updateOrCreate(['budget_id' => $budget->id, 'account_id' => $accountId, 'month' => $month], ['amount' => $amount]);
                    }
                }
            }
            AuditTrail::record('updated', $budget, ['lines' => $old], ['lines' => $budget->lines()->count()]);
        });
    }

    private function authorize(User $by): void
    {
        if (! $by->can('budgets.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
