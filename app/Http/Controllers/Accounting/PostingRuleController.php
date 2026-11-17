<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\PostingRule;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingService;
use Illuminate\Http\Request;

/**
 * Posting rules: which accounts each automatic posting uses (spec §3.6). The seeded rules
 * assume MASDAR buys and resells; MASDAR's accountant confirms them (spec Q-A2, Q-A4).
 */
class PostingRuleController extends Controller
{
    public function index(Ledger $ledger)
    {
        return view('accounting.posting-rules.index', [
            'rules' => PostingRule::query()->with('debitAccount', 'creditAccount')->orderBy('id')->get(),
            'accounts' => $ledger->postableAccounts(),
            'sides' => PostingService::RULE_SIDES,
        ]);
    }

    public function update(Request $request, PostingRule $rule, ChartOfAccountsService $service)
    {
        $data = $request->validate([
            'debit_account_id' => 'nullable|integer|exists:accounts,id',
            'credit_account_id' => 'nullable|integer|exists:accounts,id',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $service->updatePostingRule($rule, $data, $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.posting-rules.index')->with('success', __('Posting rule :event saved.', ['event' => $rule->event]));
    }
}
