<?php

namespace App\Http\Controllers\Samples;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Sample;
use App\Services\Samples\SampleService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;

/** Samples (spec §5.8): requested, made at the factory, received, sent to the client, answered. */
class SampleController extends Controller
{
    public function __construct(private SampleService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $open = $request->query('show') !== 'all';
        $latest = '(SELECT e.status FROM sample_events e WHERE e.sample_id = samples.id ORDER BY e.id DESC LIMIT 1)';
        $samples = Sample::query()
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->whereHas('client', fn ($c) => $c->where('account_manager_id', $user->id)))
            ->when($open, fn ($q) => $q->whereRaw("{$latest} NOT IN ('approved', 'rejected', 'cancelled')"))
            ->with(['client', 'latestEvent', 'supplier'])->orderByDesc('id')->paginate(30)->withQueryString();

        return view('samples.index', ['samples' => $samples, 'open' => $open, 'seesSuppliers' => Visibility::canSeeSupplierPrices($user)]);
    }

    public function create(Request $request)
    {
        $user = $request->user();

        return view('samples.create', [
            'clients' => Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'code', 'name']),
            'suppliers' => Visibility::canSeeSupplierPrices($user) ? Party::query()->suppliers()->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'products' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'pis' => ProformaInvoice::query()->current()->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))->orderByDesc('id')->limit(200)->get(['id', 'pi_number', 'revision']),
        ]);
    }

    public function store(Request $request)
    {
        $sample = $this->service->create($request->all(), $request->user());

        return redirect()->route('samples.show', $sample)->with('success', __('Sample :number recorded.', ['number' => $sample->number]));
    }

    public function show(Request $request, Sample $sample)
    {
        $user = $request->user();
        abort_if($sample->client_id && Visibility::ownClientsOnly($user) && (int) $sample->client?->account_manager_id !== (int) $user->id, 404);
        $sample->load(['events.user', 'client', 'supplier', 'product', 'proformaInvoice', 'attachments.uploader']);

        return view('samples.show', ['sample' => $sample, 'seesSuppliers' => Visibility::canSeeSupplierPrices($user)]);
    }

    public function record(Request $request, Sample $sample)
    {
        $this->service->record($sample, $request->only(['status', 'happened_on', 'courier', 'tracking_no', 'note']), $request->user());

        return back()->with('success', __('Saved.'));
    }
}
