<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\MoneyAccountController;
use App\Models\Account;
use App\Models\Party;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\LedgerReports;
use App\Services\Finance\FxRateService;
use App\Services\Reports\AgeingQueries;
use App\Support\IO\CsvSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The financial reports (spec §5.6, §7): trial balance, profit and loss, balance sheet, an
 * account's ledger, a party's statement and the cash position — each on screen and as CSV
 * (?format=csv). Party statements respect Visibility: a sales user limited to their own
 * clients sees only those, and suppliers only to those who may see supplier identities.
 */
class FinanceReportController extends Controller
{
    private const KINDS = ['client', 'supplier', 'shipping'];

    public function __construct(private LedgerReports $reports, private Ledger $ledger) {}

    public function index()
    {
        return view('finance-reports.index');
    }

    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->range($request);
        $all = $request->boolean('all');
        $report = $this->reports->trialBalance($from, $to, $all);

        if ($this->wantsCsv($request)) {
            return $this->csv("trial-balance-{$from}-{$to}.csv",
                [__('Code'), __('Account'), __('Opening debit'), __('Opening credit'), __('Debits'), __('Credits'), __('Closing debit'), __('Closing credit')],
                array_merge(array_map(fn ($r) => [
                    $r['account']->code, $r['account']->displayName(),
                    $r['opening_dr'], $r['opening_cr'], $r['debit'], $r['credit'], $r['closing_dr'], $r['closing_cr'],
                ], $report['rows']), [['', __('Total'), ...array_values($report['totals'])]]));
        }

