<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Lookup;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\LedgerReports;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts (spec §5.6): an indented tree with each account's balance in the book
 * currency, headings summing their sub-accounts. Written through ChartOfAccountsService.
 */
class ChartOfAccountsController extends Controller
{
    public function index(Request $request, LedgerReports $reports, Ledger $ledger)
    {
        return view('accounting.index', [
            'rows' => $reports->chart(),
            'book' => $ledger->bookCurrency(),
        ]);
    }

    public function create()
    {
        return view('accounting.accounts.create', $this->formData(new Account(['is_postable' => true, 'active' => true, 'type' => 'asset'])));
    }

    public function store(Request $request, ChartOfAccountsService $service)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('accounts', 'code')],
        ] + $this->rules());

        try {
            $account = $service->create($this->input($request, $data), $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.index')->with('success', __('Account :code created.', ['code' => $account->code]));
    }

    public function edit(Account $account)
    {
        return view('accounting.accounts.edit', $this->formData($account));
    }

    public function update(Request $request, Account $account, ChartOfAccountsService $service)
    {
        $data = $request->validate($this->rules());

        try {
            $service->update($account, $this->input($request, $data), $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.index')->with('success', __('Account :code saved.', ['code' => $account->code]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'name_ar' => 'nullable|string|max:160',
            'name_zh' => 'nullable|string|max:160',
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => 'nullable|integer|exists:accounts,id',
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys(Lookup::options('currency')))],
            'sort' => 'nullable|integer|min:0|max:1000000',
        ];
    }

    /** @return array<string, mixed> */
    private function input(Request $request, array $data): array
    {
        return $data + [
            'is_postable' => $request->boolean('is_postable'),
            'active' => $request->boolean('active'),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(Account $account): array
    {
        return [
            'account' => $account,
            'headings' => Account::query()->where('is_postable', false)->whereKeyNot($account->id ?? 0)->orderBy('code')->get(),
            'currencies' => Lookup::options('currency'),
            'types' => Account::typeLabels(),
            'hasLines' => $account->exists && $account->lines()->exists(),
        ];
    }
}
