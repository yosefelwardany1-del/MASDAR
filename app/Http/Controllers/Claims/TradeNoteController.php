<?php

namespace App\Http\Controllers\Claims;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Claim;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\Setting;
use App\Models\SupplierOrder;
use App\Models\TradeNote;
use App\Models\TradeNoteLine;
use App\Models\User;
use App\Services\Claims\NoteQueries;
use App\Services\Claims\TradeNoteService;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Credit notes to clients and debit notes on factories (spec §5.8): the list, a draft with its
 * lines, issuing and voiding (claims.approve), and the printed note (English; a debit note in
 * English and Chinese, for the factory).
 */
class TradeNoteController extends Controller
{
    public function __construct(private TradeNoteService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $kind = $request->query('kind') === 'debit' ? 'debit' : 'credit';
        abort_if($kind === 'debit' && ! Visibility::canSeeSupplierPrices($user), 403);

        $notes = $this->visible(TradeNote::query()->where('kind', $kind), $user)
            ->with(['party', 'proformaInvoice', 'supplierOrder', 'claim'])
            ->withSum('lines', 'amount')
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('trade-notes.index', ['notes' => $notes, 'kind' => $kind, 'seesSuppliers' => Visibility::canSeeSupplierPrices($user)]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $kind = $request->query('kind') === 'debit' ? 'debit' : 'credit';
        abort_if($kind === 'debit' && ! Visibility::canSeeSupplierPrices($user), 403);
        $claim = $request->integer('claim') ? Claim::query()->find($request->integer('claim')) : null;

        return view('trade-notes.create', $this->formData($user, $kind) + [
            'kind' => $kind,
            'preset' => [
                'claim_id' => $claim?->id,
                'proforma_invoice_id' => $request->integer('pi') ?: $claim?->proforma_invoice_id,
                'supplier_order_id' => $request->integer('order') ?: $claim?->supplier_order_id,
                'party_id' => $kind === 'credit' ? $claim?->client_id : $claim?->supplier_id,
                'reason' => $claim ? $claim->number.' — '.($claim->description) : null,
                'amount' => $claim ? ((string) ($claim->latestEvent?->agreed_amount ?? $claim->claimed_amount ?? '')) : null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $kind = $request->input('kind') === 'debit' ? 'debit' : 'credit';
        $data = $request->all();
        // The first line, typed on the same form.
        if (filled($request->input('line_description')) || filled($request->input('line_amount'))) {
            $data['lines'] = [['description' => $request->input('line_description'), 'quantity' => '1', 'unit_price' => $request->input('line_amount')]];
        }
        $note = $this->service->createDraft($kind, $data, $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Draft note created. Check its lines, then issue it.'));
    }

    public function show(Request $request, TradeNote $note)
    {
        $user = $request->user();
        abort_unless($note->visibleTo($user), 404);
        $note->load(['party', 'proformaInvoice', 'supplierOrder', 'claim', 'lines', 'creator', 'issuer', 'attachments.uploader']);

        return view('trade-notes.show', $this->formData($user, $note->kind) + [
            'note' => $note,
            'total' => $note->total(),
            'posted' => app(NoteQueries::class)->isPosted($note),
            'editable' => $note->isDraft() && $user->can('claims.edit'),
        ]);
    }

    public function update(Request $request, TradeNote $note)
    {
        $this->service->updateDraft($note, $request->all(), $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Note updated.'));
    }

    public function destroy(Request $request, TradeNote $note)
    {
        $kind = $note->kind;
        $this->service->deleteDraft($note, $request->user());

        return redirect()->route('trade-notes.index', ['kind' => $kind])->with('success', __('Draft deleted.'));
    }

    public function storeLine(Request $request, TradeNote $note)
    {
        $this->service->addLine($note, $request->only(['description', 'quantity', 'unit_price']), $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Line added.'));
    }

    public function updateLine(Request $request, TradeNote $note, TradeNoteLine $line)
    {
        abort_unless($line->trade_note_id === $note->id, 404);
        $this->service->updateLine($line, $request->only(['description', 'quantity', 'unit_price']), $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Line updated.'));
    }

    public function destroyLine(Request $request, TradeNote $note, TradeNoteLine $line)
    {
        abort_unless($line->trade_note_id === $note->id, 404);
        $this->service->deleteLine($line, $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Line removed.'));
    }

    public function issue(Request $request, TradeNote $note)
    {
        $data = $request->validate(['issued_on' => 'nullable|date_format:Y-m-d']);
        $note = $this->service->issue($note, $request->user(), $data['issued_on'] ?? null);

        return redirect()->route('trade-notes.show', $note)->with('success', __('Note :number issued.', ['number' => $note->number]));
    }

    public function void(Request $request, TradeNote $note)
    {
        $data = $request->validate(['void_reason' => 'required|string|max:2000']);
        $this->service->void($note, $data['void_reason'], $request->user());

        return redirect()->route('trade-notes.show', $note)->with('success', __('Note :number is void.', ['number' => $note->number]));
    }

    /** The printed note: English; a debit note also in Chinese, for the factory. */
    public function print(Request $request, TradeNote $note)
    {
        abort_unless($note->visibleTo($request->user()), 404);
        $note->load(['party', 'proformaInvoice', 'supplierOrder', 'claim', 'lines']);
        $setting = Setting::current();
        $business = BusinessSetting::current();

        return view('trade-notes.print', [
            'note' => $note,
            'total' => $note->total(),
            'companyName' => $setting->company_name,
            'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'address' => $business->company_address,
            'phone' => $business->company_phone,
        ]);
    }

    private function visible(Builder $query, User $user): Builder
    {
        if (Visibility::ownClientsOnly($user)) {
            $query->whereHas('party', fn ($q) => $q->where('account_manager_id', $user->id));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function formData(User $user, string $kind): array
    {
        return [
            'currencies' => Lookup::options('currency'),
            'claims' => Claim::query()->orderByDesc('id')->limit(200)
                ->when($kind === 'credit', fn ($q) => $q->whereNotNull('client_id')->when(Visibility::ownClientsOnly($user), fn ($q) => $q->whereHas('client', fn ($c) => $c->where('account_manager_id', $user->id))))
                ->when($kind === 'debit', fn ($q) => $q->whereNotNull('supplier_id'))
                ->get(['id', 'number', 'description']),
            'pis' => $kind === 'credit'
                ? ProformaInvoice::query()->current()->whereIn('status', ['issued', 'accepted', 'confirmed'])
                    ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
                    ->with('client:id,name')->orderByDesc('id')->limit(300)->get(['id', 'pi_number', 'revision', 'client_id', 'currency'])
                : collect(),
            'orders' => $kind === 'debit'
                ? SupplierOrder::query()->with('supplier:id,name')->where('status', '<>', 'cancelled')->orderByDesc('id')->limit(300)->get(['id', 'number', 'supplier_id', 'currency'])
                : collect(),
            'parties' => $kind === 'credit'
                ? Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'code', 'name', 'currency'])
                : Party::query()->suppliers()->orderBy('name')->get(['id', 'code', 'name', 'currency']),
        ];
    }
}
