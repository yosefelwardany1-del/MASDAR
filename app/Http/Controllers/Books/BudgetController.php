<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Services\Accounting\BudgetQueries;
use App\Services\Accounting\BudgetService;
use Illuminate\Http\Request;

/** Budgets per account and month, and how the year is going against them (spec §5.8). */
class BudgetController extends Controller
{
    public function __construct(private BudgetService $service, private BudgetQueries $queries) {}

    public function index()
    {
        return view('budgets.index', ['budgets' => Budget::query()->withCount('lines')->orderByDesc('year')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $budget = $this->service->create($request->only(['name', 'year', 'notes', 'copy_from']), $request->user());

        return redirect()->route('budgets.show', ['budget' => $budget, 'edit' => 1])->with('success', __('Budget created. Enter its amounts.'));
    }

    public function show(Request $request, Budget $budget)
    {
        $budget->load('lines');
        $toMonth = (int) $request->query('to_month', $budget->year === (int) now()->year ? now()->month : 12);

        return view('budgets.show', [
            'budget' => $budget, 'toMonth' => max(1, min(12, $toMonth)),
            'editing' => $request->boolean('edit') && $request->user()->can('budgets.edit'),
            'report' => $this->queries->compare($budget, max(1, min(12, $toMonth))),
            'accounts' => Account::query()->where('is_postable', true)->where('active', true)->whereIn('type', ['income', 'expense'])->orderBy('code')->get(),
            'planned' => $budget->lines->groupBy('account_id')->map(fn ($g) => $g->pluck('amount', 'month')->map(fn ($v) => (string) $v)),
        ]);
    }

    public function update(Request $request, Budget $budget)
    {
        $this->service->saveAmounts($budget, (array) $request->input('amounts', []), $request->user());

        return redirect()->route('budgets.show', $budget)->with('success', __('Budget saved.'));
    }
}
