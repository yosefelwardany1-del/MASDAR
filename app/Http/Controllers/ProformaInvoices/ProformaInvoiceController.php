<?php

namespace App\Http\Controllers\ProformaInvoices;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Setting;
use App\Services\ProformaInvoices\PiAmounts;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Proforma invoices screen (spec §5.2): the list of current revisions with what each is
 * owed, the header form, the PI's page (lines, actions, revisions, payments) and the printed
 * PI in MASDAR's layout (spec S1). Writes go through ProformaInvoiceService.
 */
class ProformaInvoiceController extends Controller
{
    use ScopesToVisibleClients;

    /** Statuses a current revision can have (superseded ones are never current). */
    private const LIST_STATUSES = ['draft', 'issued', 'accepted', 'confirmed', 'cancelled'];

    public function __construct(private ProformaInvoiceService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), self::LIST_STATUSES, true) ? $request->query('status') : null;
        $clientId = ctype_digit((string) $request->query('client')) ? (int) $request->query('client') : null;

        $pis = ProformaInvoice::query()->current()->withAmounts()->with('client')
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->whereIn('client_id', Party::query()->select('id')->where('account_manager_id', $user->id)))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('pi_number', 'ilike', "%{$search}%")->orWhere('po_number', 'ilike', "%{$search}%")))
            // Newest number first; a new revision keeps its number's place.
            ->orderByRaw('(SELECT MIN(f.id) FROM proforma_invoices f WHERE f.pi_number = proforma_invoices.pi_number) DESC')
            ->paginate(20)
            ->withQueryString();

        return view('proforma-invoices.index', [
            'pis' => $pis,
            'search' => $search,
            'status' => $status,
            'clientId' => $clientId,
            'statuses' => self::LIST_STATUSES,
            'clients' => $this->visibleClients($user)->get(['id', 'code', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        return view('proforma-invoices.create', $this->formData($request, null));
    }

    public function store(Request $request)
    {
        $pi = $this->service->createDraft($this->validateHeader($request), $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __('Draft :reference created. Add its lines.', ['reference' => $pi->reference()]));
    }

    public function show(Request $request, ProformaInvoice $pi)
    {
        $user = $request->user();
        $this->abortUnlessVisible($user, $pi);
        $pi->load(['client.accountManager', 'creator', 'issuer', 'lines.product', 'lines.supplier', 'attachments.uploader']);

        $revisions = $pi->revisionHistory();
        $totals = $pi->lineTotals();
        $paid = $pi->paidAmount();
        $canEdit = $user->can('proforma_invoices.edit');
        $seeSuppliers = Visibility::canSeeSupplierPrices($user);
        $lineForms = $pi->isDraft() && $canEdit;

        return view('proforma-invoices.show', [
            'pi' => $pi,
            'totals' => $totals,
            'revisions' => $revisions,
            'current' => $revisions->firstWhere('is_current', true),
            'pendingRevision' => $revisions->firstWhere('status', 'draft'),
            'paid' => $paid,
            'depositRequired' => PiAmounts::depositRequired($totals->amount, (string) $pi->deposit_percent),
            'outstanding' => PiAmounts::subtract($totals->amount, $paid),
            'payments' => $user->can('payments.view')
                ? Payment::query()->whereIn('proforma_invoice_id', $revisions->pluck('id'))->with(['moneyAccount', 'proformaInvoice'])
                    ->orderBy('paid_on')->orderBy('id')->get()
                : null,
            'canEdit' => $canEdit,
            'mayIssue' => $this->service->mayIssue($user),
            'seeSuppliers' => $seeSuppliers,
            'lineForms' => $lineForms,
            'products' => $lineForms ? Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit']) : collect(),
            'suppliers' => $lineForms && $seeSuppliers ? Party::query()->suppliers()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'units' => Lookup::options('unit'),
            'currencies' => Lookup::options('currency'),
        ]);
    }

    public function edit(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        if (! $pi->isDraft()) {
            return redirect()->route('proforma-invoices.show', $pi)->with('error', __('This PI has been issued and can no longer change. Make a new revision to change it.'));
        }

        return view('proforma-invoices.edit', $this->formData($request, $pi));
    }

    public function update(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $this->service->updateDraft($pi, $this->validateHeader($request, $pi), $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __('PI updated.'));
    }

    /** The printed PI: a standalone A4 page in MASDAR's layout (spec S1), always in English. */
    public function print(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $pi->load(['client', 'lines.product']);
        $setting = Setting::current();
        $business = BusinessSetting::current();

        return view('proforma-invoices.print', [
            'pi' => $pi,
            'totals' => $pi->lineTotals(),
            'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'companyName' => $setting->company_name,
            'address' => $business->company_address,
            'phone' => $business->company_phone,
            // English names of the units, whatever the reader's screen language.
            'unitNames' => Lookup::query()->where('list', 'unit')->pluck('name', 'code'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validateHeader(Request $request, ?ProformaInvoice $pi = null): array
    {
        $user = $request->user();
        // An active client the user may see; a draft may keep the client it has.
        $client = Rule::exists('parties', 'id')->where('is_client', true)
            ->where(fn ($q) => $q->where('active', true)->when($pi, fn ($q) => $q->orWhere('id', $pi->client_id)));
        if (Visibility::ownClientsOnly($user)) {
            $client->where('account_manager_id', $user->id);
        }

        return $request->validate([
            'client_id' => ['required', 'integer', $client],
            'shipped_to' => ['nullable', 'string', 'max:255'],
            'po_number' => ['nullable', 'string', 'max:80'],
            'po_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', Rule::in(array_keys(Lookup::options('currency')))],
            'deposit_percent' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'client_id.exists' => __('Pick one of your active clients.'),
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?ProformaInvoice $pi): array
    {
        $clients = $this->visibleClients($request->user(), activeOnly: true)->get(['id', 'code', 'name', 'deposit_percent', 'default_destination']);
        // A draft keeps showing its client even if that client was deactivated since.
        if ($pi && ! $clients->contains('id', $pi->client_id)) {
            $clients->push($pi->client);
        }

        return [
            'pi' => $pi,
            'clients' => $clients,
            'currencies' => Lookup::options('currency'),
            'business' => BusinessSetting::current(),
        ];
    }
}
