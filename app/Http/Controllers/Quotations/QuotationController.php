<?php

namespace App\Http\Controllers\Quotations;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Services\Quotations\QuotationService;
use App\Support\Access\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Quotations (screen `quotations`, spec §5.2): price offers before the PI. A user sees the
 * quotations of the clients they may see; supplier costs on lines only with supplier prices.
 */
class QuotationController extends Controller
{
    private const STATUSES = ['all', 'draft', 'sent', 'accepted', 'declined', 'expired'];

    public function __construct(private QuotationService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all';
        $today = now()->toDateString();

        $quotations = Quotation::query()->whereIn('client_id', Party::query()->clients()->visibleTo($user)->select('parties.id'))
            ->with(['client:id,code,name', 'proformaInvoice:id,pi_number,revision'])->withCount('lines')
            ->when($status === 'expired', fn ($q) => $q->where('status', 'sent')->where('valid_until', '<', $today))
            ->when($status === 'sent', fn ($q) => $q->where('status', 'sent')->where('valid_until', '>=', $today))
            ->when(! in_array($status, ['all', 'expired', 'sent'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('quoted_on')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('quotations.index', ['quotations' => $quotations, 'status' => $status]);
    }

    public function create(Request $request)
    {
        return view('quotations.create', $this->headerData($request, null) + ['preset' => $request->integer('client') ?: null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $quotation = $this->service->create($this->validatedHeader($request), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Quotation started. Add its lines, then send it.'));
    }

    public function show(Request $request, Quotation $quotation)
    {
        $this->visible($request, $quotation);
        $user = $request->user();
        $quotation->load(['client', 'lines.product', 'lines.supplier', 'proformaInvoice', 'creator']);

        return view('quotations.show', [
            'quotation' => $quotation,
            'seesSupplier' => Visibility::canSeeSupplierPrices($user),
            'units' => Lookup::options('unit'),
            'currencies' => Lookup::options('currency'),
            'products' => $quotation->isDraft() ? Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'sale_price', 'sale_currency']) : collect(),
            'suppliers' => Visibility::canSeeSupplierPrices($user) && $quotation->isDraft() ? Party::query()->suppliers()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
        ] + $this->headerData($request, $quotation));
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $this->service->update($quotation, $this->validatedHeader($request), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Saved.'));
    }

    public function destroy(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $this->service->deleteDraft($quotation, $request->user());

        return redirect()->route('quotations.index')->with('success', __('Draft deleted.'));
    }

    public function storeLine(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $this->service->addLine($quotation, $request->validate($this->lineRules()), $request->user());

        return redirect()->to(route('quotations.show', $quotation).'#lines')->with('success', __('Line added.'));
    }

    public function updateLine(Request $request, Quotation $quotation, QuotationLine $line): RedirectResponse
    {
        abort_unless($line->quotation_id === $quotation->id, 404);
        $this->visible($request, $quotation);
        $this->service->updateLine($line, $request->validate($this->lineRules()), $request->user());

        return redirect()->to(route('quotations.show', $quotation).'#lines')->with('success', __('Line saved.'));
    }

    public function destroyLine(Request $request, Quotation $quotation, QuotationLine $line): RedirectResponse
    {
        abort_unless($line->quotation_id === $quotation->id, 404);
        $this->visible($request, $quotation);
        $this->service->deleteLine($line, $request->user());

        return redirect()->to(route('quotations.show', $quotation).'#lines')->with('success', __('Line removed.'));
    }

    public function send(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $quotation = $this->service->send($quotation, $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Quotation :number is ready to send: print it or save it as PDF.', ['number' => $quotation->number]));
    }

    public function accept(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $this->service->accept($quotation, $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Marked as accepted.'));
    }

    public function decline(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $this->service->decline($quotation, $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Marked as declined.'));
    }

    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $pi = $this->service->convert($quotation, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __('PI :pi drafted from the quotation. Check the quantities, then issue it.', ['pi' => $pi->reference()]));
    }

    public function duplicate(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->visible($request, $quotation);
        $copy = $this->service->duplicate($quotation, $request->user());

        return redirect()->route('quotations.show', $copy)->with('success', __('A new draft, copied from :number.', ['number' => $quotation->number]));
    }

    /** The quotation as sent to the client: English, in MASDAR's layout, like the PI. */
    public function print(Request $request, Quotation $quotation)
    {
        $this->visible($request, $quotation);
        $quotation->load(['client', 'lines.product']);
        $setting = Setting::current();
        $business = BusinessSetting::current();

        return view('quotations.print', [
            'quotation' => $quotation,
            'companyName' => $setting->company_name,
            'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'address' => $business->company_address,
            'phone' => $business->company_phone,
            'unitNames' => Lookup::query()->where('list', 'unit')->pluck('name', 'code')->all(),
        ]);
    }

    private function visible(Request $request, Quotation $quotation): void
    {
        abort_unless(Party::query()->clients()->visibleTo($request->user())->whereKey($quotation->client_id)->exists(), 404);
    }

    private function headerData(Request $request, ?Quotation $quotation): array
    {
        return [
            'clients' => Party::query()->clients()->visibleTo($request->user())->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency']),
            'currencies' => Lookup::options('currency'),
            'settings' => BusinessSetting::current(),
        ];
    }

    private function validatedHeader(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
            'quoted_on' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'incoterm' => ['nullable', 'string', 'max:60'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function lineRules(): array
    {
        $number = ['nullable', 'string', 'max:30'];

        return [
            'product_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'qty_per_carton' => $number, 'cartons' => $number, 'moq' => $number, 'unit_price' => $number,
            'cbm_per_carton' => $number, 'kg_per_carton' => $number,
            'supplier_id' => ['nullable', 'integer'], 'supplier_price' => $number, 'supplier_currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
