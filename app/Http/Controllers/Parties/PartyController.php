<?php

namespace App\Http\Controllers\Parties;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\ClientStageChange;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Services\Crm\CrmQueries;
use App\Services\Finance\PaymentQueries;
use App\Services\Parties\PartyKind;
use App\Services\Parties\PartyService;
use App\Services\WhatsApp\PhoneNumber;
use App\Support\Access\Visibility;
use App\Support\SameName;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One controller for the three party screens (spec §5.1): clients, suppliers and shipping
 * companies are one registry shown three times, so each screen can be granted on its own (§6).
 * A subclass names its kind; the route middleware has already checked `{screen}.view|edit`.
 *
 * Two data rules sit on top of the screen grants:
 *  - clients are narrowed by Visibility::scopeClients, so another account manager's client is a
 *    404, not a 403 that would confirm it exists;
 *  - a party's fields for another kind (a client that is also a supplier) are written only by a
 *    user who may edit that kind's screen, and supplier bank details only by one who may see
 *    supplier prices (Visibility::canSeeSupplierPrices).
 *
 * Parties are deactivated, never deleted: documents refer to them.
 */
abstract class PartyController extends Controller
{
    public function __construct(protected PartyService $parties) {}

    abstract protected function kind(): PartyKind;

    /**
     * B23: another party of this kind with the same name (case and spacing aside) is said before
     * saving, with a link to it; ticking "Save anyway" saves it all the same.
     */
    private function warnIfSameName(Request $request, string $name, ?Party $self): void
    {
        if ($request->boolean('save_anyway') || trim($name) === '') {
            return;
        }
        $kind = $this->kind();
        $same = SameName::scope(Party::query()->where($kind->column(), true), $name)
            ->when($self, fn ($q) => $q->whereKeyNot($self->id))->first();
        if ($same) {
            session()->flash('same_name', ['url' => route($kind->routePrefix().'.show', $same), 'label' => $same->code.' — '.$same->name]);
            throw ValidationException::withMessages(['name' => __(':name already exists (:code). Open it, or tick "Save anyway" to add another.', ['name' => $same->name, 'code' => $same->code])]);
        }
    }

    public function index(Request $request)
    {
        $kind = $this->kind();
        $user = $request->user();
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => in_array($request->query('active'), ['1', '0', 'all'], true) ? $request->query('active') : '1',
            'country' => (string) $request->query('country', ''),
            'stage' => (string) $request->query('stage', ''),
            'manager' => ctype_digit((string) $request->query('manager', '')) ? (int) $request->query('manager') : null,
        ];