        return view('finance-reports.trial-balance', compact('report', 'from', 'to', 'all') + ['book' => $this->ledger->bookCurrency()]);
    }

    public function profitAndLoss(Request $request)
    {
        [$from, $to] = $this->range($request);
        $all = $request->boolean('all');
        $report = $this->reports->profitAndLoss($from, $to, $all);

        if ($this->wantsCsv($request)) {
            $rows = [];
            foreach (['income' => __('Income'), 'expense' => __('Expenses')] as $key => $title) {
                $rows[] = ['', $title, ''];
                foreach ($report[$key]['rows'] as $r) {
                    $rows[] = [$r['account']->code, str_repeat('  ', $r['depth']).$r['account']->displayName(), $r['amount']];
                }
                $rows[] = ['', __('Total :section', ['section' => $title]), $report[$key]['total']];
            }
            $rows[] = ['', __('Net result'), $report['net']];

            return $this->csv("profit-and-loss-{$from}-{$to}.csv", [__('Code'), __('Account'), __('Amount')], $rows);
        }

        return view('finance-reports.profit-and-loss', compact('report', 'from', 'to', 'all') + ['book' => $this->ledger->bookCurrency()]);
    }

    public function balanceSheet(Request $request)
    {
        $asAt = $this->dateOr($request->query('as_at'), now()->toDateString());
        // The financial year end is OPEN (spec Q-A4): the year's start is asked on the report,
        // starting at 1 January, so the split between this year's and earlier results is visible.
        $yearStart = $this->dateOr($request->query('year_start'), Carbon::parse($asAt)->startOfYear()->toDateString());
        $all = $request->boolean('all');
        $report = $this->reports->balanceSheet($asAt, $yearStart, $all);

        if ($this->wantsCsv($request)) {
            $rows = [];
            foreach (['assets' => __('Assets'), 'liabilities' => __('Liabilities'), 'equity' => __('Equity')] as $key => $title) {
                $rows[] = ['', $title, ''];
                foreach ($report[$key]['rows'] as $r) {
                    $rows[] = [$r['account']->code, str_repeat('  ', $r['depth']).$r['account']->displayName(), $r['amount']];
                }
                if ($key === 'equity') {
                    $rows[] = ['', __('Result of earlier years not yet carried to equity'), $report['earlier_years']];
                    $rows[] = ['', __('Current-year result'), $report['current_year']];
                    $rows[] = ['', __('Total :section', ['section' => $title]), $report['equity_total']];
                } else {
                    $rows[] = ['', __('Total :section', ['section' => $title]), $report[$key]['total']];
                }
            }
            $rows[] = ['', __('Liabilities and equity'), $report['liabilities_and_equity']];

            return $this->csv("balance-sheet-{$asAt}.csv", [__('Code'), __('Account'), __('Amount')], $rows);
        }

        return view('finance-reports.balance-sheet', compact('report', 'asAt', 'yearStart', 'all') + ['book' => $this->ledger->bookCurrency()]);
    }

    public function accountLedger(Request $request)
    {
        [$from, $to] = $this->range($request);
        $accounts = Account::query()->where('is_postable', true)->orderBy('code')->get();
        $account = ctype_digit((string) $request->query('account_id')) ? $accounts->firstWhere('id', (int) $request->query('account_id')) : null;
        $report = $account ? $this->reports->accountLedger($account, $from, $to, $request->user()) : null;

        if ($report && $this->wantsCsv($request)) {
            return $this->csv("ledger-{$account->code}-{$from}-{$to}.csv",
                [__('Date'), __('Entry'), __('Description'), __('Party'), __('Currency'), __('Amount in currency'), __('Rate'), __('Debit'), __('Credit'), __('Balance')],
                array_merge(
                    [['', '', __('Opening balance'), '', '', '', '', '', '', $report['opening']]],
                    array_map(fn ($r) => [
                        $r['entry_date'], $r['number'], trim(($r['description'] ?? '').' '.($r['memo'] ?? '')), $r['party_name'] ?? '',
                        $r['currency'], $r['amount_currency'], $r['rate'], $r['debit'], $r['credit'], $r['running'],
                    ], $report['rows']),
                    [['', '', __('Closing balance'), '', '', '', '', $report['debit'], $report['credit'], $report['closing']]],
                ));
        }

        return view('finance-reports.account-ledger', compact('accounts', 'account', 'report', 'from', 'to') + ['book' => $this->ledger->bookCurrency()]);
    }

    public function partyStatement(Request $request)
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : 'client';
        $parties = $this->ledger->statementParties($user, $kind)->get(['id', 'code', 'name', 'is_client', 'is_supplier', 'is_shipping', 'account_manager_id']);

        $party = null;
        if (ctype_digit((string) $request->query('party_id'))) {
            $party = Party::query()->findOrFail((int) $request->query('party_id'));
            $ofKind = match ($kind) {
                'client' => $party->is_client,
                'supplier' => $party->is_supplier,
                default => $party->is_shipping,
            };
            abort_unless($ofKind, 404);
            abort_unless($parties->contains('id', $party->id) && $this->ledger->canSeeParty($user, $party), 403);
        }
        $report = $party ? $this->reports->partyStatement($party, $from, $to) : null;

        if ($report && $this->wantsCsv($request)) {
            return $this->csv("statement-{$party->code}-{$from}-{$to}.csv",
                [__('Date'), __('Entry'), __('Description'), __('Account'), __('Currency'), __('Amount in currency'), __('Rate'), __('Debit'), __('Credit'), __('Balance (debit − credit)')],
                array_merge(
                    [['', '', __('Opening balance'), '', '', '', '', '', '', $report['opening']]],
                    array_map(fn ($r) => [
                        $r['entry_date'], $r['number'], trim(($r['description'] ?? '').' '.($r['memo'] ?? '')), $r['account_code'],
                        $r['currency'], $r['amount_currency'], $r['rate'], $r['debit'], $r['credit'], $r['running'],
                    ], $report['rows']),
                    [['', '', __('Closing balance'), '', '', '', '', $report['debit'], $report['credit'], $report['closing']]],
                ));
        }

        return view('finance-reports.party-statement', compact('kind', 'parties', 'party', 'report', 'from', 'to') + ['book' => $this->ledger->bookCurrency()]);
    }

    public function cashPosition(Request $request)
    {
        $asAt = $this->dateOr($request->query('as_at'), now()->toDateString());
        $report = $this->reports->cashPosition($asAt);

        if ($this->wantsCsv($request)) {
            return $this->csv("cash-position-{$asAt}.csv",
                [__('Money account'), __('Currency'), __('Balance from payments'), __('Ledger account'), __('Ledger balance (:book)', ['book' => $this->ledger->bookCurrency()])],
                array_map(fn ($r) => [
                    $r['account']->name, $r['account']->currency, $r['native'],
                    $r['account']->ledgerAccount?->code ?? '', $r['book'] ?? '',
                ], $report['rows']));
        }

        return view('finance-reports.cash-position', compact('report', 'asAt') + [
            'book' => $this->ledger->bookCurrency(),
            // Read only, for the bars: each currency's total at the rate of the day.
            'inBook' => MoneyAccountController::inBook($report['by_currency'], $asAt, app(FxRateService::class)),
        ]);
    }

    /** Receivables or payables by age (spec §5.8): per party and currency, oldest settled first. */
    public function ageing(Request $request, AgeingQueries $ageing)
    {
        $side = in_array($request->query('side'), AgeingQueries::SIDES, true) ? $request->query('side') : 'receivable';
        $asAt = $this->dateOr($request->query('as_at'), now()->toDateString());
        $report = $ageing->report($side, $asAt, $request->user());
        $labels = $this->bucketLabels($report['buckets']);

        if ($this->wantsCsv($request)) {
            return $this->csv("ageing-{$side}-{$asAt}.csv",
                array_merge([__('Code'), __('Party'), __('Currency'), __('Open')], $labels, [__('Advance'), __('Oldest open (days)')]),
                array_map(fn ($r) => array_merge(
                    [$r['party']->code, $r['party']->name, $r['currency'], $r['open']], $r['buckets'], [$r['advance'], $r['oldest']['days'] ?? ''],
                ), $report['rows']));
        }

        return view('finance-reports.ageing', compact('side', 'asAt', 'report', 'labels'));
    }

    /** @return list<string> "0–30 days", "31–60 days", …, "over 90 days" */
    private function bucketLabels(array $buckets): array
    {
        $labels = [];
        $from = 0;
        foreach ($buckets as $limit) {
            $labels[] = __(':from–:to days', ['from' => $from, 'to' => $limit]);
            $from = $limit + 1;
        }
        $labels[] = __('Over :days days', ['days' => end($buckets)]);

        return $labels;
    }

    /** @return array{string, string} from, to — the current year to date unless given */
    private function range(Request $request): array
    {
        $to = $this->dateOr($request->query('to'), now()->toDateString());
        $from = $this->dateOr($request->query('from'), Carbon::parse($to)->startOfYear()->toDateString());

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    private function dateOr(mixed $value, string $default): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('!Y-m-d', $value)->toDateString();
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }

    private function wantsCsv(Request $request): bool
    {
        return $request->query('format') === 'csv';
    }

    /** Stream a CSV; text cells are neutralised against formula injection, amounts stay numbers. */
    private function csv(string $filename, array $header, iterable $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => CsvSanitizer::cell((string) $c), $header));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^-?\d+(\.\d+)?$/', $cell)
                    ? $cell
                    : CsvSanitizer::cell((string) ($cell ?? '')), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
