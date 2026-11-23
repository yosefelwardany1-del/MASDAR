<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Services\Finance\BankReconciliationService;
use App\Services\Finance\PaymentQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bank statements imported per money account and matched to payments (spec §5.8). */
class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciliationService $service, private PaymentQueries $payments) {}

    public function index()
    {
        $accounts = MoneyAccount::query()->where('active', true)->orderBy('currency')->orderBy('name')->get();
        $statements = BankStatement::query()->with('moneyAccount', 'importer')->withCount('lines')
            ->withCount(['lines as matched_count' => fn ($q) => $q->whereHas('match')])
            ->orderByDesc('period_to')->orderByDesc('id')->limit(100)->get();

        return view('bank-reconciliation.index', ['accounts' => $accounts, 'statements' => $statements, 'formats' => BankReconciliationService::DATE_FORMATS]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'money_account_id' => 'required|integer|exists:money_accounts,id',
            'file' => 'required|file|mimes:csv,txt|max:4096',
            'date_format' => 'required|string',
            'layout' => 'required|in:one,two',
            'col_date' => 'required|integer|min:1|max:50', 'col_description' => 'nullable|integer|min:1|max:50', 'col_reference' => 'nullable|integer|min:1|max:50',
            'col_amount' => 'nullable|integer|min:1|max:50', 'col_in' => 'nullable|integer|min:1|max:50', 'col_out' => 'nullable|integer|min:1|max:50',
            'col_balance' => 'nullable|integer|min:1|max:50',
            'skip_header' => 'nullable|boolean',
        ]);
        $col = fn (?int $n) => $n ? $n - 1 : null;
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $row);
        }
        fclose($handle);
        if ($request->boolean('skip_header', true)) {
            array_shift($rows);
        }
        $statement = $this->service->import(MoneyAccount::query()->findOrFail($data['money_account_id']), $rows, [
            'date' => $col((int) $data['col_date']), 'description' => $col($data['col_description'] ?? null), 'reference' => $col($data['col_reference'] ?? null),
            'amount' => $data['layout'] === 'one' ? $col($data['col_amount'] ?? null) : null,
            'money_in' => $data['layout'] === 'two' ? $col($data['col_in'] ?? null) : null,
            'money_out' => $data['layout'] === 'two' ? $col($data['col_out'] ?? null) : null,
            'balance' => $col($data['col_balance'] ?? null), 'date_format' => $data['date_format'],
        ], $request->user(), $request->file('file')->getClientOriginalName(), anyway: $request->boolean('anyway'));

        return redirect()->route('bank-reconciliation.show', $statement)->with('success', __('Statement imported: :count lines. Match them to the payments below.', ['count' => $statement->lines()->count()]));
    }

    public function show(BankStatement $statement)
    {
        $statement->load(['moneyAccount', 'lines.match.payment']);
        $suggestions = $this->service->suggestions($statement);
        $matchedIds = DB::table('bank_matches')->pluck('payment_id');
        $unmatchedPayments = $this->payments->whereEffective(Payment::query())
            ->where('money_account_id', $statement->money_account_id)
            ->whereBetween('paid_on', [$statement->period_from->toDateString(), $statement->period_to->toDateString()])
            ->whereNotIn('id', $matchedIds)->orderBy('paid_on')->get();
        $systemBalance = (string) $this->payments->whereEffective(DB::table('payments'))
            ->where('money_account_id', $statement->money_account_id)->where('paid_on', '<=', $statement->period_to->toDateString())
            ->selectRaw('COALESCE(SUM(signed_amount), 0)::numeric(18,4) AS b')->value('b');

        return view('bank-reconciliation.show', compact('statement', 'suggestions', 'unmatchedPayments', 'systemBalance'));
    }

    public function match(Request $request, BankStatement $statement, BankStatementLine $line)
    {
        abort_unless($line->bank_statement_id === $statement->id, 404);
        $data = $request->validate(['payment_id' => 'required|integer|exists:payments,id']);
        $this->service->match($line, Payment::query()->findOrFail($data['payment_id']), $request->user());

        return back()->with('success', __('Matched.'));
    }

    public function unmatch(Request $request, BankStatement $statement, BankStatementLine $line)
    {
        abort_unless($line->bank_statement_id === $statement->id, 404);
        $this->service->unmatch($line, $request->user());

        return back()->with('success', __('Unmatched.'));
    }

    public function matchSuggested(Request $request, BankStatement $statement)
    {
        $count = $this->service->matchSuggested($statement, $request->user());

        return back()->with('success', trans_choice('{0} No line had a single sure match.|{1} One line matched.|[2,*] :count lines matched.', $count, ['count' => $count]));
    }

    public function destroy(Request $request, BankStatement $statement)
    {
        $this->service->deleteStatement($statement, $request->user());

        return redirect()->route('bank-reconciliation.index')->with('success', __('Statement deleted.'));
    }
}
