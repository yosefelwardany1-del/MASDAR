<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\Lookup;
use App\Models\ProformaInvoice;
use App\Services\Finance\ExpenseClaimService;
use Illuminate\Http\Request;

/**
 * Staff expense claims (spec §5.8). "My expenses" (signed in only) is each person's own claims;
 * the Expense claims screen is everyone's, for whoever approves or pays them back.
 */
class ExpenseClaimController extends Controller
{
    public function __construct(private ExpenseClaimService $service) {}

    // ---- my expenses

    public function mine(Request $request)
    {
        return view('expense-claims.mine', [
            'claims' => ExpenseClaim::query()->where('user_id', $request->user()->id)->with('latestDecision')->withSum('lines', 'amount')
                ->withCount(['payments as paid_count' => fn ($q) => $q->whereNull('reverses_payment_id')->whereDoesntHave('reversal')])
                ->orderByDesc('id')->get(),
            'currencies' => Lookup::options('currency'),
        ]);
    }

    public function store(Request $request)
    {
        $claim = $this->service->create($request->only(['currency', 'title']), $request->user());

        return redirect()->route('my-expenses.show', $claim)->with('success', __('Claim :number started. Add each receipt.', ['number' => $claim->number]));
    }

    public function showMine(Request $request, ExpenseClaim $claim)
    {
        abort_unless((int) $claim->user_id === (int) $request->user()->id, 404);

        return $this->page($claim, mine: true);
    }

    public function storeLine(Request $request, ExpenseClaim $claim)
    {
        $this->service->addLine($claim, $request->only(['spent_on', 'category', 'description', 'amount', 'proforma_invoice_id', 'container_id']), $request->user());

        return back()->with('success', __('Receipt added.'));
    }

    public function destroyLine(Request $request, ExpenseClaim $claim, ExpenseClaimLine $line)
    {
        abort_unless($line->expense_claim_id === $claim->id, 404);
        $this->service->deleteLine($line, $request->user());

        return back()->with('success', __('Receipt removed.'));
    }

    public function submit(Request $request, ExpenseClaim $claim)
    {
        $this->service->submit($claim, $request->user());

        return back()->with('success', __('Submitted for approval.'));
    }

    // ---- everyone's (screen expense_claims)

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['submitted', 'approved', 'rejected', 'returned', 'draft', 'all'], true) ? $request->query('status') : 'submitted';
        $latest = '(SELECT d.decision FROM expense_claim_decisions d WHERE d.expense_claim_id = expense_claims.id ORDER BY d.id DESC LIMIT 1)';
        $claims = ExpenseClaim::query()
            ->when($status === 'draft', fn ($q) => $q->whereRaw("{$latest} IS NULL"))
            ->when(! in_array($status, ['draft', 'all'], true), fn ($q) => $q->whereRaw("{$latest} = ?", [$status]))
            ->with(['user', 'latestDecision'])->withSum('lines', 'amount')
            ->withCount(['payments as paid_count' => fn ($q) => $q->whereNull('reverses_payment_id')->whereDoesntHave('reversal')])
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('expense-claims.index', compact('claims', 'status'));
    }

    public function show(ExpenseClaim $claim)
    {
        return $this->page($claim, mine: false);
    }

    public function decide(Request $request, ExpenseClaim $claim)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected,returned', 'note' => 'nullable|string|max:2000']);
        $this->service->decideAs($claim, $data['decision'], $request->user(), $data['note'] ?? null);

        return back()->with('success', __('Claim :number: :status.', ['number' => $claim->number, 'status' => mb_strtolower(ExpenseClaim::statusLabel($data['decision']))]));
    }

    private function page(ExpenseClaim $claim, bool $mine)
    {
        $claim->load(['user', 'lines.proformaInvoice', 'lines.container', 'decisions.user', 'attachments.uploader',
            'payments' => fn ($q) => $q->with('moneyAccount', 'reversal')]);
        $paid = $claim->payments->first(fn ($p) => $p->reverses_payment_id === null && ! $p->reversal);

        return view('expense-claims.show', [
            'claim' => $claim, 'mine' => $mine, 'paid' => $paid, 'total' => $claim->total(),
            'categories' => Lookup::options('expense_category'),
            'pis' => $claim->isEditable() ? ProformaInvoice::query()->current()->whereIn('status', ['issued', 'accepted', 'confirmed'])->orderByDesc('id')->limit(200)->get(['id', 'pi_number', 'revision']) : collect(),
            'containers' => $claim->isEditable() ? Container::query()->orderByDesc('id')->limit(100)->get(['id', 'container_no']) : collect(),
        ]);
    }
}
