<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Budget;
use Illuminate\Support\Facades\DB;

/** Budget against actual (spec §5.8), read-only: actual = posted lines per account and month, income as a positive figure. */
class BudgetQueries
{
    /** @return array<int, array<int, string>> account_id => month => actual */
    public function actuals(int $year): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.status', 'posted')->whereIn('a.type', ['income', 'expense'])
            ->whereRaw('EXTRACT(YEAR FROM e.entry_date) = ?', [$year])
            ->groupBy('l.account_id', 'a.type')->groupByRaw('EXTRACT(MONTH FROM e.entry_date)')
            ->selectRaw('l.account_id, a.type, EXTRACT(MONTH FROM e.entry_date)::int AS month, SUM(l.debit) - SUM(l.credit) AS net')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $net = Decimal::normalize((string) $r->net);
            $out[(int) $r->account_id][(int) $r->month] = $r->type === 'income' ? Decimal::neg($net) : $net;
        }

        return $out;
    }

    /**
     * Each account with a budget or an actual: per month and for the year, budget, actual and
     * the difference (actual − budget), grouped income then expenses.
     */
    public function compare(Budget $budget, ?int $toMonth = null): array
    {
        $toMonth ??= 12;
        $planned = [];
        foreach ($budget->lines as $line) {
            $planned[$line->account_id][$line->month] = (string) $line->amount;
        }
        $actuals = $this->actuals($budget->year);
        $ids = array_unique(array_merge(array_keys($planned), array_keys($actuals)));
        $accounts = Account::query()->whereIn('id', $ids)->orderBy('code')->get();
        $sections = ['income' => [], 'expense' => []];
        foreach ($accounts as $account) {
            $months = [];
            $b = '0';
            $a = '0';
            for ($m = 1; $m <= 12; $m++) {
                $pm = $planned[$account->id][$m] ?? '0';
                $am = $actuals[$account->id][$m] ?? '0';
                $months[$m] = ['budget' => $pm, 'actual' => $am];
                if ($m <= $toMonth) {
                    $b = Decimal::add($b, $pm);
                    $a = Decimal::add($a, $am);
                }
            }
            $sections[$account->type][] = ['account' => $account, 'months' => $months, 'budget' => $b, 'actual' => $a, 'difference' => Decimal::sub($a, $b)];
        }
        $total = fn (array $rows, string $key) => Decimal::sum(array_column($rows, $key));

        return [
            'income' => $sections['income'], 'expense' => $sections['expense'],
            'totals' => [
                'income' => ['budget' => $total($sections['income'], 'budget'), 'actual' => $total($sections['income'], 'actual')],
                'expense' => ['budget' => $total($sections['expense'], 'budget'), 'actual' => $total($sections['expense'], 'actual')],
            ],
        ];
    }
}