        $query = $this->scoped($user)->with(['contacts', 'accountManager']);
        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $digits = preg_replace('/\D+/', '', $filters['search']) ?? '';
            $query->where(fn ($q) => $q
                ->where('code', 'ilike', $term)
                ->orWhere('name', 'ilike', $term)
                ->orWhere('name_local', 'ilike', $term)
                ->orWhereHas('contacts', fn ($c) => $c->where(fn ($c) => $c
                    ->where('phone', 'ilike', $term)->orWhere('whatsapp', 'ilike', $term)
                    ->when(strlen($digits) >= 3, fn ($c) => $c->orWhere('phone', 'like', "%{$digits}%")->orWhere('whatsapp', 'like', "%{$digits}%")))));
        }
        $query->when($filters['active'] !== 'all', fn ($q) => $q->where('active', $filters['active'] === '1'))
            ->when($filters['country'] !== '', fn ($q) => $q->where('country', $filters['country']));
        if ($kind === PartyKind::Client) {
            $query->when($filters['manager'], fn ($q) => $q->where('account_manager_id', $filters['manager']));
            // B11: the stage now — typed, or read from the documents — as on the client page and the Pipeline.
            if ($filters['stage'] !== '') {
                $query->whereIn('parties.id', app(CrmQueries::class)->clientIdsAtStage($query, $filters['stage']));
            }
        }

        $parties = $query->orderBy('name')->paginate(25)->withQueryString();
        $crmStages = $kind === PartyKind::Client ? app(CrmQueries::class)->stagesFor($parties->pluck('id')->all()) : [];

        return view('parties.index', [
            'kind' => $kind,
            'parties' => $parties,
            'crmStages' => $crmStages,
            'filters' => $filters,
            'countries' => Lookup::options('country'),
            'stages' => Lookup::options('client_stage'),
            'shippingKinds' => Lookup::options('shipping_company_kind'),
            'managers' => $kind === PartyKind::Client && ! Visibility::ownClientsOnly($user) ? $this->activeUsers() : collect(),
        ]);
    }

    public function create(Request $request)
    {
        return view('parties.create', $this->formData($request, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $this->warnIfSameName($request, $data['name'] ?? '', null);
        $party = $this->parties->create($data, $request->user());

        return $this->redirectToParty($request, $party, __(':code created.', ['code' => $party->code]));
    }

    public function show(Request $request, string $id)
    {
        $kind = $this->kind();
        $user = $request->user();
        $party = $this->find($request, $id)->load(['contacts', 'accountManager', 'attachments.uploader']);

        $related = [];
        if ($party->is_client && $user->can('proforma_invoices.view')) {
            $related['proforma_invoices'] = $party->proformaInvoices()->where('is_current', true)->latest('id')->limit(25)->get();
        }
        if ($party->is_supplier && $user->can('supplier_orders.view')) {
            $related['supplier_orders'] = $party->supplierOrders()->latest('id')->limit(25)->get();
        }
        if ($party->is_shipping && $user->can('containers.view')) {
            $related['shipments'] = $party->shipments()->with('containers')->latest('id')->limit(25)->get();
        }

        return view('parties.show', [
            'kind' => $kind,
            'party' => $party,
            'interactions' => $party->interactions()->with('user')->limit(100)->get(),
            'openTasks' => $party->tasks()->open()->with('assignee')->orderBy('due_on')->get(),
            'related' => $related,
            'canSeeBank' => Visibility::canSeeSupplierPrices($user),
            'money' => $user->can('payments.view') ? $this->money($party, $user) : null,
            'channels' => Lookup::options('interaction_channel'),
            'users' => $user->can('crm.edit') ? $this->activeUsers() : collect(),
            'labels' => $this->lookupLabels(),
            // The stage now (set by a person or read from the documents) and the stages a person set (spec §5.1).
            'crmStage' => $party->is_client ? (app(CrmQueries::class)->stagesFor([$party->id])[$party->id] ?? null) : null,
            'stageChanges' => $party->is_client ? ClientStageChange::query()->where('party_id', $party->id)->with('user:id,name')->orderByDesc('id')->limit(20)->get() : collect(),
        ]);
    }

    public function edit(Request $request, string $id)
    {
        return view('parties.edit', $this->formData($request, $this->find($request, $id)));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $party = $this->find($request, $id);
        $data = $this->validated($request, $party);
        $this->warnIfSameName($request, $data['name'] ?? $party->name, $party);
        $this->parties->update($party, $data, $request->user());

        return $this->redirectToParty($request, $party, __(':code saved.', ['code' => $party->code]));
    }

    public function deactivate(Request $request, string $party): RedirectResponse
    {
        $model = $this->parties->deactivate($this->find($request, $party));

        return redirect()->route($this->kind()->routePrefix().'.show', $model)->with('success', __(':code deactivated.', ['code' => $model->code]));
    }

    public function reactivate(Request $request, string $party): RedirectResponse
    {
        $model = $this->parties->reactivate($this->find($request, $party));

        return redirect()->route($this->kind()->routePrefix().'.show', $model)->with('success', __(':code reactivated.', ['code' => $model->code]));
    }

    public function storeContact(Request $request, string $party): RedirectResponse
    {
        $model = $this->find($request, $party);
        $this->parties->addContact($model, $this->validatedContact($request, 'contact'));

        return redirect()->to(route($this->kind()->routePrefix().'.show', $model).'#contacts')->with('success', __('Contact added.'));
    }

    public function updateContact(Request $request, string $party, string $contact): RedirectResponse
    {
        $model = $this->find($request, $party);
        $row = $this->findContact($model, $contact);
        $this->parties->updateContact($row, $this->validatedContact($request, 'contact_'.$row->id));

        return redirect()->to(route($this->kind()->routePrefix().'.show', $model).'#contacts')->with('success', __('Contact saved.'));
    }

    public function destroyContact(Request $request, string $party, string $contact): RedirectResponse
    {
        $model = $this->find($request, $party);
        $this->parties->deleteContact($this->findContact($model, $contact));

        return redirect()->to(route($this->kind()->routePrefix().'.show', $model).'#contacts')->with('success', __('Contact deleted.'));
    }

    /**
     * The Money tab, read-only: the payments linked to the party (payments.party_id) that $user
     * may see (PaymentQueries::whereVisibleTo), what counted per currency (whereEffective), and
     * for a client what each open PI still owes (ProformaInvoice::withAmounts). Nothing writes.
     *
     * @return array<string, mixed>
     */
    private function money(Party $party, User $user): array
    {
        $queries = app(PaymentQueries::class);
        $visible = fn () => $queries->whereVisibleTo(Payment::query()->where('payments.party_id', $party->id), $user);

        $totals = $queries->whereEffective($visible()->toBase())
            ->selectRaw(<<<'SQL'
                payments.currency,
                COALESCE(SUM(payments.signed_amount) FILTER (WHERE payments.direction = 'in'), 0)::numeric(18,4) AS money_in,
                (-COALESCE(SUM(payments.signed_amount) FILTER (WHERE payments.direction = 'out'), 0))::numeric(18,4) AS money_out
            SQL)
            ->groupBy('payments.currency')->orderBy('payments.currency')->get();

        $kind = match ($this->kind()) {
            PartyKind::Client => 'client_receipt',
            PartyKind::Supplier => 'supplier_payment',
            PartyKind::Shipping => 'shipping_payment',
        };

        return [
            'payments' => $visible()->with(['moneyAccount', 'proformaInvoice', 'supplierOrder', 'container', 'approval', 'reversal', 'reverses.approval'])
                ->orderByDesc('paid_on')->orderByDesc('id')->limit(50)->get(),
            'count' => $visible()->count(),
            'totals' => $totals,
            'pis' => $party->is_client && $user->can('proforma_invoices.view')
                ? ProformaInvoice::query()->current()->withAmounts()->where('client_id', $party->id)
                    ->whereIn('status', ['issued', 'accepted', 'confirmed'])->orderByDesc('id')->get()
                : collect(),
            'approvalRequired' => $queries->approvalRequired(),
            'queries' => $queries,
            'recordKind' => $user->can('payments.edit') && in_array($kind, $queries->recordableKinds($user), true) ? $kind : null,
        ];
    }

    // ---- lookups -------------------------------------------------------------------

    /** Parties of this screen's kind that $user may see. */
    protected function scoped(User $user)
    {
        $query = Party::query()->where($this->kind()->column(), true);

        return $this->kind() === PartyKind::Client ? Visibility::scopeClients($query, $user) : $query;
    }

    /** A party of this screen's kind that the user may see, or a 404. */
    protected function find(Request $request, string $id): Party
    {
        abort_unless(ctype_digit($id), 404);

        return $this->scoped($request->user())->findOrFail((int) $id);
    }

    private function findContact(Party $party, string $id): PartyContact
    {
        abort_unless(ctype_digit($id), 404);

        return PartyContact::where('party_id', $party->id)->findOrFail((int) $id);
    }

    private function activeUsers()
    {
        return User::where('status', 'active')->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, array<string, string>> list => code => name, for the show page */
    private function lookupLabels(): array
    {
        $lists = ['country', 'currency', 'party_source', 'client_stage', 'shipping_company_kind', 'interaction_channel'];

        return collect($lists)->mapWithKeys(fn ($list) => [$list => Lookup::query()->ofList($list)->get()
            ->mapWithKeys(fn (Lookup $l) => [$l->code => $l->displayName()])->all()])->all();
    }

    // ---- the form ------------------------------------------------------------------

    private function formData(Request $request, ?Party $party): array
    {
        $user = $request->user();
        $kind = $this->kind();
        $settings = BusinessSetting::current();
        $defaultCurrency = match ($kind) {
            PartyKind::Client => $settings->pi_currency,
            PartyKind::Supplier => $settings->supplier_currency,
            PartyKind::Shipping => null,
        };

        return [
            'kind' => $kind,
            'party' => $party,
            'flags' => $this->currentFlags($party),
            'editableKinds' => collect(PartyKind::cases())->filter(fn (PartyKind $k) => $this->mayWriteKind($user, $k))->map->value->values()->all(),
            'canSeeBank' => Visibility::canSeeSupplierPrices($user),
            'ownClientsOnly' => Visibility::ownClientsOnly($user),
            'defaultCurrency' => $party ? $party->currency : $defaultCurrency,
            'defaultDeposit' => $settings->default_deposit_percent,
            'options' => [
                'country' => Lookup::options('country'),
                'currency' => Lookup::options('currency'),
                'party_source' => Lookup::options('party_source'),
                // Only the stages a person sets: those from "PI sent" on follow the documents (spec §5.1).
                'client_stage' => Lookup::query()->ofList('client_stage')->where('active', true)->get()
                    ->reject(fn (Lookup $l) => CrmQueries::isDocumentStage($l->code, $l))
                    ->mapWithKeys(fn (Lookup $l) => [$l->code => $l->displayName()])->all(),
                'shipping_company_kind' => Lookup::options('shipping_company_kind'),
            ],
            'users' => $this->activeUsers(),
        ];
    }

    /** @return array<string, bool> column => set, for the form's "also a" boxes */
    private function currentFlags(?Party $party): array
    {
        return collect(PartyKind::cases())->mapWithKeys(fn (PartyKind $k) => [
            $k->column() => $k === $this->kind() || (bool) ($party?->{$k->column()}),
        ])->all();
    }

    /** The screen's own kind is always writable here; another kind only with its edit grant. */
    private function mayWriteKind(User $user, PartyKind $kind): bool
    {
        return $kind === $this->kind() || $user->can($kind->screen().'.edit');
    }

    /**
     * The kinds the party will have: the screen's own always; another kind as ticked when the
     * user may edit that kind's screen, otherwise as it was.
     *
     * @return array<string, bool>
     */
    private function requestedFlags(Request $request, ?Party $party): array
    {
        $asked = array_map('strval', (array) $request->input('kinds', []));

        return collect(PartyKind::cases())->mapWithKeys(fn (PartyKind $k) => [
            $k->column() => match (true) {
                $k === $this->kind() => true,
                $this->mayWriteKind($request->user(), $k) => in_array($k->value, $asked, true),
                default => (bool) ($party?->{$k->column()}),
            },
        ])->all();
    }

    /** @return array<string, mixed> the fields to write, already narrowed to what the user may write */
    private function validated(Request $request, ?Party $party): array
    {
        $user = $request->user();
        $flags = $this->requestedFlags($request, $party);
        $writes = fn (PartyKind $k) => $flags[$k->column()] && $this->mayWriteKind($user, $k);
        $lookup = fn (string $list) => Rule::exists('lookups', 'code')->where('list', $list);

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'name_local' => ['nullable', 'string', 'max:200'],
            'kinds' => ['array'],
            'kinds.*' => ['string', Rule::in(array_map(fn (PartyKind $k) => $k->value, PartyKind::cases()))],
            'country' => ['nullable', 'string', $lookup('country')],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3', $lookup('currency')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
        ];
        if ($writes(PartyKind::Client)) {
            $rules += [
                'account_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                'source' => ['nullable', 'string', $lookup('party_source')],
                'stage' => ['nullable', 'string', $lookup('client_stage')],
                'deposit_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
                'default_destination' => ['nullable', 'string', 'max:255'],
            ];
        }
        if ($writes(PartyKind::Supplier)) {
            $rules += [
                'factory_address' => ['nullable', 'string', 'max:2000'],
                'products_note' => ['nullable', 'string', 'max:2000'],
            ];
            if (Visibility::canSeeSupplierPrices($user)) {
                $rules['bank_details'] = ['nullable', 'string', 'max:2000'];
            }
        }
        if ($writes(PartyKind::Shipping)) {
            $rules['shipping_kind'] = ['nullable', 'string', $lookup('shipping_company_kind')];
            $rules['tracking_url_template'] = ['nullable', 'string', 'max:255', 'regex:#^https?://[^\s]+$#'];
        }

        $data = $request->validate($rules);
        unset($data['kinds']);
        $data['active'] = $request->boolean('active');
        // Only the kinds the user may write are sent; the others stay as they are.
        foreach (PartyKind::cases() as $k) {
            if ($this->mayWriteKind($user, $k)) {
                $data[$k->column()] = $flags[$k->column()];
            }
        }
        // A sales user who sees only their own clients manages every client they write:
        // handing one to somebody else would hide it from the person who just saved it.
        if ($writes(PartyKind::Client) && Visibility::ownClientsOnly($user)) {
            $data['account_manager_id'] = $user->id;
        }
        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validatedContact(Request $request, string $bag): array
    {
        // Spec §3.1: phones in international format. A number typed without its country code
        // is refused rather than given the default one: MASDAR's parties are in many countries
        // and the default (WHATSAPP_DEFAULT_COUNTRY_CODE) is still OPEN (Q-B3).
        $international = function (string $attribute, mixed $value, Closure $fail) {
            $raw = trim((string) $value);
            $prefixed = preg_match('/^(\+|00|[٠۰]{2})/u', $raw) === 1;
            if ($raw !== '' && (! $prefixed || PhoneNumber::normalise($raw) === null)) {
                $fail(__('Enter the number in international format: + and the country code, e.g. +86 130 8565 0160.'));
            }
        };

        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:160'],
            'role' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', $international],
            'whatsapp' => ['nullable', 'string', 'max:40', $international],
            'wechat' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['boolean'],
        ]);
        $data['is_primary'] = $request->boolean('is_primary');
        $data['phone'] = PhoneNumber::normalise($data['phone'] ?? null);
        $data['whatsapp'] = PhoneNumber::normalise($data['whatsapp'] ?? null);
        foreach (['role', 'wechat', 'email', 'notes'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? $data[$key] : null;
        }

        return $data;
    }

    /** To the party's page, or — when the save took it out of the user's sight — to the list. */
    private function redirectToParty(Request $request, Party $party, string $message): RedirectResponse
    {
        $visible = $this->scoped($request->user())->whereKey($party->id)->exists();

        return redirect()->route($this->kind()->routePrefix().($visible ? '.show' : '.index'), $visible ? $party : [])->with('success', $message);
    }
}
