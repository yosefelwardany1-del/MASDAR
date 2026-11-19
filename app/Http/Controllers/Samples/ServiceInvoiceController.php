<?php

namespace App\Http\Controllers\Samples;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\Setting;
use App\Services\ServiceInvoices\ServiceInvoiceService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;

/** Service invoices (spec §5.8): commission, inspection, sourcing, samples, freight recharged. */
class ServiceInvoiceController extends Controller
{
    public function __construct(private ServiceInvoiceService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $invoices = ServiceInvoice::query()
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->whereHas('client', fn ($c) => $c->where('account_manager_id', $user->id)))
            ->with('client')->withSum('lines', 'amount')
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")->orderByDesc('issued_on')->orderByDesc('id')->paginate(30);

        return view('service-invoices.index', compact('invoices'));
    }

    public function create(Request $request)
    {
        return view('service-invoices.create', $this->formData($request) + ['preset' => ['client_id' => $request->integer('client') ?: null, 'proforma_invoice_id' => $request->integer('pi') ?: null]]);
    }

    public function store(Request $request)
    {
        $data = $request->only(['client_id', 'proforma_invoice_id', 'currency', 'notes']);
        if (filled($request->input('line_description'))) {
            $data['lines'] = [['category' => $request->input('line_category'), 'description' => $request->input('line_description'), 'quantity' => '1', 'unit_price' => $request->input('line_amount')]];
        }
        $invoice = $this->service->createDraft($data, $request->user());

        return redirect()->route('service-invoices.show', $invoice)->with('success', __('Draft invoice created. Check its lines, then issue it.'));
    }

    public function show(Request $request, ServiceInvoice $invoice)
    {
        abort_unless($invoice->visibleTo($request->user()), 404);
        $invoice->load(['client', 'proformaInvoice', 'lines', 'attachments.uploader']);

        return view('service-invoices.show', $this->formData($request) + ['invoice' => $invoice, 'total' => $invoice->total(),
            'editable' => $invoice->isDraft() && $request->user()->can('service_invoices.edit')]);
    }

    public function update(Request $request, ServiceInvoice $invoice)
    {
        $this->service->updateDraft($invoice, $request->only(['client_id', 'proforma_invoice_id', 'currency', 'notes']), $request->user());

        return back()->with('success', __('Saved.'));
    }

    public function storeLine(Request $request, ServiceInvoice $invoice)
    {
        $this->service->addLine($invoice, $request->only(['category', 'description', 'quantity', 'unit_price']), $request->user());

        return back()->with('success', __('Line added.'));
    }

    public function destroyLine(Request $request, ServiceInvoice $invoice, ServiceInvoiceLine $line)
    {
        abort_unless($line->service_invoice_id === $invoice->id, 404);
        $this->service->deleteLine($line, $request->user());

        return back()->with('success', __('Line removed.'));
    }

    public function issue(Request $request, ServiceInvoice $invoice)
    {
        $data = $request->validate(['issued_on' => 'nullable|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:'.($request->input('issued_on') ?: now()->toDateString())],
            ['due_on.after_or_equal' => __('The due date can\'t be before the invoice date.')]);
        $invoice = $this->service->issue($invoice, $request->user(), $data['issued_on'] ?? null, $data['due_on'] ?? null);

        return back()->with('success', __('Invoice :number issued.', ['number' => $invoice->number]));
    }

    public function void(Request $request, ServiceInvoice $invoice)
    {
        $this->service->void($invoice, (string) $request->input('void_reason'), $request->user());

        return back()->with('success', __('Invoice voided.'));
    }

    public function destroy(Request $request, ServiceInvoice $invoice)
    {
        $this->service->deleteDraft($invoice, $request->user());

        return redirect()->route('service-invoices.index')->with('success', __('Draft deleted.'));
    }

    public function print(Request $request, ServiceInvoice $invoice)
    {
        abort_unless($invoice->visibleTo($request->user()), 404);
        $invoice->load(['client', 'proformaInvoice', 'lines']);
        $setting = Setting::current();
        $business = BusinessSetting::current();

        return view('service-invoices.print', [
            'invoice' => $invoice, 'total' => $invoice->total(),
            'categories' => Lookup::query()->where('list', 'service_income')->pluck('name', 'code'),
            'companyName' => $setting->company_name, 'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'address' => $business->company_address, 'phone' => $business->company_phone, 'bank' => $business->invoice_bank_details,
        ]);
    }

    private function formData(Request $request): array
    {
        $user = $request->user();

        return [
            'clients' => Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'code', 'name', 'currency']),
            'pis' => ProformaInvoice::query()->current()->whereIn('status', ['issued', 'accepted', 'confirmed'])->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
                ->with('client:id,name')->orderByDesc('id')->limit(200)->get(['id', 'pi_number', 'revision', 'client_id', 'currency']),
            'currencies' => Lookup::options('currency'),
            'categories' => Lookup::options('service_income'),
        ];
    }
}
