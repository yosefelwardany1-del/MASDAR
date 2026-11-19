<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The financial reports (spec §5.6, §7), every figure a SUM() over posted journal lines in the
 * book currency — drafts never count. Read-only.
 */
class LedgerReports
{
    public function __construct(private Ledger $ledger) {}

    /** Posted lines joined to their entries. */
    private function lines(): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted');
    }

    /** @return array<int, string> account_id => SUM(debit − credit) over entries dated from $from to $to (either end open) */
    public function movements(?string $from = null, ?string $to = null): array
    {
        return $this->lines()
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) AS net')
            ->pluck('net', 'account_id')
            ->map(fn ($net) => Decimal::normalize((string) $net))
            ->all();
    }

    /** The whole chart with all-time balances. */
    public function chart(): array
    {
        return ChartTree::rows(Account::query()->get(), $this->movements());
    }

    /**
     * Opening, debits, credits and closing per postable account. Opening and closing are split
     * into a debit and a credit column, so every pair of columns totals equal.
     */
    public function trialBalance(string $from, string $to, bool $all = false): array
    {
        $sums = $this->lines()
            ->where('e.entry_date', '<=', $to)
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id,
                COALESCE(SUM(l.debit - l.credit) FILTER (WHERE e.entry_date < ?), 0) AS opening,
                COALESCE(SUM(l.debit) FILTER (WHERE e.entry_date >= ?), 0) AS debit,
                COALESCE(SUM(l.credit) FILTER (WHERE e.entry_date >= ?), 0) AS credit', [$from, $from, $from])
            ->get()->keyBy('account_id');

        $rows = [];
        $totals = array_fill_keys(['opening_dr', 'opening_cr', 'debit', 'credit', 'closing_dr', 'closing_cr'], '0');
        // The figures shown are to the cent, and the totals are the sum of those figures (B09);
        // the exact sums stay in 'exact', to tell sub-cent amounts posted before that from an error.
        $exact = $totals;
        $cent = fn (string $v) => Decimal::normalize(Decimal::round($v, 2));
        foreach (Account::query()->where('is_postable', true)->orderBy('code')->get() as $account) {
            $sum = $sums->get($account->id);
            $opening = Decimal::normalize((string) ($sum->opening ?? '0'));
            $debit = Decimal::normalize((string) ($sum->debit ?? '0'));
            $credit = Decimal::normalize((string) ($sum->credit ?? '0'));
            $closing = Decimal::add($opening, Decimal::sub($debit, $credit));
            if (! $all && Decimal::isZero($opening) && Decimal::isZero($debit) && Decimal::isZero($credit)) {
                continue;
            }
            $row = [
                'account' => $account,
                'opening_dr' => Decimal::isPositive($opening) ? $opening : '0',
                'opening_cr' => Decimal::isPositive($opening) ? '0' : Decimal::abs($opening),
                'debit' => $debit,
                'credit' => $credit,
                'closing_dr' => Decimal::isPositive($closing) ? $closing : '0',
                'closing_cr' => Decimal::isPositive($closing) ? '0' : Decimal::abs($closing),
            ];
            foreach ($totals as $key => $total) {
                $exact[$key] = Decimal::add($exact[$key], $row[$key]);
                $row[$key] = $cent($row[$key]);
                $totals[$key] = Decimal::add($total, $row[$key]);
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals, 'exact' => $exact];
    }

    /** Income and expense accounts for a period, grouped by heading, and the net result. */
    public function profitAndLoss(string $from, string $to, bool $all = false): array
    {
        $raw = $this->movements($from, $to);
        $section = function (string $type) use ($raw, $all) {
            $rows = ChartTree::rows(Account::query()->where('type', $type)->get(), $raw);

            return [
                'rows' => $all ? $rows : array_values(array_filter($rows, fn ($r) => ! Decimal::isZero($r['raw']))),
                'total' => ChartTree::total($rows),
            ];
        };
        $income = $section('income');
        $expense = $section('expense');

        return ['income' => $income, 'expense' => $expense, 'net' => Decimal::sub($income['total'], $expense['total'])];
    }

    /**
     * Assets, liabilities and equity as at a date, plus the result not yet carried to equity:
     * the current financial year's (from $yearStart) and any earlier years'. Assets equal
     * liabilities + equity + results whenever every entry balances (I1).
     */
    public function balanceSheet(string $asAt, string $yearStart, bool $all = false): array
    {
        $raw = $this->movements(null, $asAt);
        $section = function (string $type) use ($raw, $all) {
            $rows = ChartTree::rows(Account::query()->where('type', $type)->get(), $raw);

            return [
                'rows' => $all ? $rows : array_values(array_filter($rows, fn ($r) => ! Decimal::isZero($r['raw']))),
                'total' => ChartTree::total($rows),
            ];
        };
        $result = fn (?string $from, ?string $to) => Decimal::neg((string) $this->lines()
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.type', ['income', 'expense'])
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS net')->value('net'));

        $assets = $section('asset');
        $liabilities = $section('liability');
        $equity = $section('equity');
        if ($yearStart > $asAt) {
            $currentYear = '0';
            $earlierYears = $result(null, $asAt);
        } else {
            $currentYear = $result($yearStart, $asAt);
            $earlierYears = $result(null, Carbon::parse($yearStart)->subDay()->toDateString());
        }
        $equityTotal = Decimal::sum([$equity['total'], $currentYear, $earlierYears]);
        $liabilitiesAndEquity = Decimal::add($liabilities['total'], $equityTotal);

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'current_year' => $currentYear, 'earlier_years' => $earlierYears,
            'equity_total' => $equityTotal, 'liabilities_and_equity' => $liabilitiesAndEquity,
            'balanced' => Decimal::cmp($assets['total'], $liabilitiesAndEquity) === 0,
        ];
    }

    /** One account's lines in a range, after its opening balance, with a running balance in the account's natural sign. */
    public function accountLedger(Account $account, string $from, string $to, User $viewer): array
    {
        $sign = fn (string $raw) => $account->isDebitNatured() ? $raw : Decimal::neg($raw);
        $opening = $sign(Decimal::normalize((string) $this->lines()->where('l.account_id', $account->id)->where('e.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS net')->value('net')));

        $lines = $this->rangeLines($from, $to)->where('l.account_id', $account->id)->get();
        $parties = Party::query()->whereIn('id', $lines->pluck('party_id')->filter()->unique())->get()->keyBy('id');

        $running = $opening;
        $rows = $lines->map(function ($line) use (&$running, $sign, $parties, $viewer) {
            $running = Decimal::add($running, $sign(Decimal::sub((string) $line->debit, (string) $line->credit)));
            $party = $line->party_id ? $parties->get($line->party_id) : null;

            return (array) $line + [
                'party_name' => $party ? ($this->ledger->canSeeParty($viewer, $party) ? $party->name : __('(hidden)')) : null,
                'running' => $running,
            ];
        })->all();

        return [
            'opening' => $opening, 'rows' => $rows, 'closing' => $running,
            'debit' => Decimal::sum($lines->pluck('debit')->map(fn ($v) => (string) $v)),
            'credit' => Decimal::sum($lines->pluck('credit')->map(fn ($v) => (string) $v)),
        ];
    }

    /** A party's statement: every posted line carrying its party_id, running balance as debit − credit (Dr positive). */
    public function partyStatement(Party $party, string $from, string $to): array
    {
        $opening = Decimal::normalize((string) $this->lines()->where('l.party_id', $party->id)->where('e.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS net')->value('net'));

        $lines = $this->rangeLines($from, $to)->where('l.party_id', $party->id)
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->addSelect('a.code as account_code', 'a.name as account_name', 'a.name_ar as account_name_ar', 'a.name_zh as account_name_zh')
            ->get();

        $running = $opening;
        $rows = $lines->map(function ($line) use (&$running) {
            $running = Decimal::add($running, Decimal::sub((string) $line->debit, (string) $line->credit));

            return (array) $line + ['running' => $running];
        })->all();

        return [
            'opening' => $opening, 'rows' => $rows, 'closing' => $running,
            'debit' => Decimal::sum($lines->pluck('debit')->map(fn ($v) => (string) $v)),
            'credit' => Decimal::sum($lines->pluck('credit')->map(fn ($v) => (string) $v)),
        ];
    }

    /**
     * Each money account: its ledger account's balance in the book currency, and the native
     * SUM(signed_amount) of its effective payments, as at a date.
     */
    public function cashPosition(string $asAt): array
    {
        $accounts = MoneyAccount::query()->with('ledgerAccount')->orderBy('currency')->orderBy('name')->get();
        $ledger = $this->movements(null, $asAt);
        $native = $this->ledger->effectivePayments()->where('paid_on', '<=', $asAt)
            ->groupBy('money_account_id')->selectRaw('money_account_id, SUM(signed_amount) AS total')
            ->pluck('total', 'money_account_id');

        $rows = $accounts->map(fn (MoneyAccount $m) => [
            'account' => $m,
            'native' => Decimal::normalize((string) ($native[$m->id] ?? '0')),
            'book' => $m->ledger_account_id ? ($ledger[$m->ledger_account_id] ?? '0') : null,
        ])->all();

        $byCurrency = collect($rows)->groupBy(fn ($r) => $r['account']->currency)
            ->map(fn (Collection $group) => Decimal::sum($group->pluck('native')))->all();
        // Several money accounts may share one ledger account: count each ledger account once.
        $bookTotal = Decimal::sum($accounts->pluck('ledger_account_id')->filter()->unique()->map(fn ($id) => $ledger[$id] ?? '0'));

        return ['rows' => $rows, 'by_currency' => $byCurrency, 'book_total' => $bookTotal];
    }

    /** Posted lines dated in a range, in date order, with their entry's header. */
    private function rangeLines(string $from, string $to): Builder
    {
        return $this->lines()
            ->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')
            ->select('l.id', 'l.journal_entry_id', 'e.number', 'e.entry_date', 'e.description', 'e.event', 'l.memo', 'l.party_id',
                'l.debit', 'l.credit', 'l.currency', 'l.amount_currency', 'l.rate');
    }
}
