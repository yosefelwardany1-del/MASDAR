<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * What an exchange revaluation on a date would post (spec §5.8), read-only. For every asset
 * and liability account, party and currency other than the book's: the balance in that currency
 * (its lines), what the books carry for it (those lines and earlier revaluations of that
 * currency), the balance at the rate of the date, and the difference. A missing rate is never
 * guessed: the preview fails and says which.
 */
class Revaluation
{
    public function __construct(private Ledger $ledger) {}

    /** @return list<array{account: Account, party_id: ?int, party: ?string, currency: string, balance: string, carried: string, rate: string, revalued: string, difference: string}> */
    public function preview(string $date): array
    {
        $book = $this->ledger->bookCurrency();
        $sums = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.status', 'posted')->where('e.entry_date', '<=', $date)
            ->whereIn('a.type', ['asset', 'liability'])
            ->where(fn ($q) => $q->where('l.currency', '<>', $book)->orWhereNotNull('l.revalues_currency'))
            ->groupByRaw('l.account_id, l.party_id, COALESCE(l.revalues_currency, l.currency)')
            ->selectRaw('l.account_id, l.party_id, COALESCE(l.revalues_currency, l.currency) AS currency,
                SUM(CASE WHEN l.revalues_currency IS NULL THEN (CASE WHEN l.debit > 0 THEN l.amount_currency ELSE -l.amount_currency END) ELSE 0 END) AS balance,
                SUM(l.debit - l.credit) AS carried')
            ->get();

        $accounts = Account::query()->whereIn('id', $sums->pluck('account_id')->unique())->get()->keyBy('id');
        $parties = Party::query()->whereIn('id', $sums->pluck('party_id')->filter()->unique())->pluck('name', 'id');
        $rows = [];
        foreach ($sums as $s) {
            if ($s->currency === $book) {
                continue;
            }
            $rate = $this->ledger->rateOn($s->currency, $date);
            $balance = Decimal::normalize((string) $s->balance);
            $carried = Decimal::normalize((string) $s->carried);
            // To the cent, as every journal line is (B09).
            $revalued = Decimal::normalize(Decimal::round(Decimal::mul($balance, $rate, 10), 2));
            $difference = Decimal::normalize(Decimal::round(Decimal::sub($revalued, $carried), 2));
            if (Decimal::cmp(Decimal::abs($difference), '0.01') < 0) {
                continue;
            }
            $rows[] = [
                'account' => $accounts[$s->account_id], 'party_id' => $s->party_id !== null ? (int) $s->party_id : null,
                'party' => $s->party_id !== null ? ($parties[$s->party_id] ?? null) : null,
                'currency' => $s->currency, 'balance' => $balance, 'carried' => $carried, 'rate' => $rate,
                'revalued' => $revalued, 'difference' => $difference,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['account']->code, $a['currency'], $a['party']] <=> [$b['account']->code, $b['currency'], $b['party']]);

        return $rows;
    }
}
