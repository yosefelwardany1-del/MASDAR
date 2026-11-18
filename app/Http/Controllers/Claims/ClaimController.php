<?php

namespace App\Http\Controllers\Claims;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Claims\ClaimService;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Quality claims (spec §5.8): a list by status, opening one from a purchase order or a PI, its
 * page with the status log, the notes that settle it and its photos.
 */
class ClaimController extends Controller
{
    public function __construct(private ClaimService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $status = in_array($request->query('status'), [...Claim::STATUSES, 'all', 'active'], true) ? $request->query('status') : 'active';
        $latest = '(SELECT ce.status FROM claim_events ce WHERE ce.claim_id = claims.id ORDER BY ce.id DESC LIMIT 1)';

        $claims = $this->visible(Claim::query(), $user)
            ->when($status === 'active', fn ($q) => $q->whereRaw("{$latest} NOT IN ('closed', 'rejected')"))
            ->when(in_array($status, Claim::STATUSES, true), fn ($q) => $q->whereRaw("{$latest} = ?", [$status]))
            ->with(['latestEvent', 'supplier', 'client', 'supplierOrder', 'proformaInvoice'])
            ->orderByDesc('opened_on')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('claims.index', [
            'claims' => $claims, 'status' => $status,
            'kinds' => Lookup::options('claim_type'),
            'seesSuppliers' => Visibility::canSeeSupplierPrices($user),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $preset = [
            'supplier_order_id' => $request->integer('order') ?: null,
            'proforma_invoice_id' => $request->integer('pi') ?: null,
        ];

        return view('claims.create', $this->formData($user) + ['claim' => null, 'preset' => $preset]);
    }

    public function store(Request $request)
    {
        $claim = $this->service->open($request->all(), $request->user());

        return redirect()->route('claims.show', $claim)->with('success', __('Claim :number opened.', ['number' => $claim->number]));
    }

    public function show(Request $request, Claim $claim)
    {
        $user = $request->user();
        abort_unless($this->service->visibleTo($claim, $user), 404);
        $claim->load(['events.user', 'supplier', 'client', 'supplierOrder', 'proformaInvoice', 'creator', 'notes.lines', 'attachments.uploader']);

        return view('claims.show', [
            'claim' => $claim,
            'kinds' => Lookup::options('claim_type'),
            'moves' => ClaimService::MOVES[$claim->status()] ?? [],
            'seesSuppliers' => Visibility::canSeeSupplierPrices($user),
        ]);
    }

    public function edit(Request $request, Claim $claim)
    {
        $user = $request->user();
        abort_unless($this->service->visibleTo($claim, $user), 404);

        return view('claims.edit', $this->formData($user) + ['claim' => $claim, 'preset' => []]);
    }

    public function update(Request $request, Claim $claim)
    {
        $this->service->update($claim, $request->all(), $request->user());

        return redirect()->route('claims.show', $claim)->with('success', __('Claim updated.'));
    }

    public function move(Request $request, Claim $claim)
    {
        $data = $request->validate([
            'status' => 'required|string|in:'.implode(',', Claim::STATUSES),
            'note' => 'nullable|string|max:2000',
            'agreed_amount' => 'nullable|string|max:30',
        ]);
        $this->service->move($claim, $data['status'], $request->user(), $data['note'] ?? null, $data['agreed_amount'] ?? null);

        return redirect()->route('claims.show', $claim)->with('success', __('Claim :number is now :status.', ['number' => $claim->number, 'status' => Claim::statusLabel($data['status'])]));
    }

    private function visible(Builder $query, User $user): Builder
    {
        if (! Visibility::canSeeSupplierPrices($user)) {
            $query->whereNull('supplier_id');
        }
        if (Visibility::ownClientsOnly($user)) {
            $query->whereHas('client', fn ($q) => $q->where('account_manager_id', $user->id));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        $seesSuppliers = Visibility::canSeeSupplierPrices($user);

        return [
            'kinds' => Lookup::options('claim_type'),
            'currencies' => Lookup::options('currency'),
            'seesSuppliers' => $seesSuppliers,
            'orders' => $seesSuppliers
                ? SupplierOrder::query()->with('supplier:id,name')->where('status', '<>', 'cancelled')->orderByDesc('id')->limit(300)->get(['id', 'number', 'supplier_id', 'currency'])
                : collect(),
            'suppliers' => $seesSuppliers ? Party::query()->suppliers()->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'pis' => ProformaInvoice::query()->current()->whereIn('status', ['issued', 'accepted', 'confirmed'])
                ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
                ->with('client:id,name')->orderByDesc('id')->limit(300)->get(['id', 'pi_number', 'revision', 'client_id', 'currency']),
            'clients' => Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
