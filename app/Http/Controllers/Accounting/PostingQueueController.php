<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingQueue;
use App\Services\Accounting\PostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "To post": effective payments, confirmed PIs and fully received supplier orders that have no
 * entry yet, and a form for a shipping company's bill. Each Post shows the posting service's
 * message when it refuses (a rule or a money account not set up, a closed period, a missing
 * FX rate) rather than failing.
 */
class PostingQueueController extends Controller
{
    public function index(PostingQueue $queue, Ledger $ledger)
    {
        return view('accounting.to-post.index', [
            'payments' => $queue->payments(),
            'awaitingApproval' => $queue->awaitingApproval(),
            'invoices' => $queue->invoices(),
            'bills' => $queue->bills(),
            'payrolls' => $queue->payrolls(),
            'shippingCompanies' => Party::query()->shippingCompanies()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'containers' => Container::query()->orderByDesc('id')->limit(500)->get(['id', 'container_no', 'shipment_id']),
            'currencies' => array_keys(Lookup::options('currency')) ?: [$ledger->bookCurrency()],
            'book' => $ledger->bookCurrency(),
        ]);
    }

    public function postPayment(Request $request, Payment $payment, PostingService $posting)
    {
        return $this->one(fn () => $posting->postPayment($payment, $request->user()));
    }

    public function postAllPayments(Request $request, PostingQueue $queue, PostingService $posting)
    {
        return $this->all($queue->payments(), fn (Payment $p) => $posting->postPayment($p, $request->user()), fn (Payment $p) => $p->number);
    }

    public function postInvoice(Request $request, ProformaInvoice $pi, PostingService $posting)
    {
        $on = $this->date($request);

        return $this->one(fn () => $posting->postClientInvoice($pi, $request->user(), $on));
    }

    public function postAllInvoices(Request $request, PostingQueue $queue, PostingService $posting)
    {
        return $this->all($queue->invoices(), fn (ProformaInvoice $pi) => $posting->postClientInvoice($pi, $request->user()), fn (ProformaInvoice $pi) => $pi->reference());
    }

    public function postBill(Request $request, SupplierOrder $order, PostingService $posting)
    {
        $on = $this->date($request);

        return $this->one(fn () => $posting->postSupplierBill($order, $request->user(), $on));
    }

    public function postAllBills(Request $request, PostingQueue $queue, PostingService $posting)
    {
        return $this->all($queue->bills(), fn (SupplierOrder $o) => $posting->postSupplierBill($o, $request->user()), fn (SupplierOrder $o) => $o->number);
    }

    public function postPayroll(Request $request, PayrollRun $run, PostingService $posting)
    {
        return $this->one(fn () => $posting->postPayroll($run, $request->user()));
    }

    public function postAllPayrolls(Request $request, PostingQueue $queue, PostingService $posting)
    {
        return $this->all($queue->payrolls(), fn (PayrollRun $run) => $posting->postPayroll($run, $request->user()), fn (PayrollRun $run) => $run->number);
    }

    public function postShippingBill(Request $request, PostingService $posting)
    {
        $data = $request->validate([
            'shipping_company_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('is_shipping', true)],
            'container_id' => 'nullable|integer|exists:containers,id',
            'amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys(Lookup::options('currency')))],
            'rate' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'bill_date' => 'required|date_format:Y-m-d',
            'description' => 'nullable|string|max:500',
        ]);

        return $this->one(fn () => $posting->postShippingBill(
            Party::query()->findOrFail($data['shipping_company_id']),
            filled($data['container_id'] ?? null) ? Container::query()->findOrFail($data['container_id']) : null,
            $data['amount'], $data['currency'], $data['bill_date'], $data['description'] ?? null,
            $request->user(), $data['rate'] ?? null,
        ));
    }

    private function one(callable $post)
    {
        try {
            $entry = $post();
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.to-post.index')->with('success', __('Posted as :number.', ['number' => $entry->number]));
    }

    /** Post every row of a section; each refusal is reported, the others still post. */
    private function all(iterable $rows, callable $post, callable $name)
    {
        $posted = 0;
        $refused = [];
        foreach ($rows as $row) {
            try {
                $post($row);
                $posted++;
            } catch (AccountingException $e) {
                $refused[] = $name($row).': '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('accounting.to-post.index');
        if ($posted > 0) {
            $redirect->with('success', trans_choice('{1} One posted.|[2,*] :count posted.', $posted, ['count' => $posted]));
        }
        if ($refused !== []) {
            $more = count($refused) > 5 ? ' '.__('and :count more.', ['count' => count($refused) - 5]) : '';
            $redirect->with('error', __('Not posted:').' '.implode(' — ', array_slice($refused, 0, 5)).$more);
        }
        if ($posted === 0 && $refused === []) {
            $redirect->with('warning', __('Nothing to post.'));
        }

        return $redirect;
    }

    private function date(Request $request): ?string
    {
        $data = $request->validate(['posting_date' => 'nullable|date_format:Y-m-d']);

        return $data['posting_date'] ?? null;
    }
}
