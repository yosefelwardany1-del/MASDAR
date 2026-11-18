<?php

namespace App\Http\Controllers\Sourcing;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SourcingRequest;
use App\Models\SupplierQuote;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\Ledger;
use App\Services\Sourcing\SourcingService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;

/**
 * Factory quotes (spec §5.8): sourcing requests, the quotes gathered for each compared side by
 * side, the factory chosen, and its price put on a quotation line. Factories' prices: only for
 * those who see supplier prices.
 */
class SourcingController extends Controller
{
    public function __construct(private SourcingService $service, private Ledger $ledger) {}

    public function index(Request $request)
    {
        $this->assertSees($request);
        $status = in_array($request->query('status'), ['open', 'chosen', 'closed', 'all'], true) ? $request->query('status') : 'open';
        $requests = SourcingRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['client', 'chosenQuote.supplier'])
            ->withCount('quotes')->withMin('quotes', 'unit_price')
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('sourcing.index', compact('requests', 'status'));
    }

    public function create(Request $request)
    {
        $this->assertSees($request);

        return view('sourcing.create', $this->formData($request) + ['sourcing' => null]);
    }

    public function store(Request $request)
    {
        $sourcing = $this->service->create($request->all(), $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __(':number created. Add the factories\' quotes.', ['number' => $sourcing->number]));
    }

    public function show(Request $request, SourcingRequest $sourcing)
    {
        $this->assertSees($request);
        $sourcing->load(['quotes.supplier', 'client', 'product', 'creator', 'quotationLine.quotation', 'attachments.uploader']);
        $book = $this->ledger->bookCurrency();
        $rates = $this->ledger->latestRates();
        $inBook = function (SupplierQuote $q) use ($book, $rates): ?string {
            if ($q->currency === $book) {
                return (string) $q->unit_price;
            }

            return isset($rates[$q->currency]) ? Decimal::mul((string) $q->unit_price, $rates[$q->currency], 6) : null;
        };
        $quotes = $sourcing->quotes->map(fn (SupplierQuote $q) => ['quote' => $q, 'book' => $inBook($q)]);
        $cheapest = $quotes->filter(fn ($r) => $r['book'] !== null && ! $r['quote']->isExpired())
            ->sortBy(fn ($r) => (float) $r['book'])->first()['quote'] ?? null;
        $target = $sourcing->target_price !== null
            ? ($sourcing->target_currency === $book ? (string) $sourcing->target_price : (isset($rates[$sourcing->target_currency]) ? Decimal::mul((string) $sourcing->target_price, $rates[$sourcing->target_currency], 6) : null))
            : null;

        return view('sourcing.show', $this->formData($request) + [
            'sourcing' => $sourcing, 'quotes' => $quotes, 'cheapest' => $cheapest, 'book' => $book, 'targetInBook' => $target,
            'quotations' => $sourcing->status === 'chosen' && ! $sourcing->quotation_line_id
                ? Quotation::query()->where('status', 'draft')->when($sourcing->client_id, fn ($q, $id) => $q->where('client_id', $id))
                    ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $request->user()))->with('client')->orderByDesc('id')->limit(100)->get()
                : collect(),
        ]);
    }

    public function update(Request $request, SourcingRequest $sourcing)
    {
        $this->service->update($sourcing, $request->all(), $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Saved.'));
    }

    public function storeQuote(Request $request, SourcingRequest $sourcing)
    {
        $this->service->addQuote($sourcing, $request->all(), $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Quote added.'));
    }

    public function updateQuote(Request $request, SourcingRequest $sourcing, SupplierQuote $quote)
    {
        abort_unless($quote->sourcing_request_id === $sourcing->id, 404);
        $this->service->updateQuote($quote, $request->all(), $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Quote updated.'));
    }

    public function destroyQuote(Request $request, SourcingRequest $sourcing, SupplierQuote $quote)
    {
        abort_unless($quote->sourcing_request_id === $sourcing->id, 404);
        $this->service->deleteQuote($quote, $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Quote removed.'));
    }

    public function choose(Request $request, SourcingRequest $sourcing, SupplierQuote $quote)
    {
        abort_unless($quote->sourcing_request_id === $sourcing->id, 404);
        $this->service->choose($quote, $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __(':supplier chosen.', ['supplier' => $quote->supplier?->name]));
    }

    public function close(Request $request, SourcingRequest $sourcing)
    {
        $this->service->close($sourcing, $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Closed without choosing.'));
    }

    public function reopen(Request $request, SourcingRequest $sourcing)
    {
        $this->service->reopen($sourcing, $request->user());

        return redirect()->route('sourcing.show', $sourcing)->with('success', __('Reopened.'));
    }

    public function toQuotation(Request $request, SourcingRequest $sourcing)
    {
        $data = $request->validate(['quotation_id' => 'required|integer|exists:quotations,id', 'unit_price' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/']]);
        $quotation = Quotation::query()->findOrFail($data['quotation_id']);
        abort_unless(Party::query()->clients()->visibleTo($request->user())->whereKey($quotation->client_id)->exists(), 404);
        $this->service->toQuotation($sourcing, $quotation, $data['unit_price'], $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Line added from :number.', ['number' => $sourcing->number]));
    }

    private function assertSees(Request $request): void
    {
        abort_unless(Visibility::canSeeSupplierPrices($request->user()), 403, __('Factory quotes show factories\' prices: they are for those who may see supplier prices.'));
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        return [
            'units' => Lookup::options('unit'),
            'currencies' => Lookup::options('currency'),
            'products' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'clients' => Visibility::scopeClients(Party::query()->clients(), $request->user())->orderBy('name')->get(['id', 'code', 'name']),
            'suppliers' => Party::query()->suppliers()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency']),
        ];
    }
}
