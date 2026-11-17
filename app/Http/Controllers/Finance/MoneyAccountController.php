<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Services\Finance\MoneyAccountService;
use App\Services\Finance\PaymentQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Money accounts (screen `payments`, spec §3.5): each account's balance in its own currency and
 * the cash position per currency (spec §7), an account's history with its running balance,
 * and the account form. Balances are SUMs over effective payments, never stored.
 */
class MoneyAccountController extends Controller
{
    public function __construct(private MoneyAccountService $accounts, private PaymentQueries $queries) {}

    public function index()
    {
        return view('money-accounts.index', [
            'accounts' => MoneyAccount::query()->with('ledgerAccount')->orderByDesc('active')->orderBy('currency')->orderBy('name')->get(),
            'balances' => $this->queries->accountBalances(),
            'position' => $this->queries->cashPosition(),
        ]);
    }

    public function create()
    {
        return view('money-accounts.create', $this->formOptions(null));
    }

    public function store(Request $request)
    {
        $account = $this->accounts->create($this->validated($request));

        return redirect()->route('money-accounts.show', $account)->with('success', __('Money account created.'));
    }

    public function show(Request $request, MoneyAccount $account)
    {
        $user = $request->user();
        $complete = $this->queries->seesEveryPayment($user);

        // The running balance runs over every effective payment of the account, oldest first.
        $running = DB::table('payments as p')->where('p.money_account_id', $account->id)
            ->selectRaw('p.id, SUM(CASE WHEN '.$this->queries->effectiveSql('p').' THEN p.signed_amount ELSE 0 END) OVER (ORDER BY p.paid_on, p.id) AS running_total');

        $history = Payment::query()
            ->with(['party', 'proformaInvoice', 'supplierOrder', 'container', 'approval', 'reversal', 'reverses.approval'])
            ->where('payments.money_account_id', $account->id)
            ->joinSub($running, 'rt', 'rt.id', '=', 'payments.id')
            ->select('payments.*', 'rt.running_total');
        $this->queries->whereVisibleTo($history, $user);

        return view('money-accounts.show', [
            'account' => $account->load('ledgerAccount'),
            'balance' => $this->queries->accountBalances()[$account->id] ?? '0.0000',
            'history' => $history->orderByDesc('payments.paid_on')->orderByDesc('payments.id')->paginate(25),
            'complete' => $complete,
            'approvalRequired' => $this->queries->approvalRequired(),
            'queries' => $this->queries,
        ]);
    }

    public function edit(MoneyAccount $account)
    {
        return view('money-accounts.edit', $this->formOptions($account));
    }

    public function update(Request $request, MoneyAccount $account)
    {
        $this->accounts->update($account, $this->validated($request));

        return redirect()->route('money-accounts.show', $account)->with('success', __('Money account updated.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(MoneyAccount::KINDS)],
            'currency' => ['required', 'string', 'size:3'],
            'location' => ['required', Rule::in(MoneyAccount::LOCATIONS)],
            'ledger_account_id' => ['nullable', 'integer'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['active'] = $request->boolean('active');

        return $data;
    }

    private function formOptions(?MoneyAccount $account): array
    {
        $currencies = Lookup::options('currency');
        if ($account && ! isset($currencies[$account->currency])) {
            $currencies[$account->currency] = $account->currency;
        }

        return [
            'account' => $account,
            'currencies' => $currencies,
            'currencyLocked' => $account !== null && $account->payments()->exists(),
            'ledgerAccounts' => Account::query()->where('type', 'asset')->where('is_postable', true)
                ->where(fn ($q) => $q->where('active', true)->when($account?->ledger_account_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('code')->get(),
            'book' => BusinessSetting::current()->book_currency,
        ];
    }
}
