<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The Payments screen (spec §3.5): the list with its filters and totals, the payment form (one
 * per kind), the transfer form, a payment's page with its approval and reversal. Payments are
 * never edited or deleted. What a user sees follows spec §6's two rules (PaymentQueries).
 */
class PaymentController extends Controller
{
    private const PAGE = 25;

    /** Every kind, in the order the filter lists them. */
    private const ALL_KINDS = ['client_receipt', 'supplier_payment', 'shipping_payment', 'expense', 'other_in', 'other_out', 'transfer_out', 'transfer_in'];

    public function __construct(private PaymentService $payments, private PaymentQueries $queries) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $query = $this->filtered($filters, $user);

        $totals = (clone $query)->toBase()->selectRaw(<<<'SQL'
            COALESCE(SUM(payments.signed_book_amount) FILTER (WHERE payments.direction = 'in'), 0)::numeric(18,4) AS total_in,
            (-COALESCE(SUM(payments.signed_book_amount) FILTER (WHERE payments.direction = 'out'), 0))::numeric(18,4) AS total_out,
            COALESCE(SUM(payments.signed_book_amount), 0)::numeric(18,4) AS net,
            COUNT(*) AS count
        SQL)->first();

        $payments = $query
            ->with(['moneyAccount', 'party', 'proformaInvoice', 'supplierOrder', 'container', 'approval', 'reversal', 'reverses.approval'])
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->paginate(self::PAGE)->withQueryString();

        $visibleParties = $this->queries->whereVisibleTo(Payment::query(), $user)->whereNotNull('party_id')->select('party_id');
        $approvalRequired = $this->queries->approvalRequired();

        return view('payments.index', [
            // Read only, for the page's callout and its paid-against-total bars.
            'awaitingCount' => $approvalRequired && $user->can('payments.approve')
                ? $this->queries->whereAwaitingApproval($this->queries->whereVisibleTo(Payment::query(), $user))->count()
                : 0,
            'collections' => $this->collections($user, $filters),
            'payments' => $payments,
            'filters' => $filters,
            'totals' => $totals,
            'kinds' => $this->visibleKinds($user),
            'accounts' => MoneyAccount::query()->orderBy('name')->get(),
            'parties' => Party::query()->whereIn('id', $visibleParties)->orderBy('name')->get(['id', 'name', 'code']),
            'book' => BusinessSetting::current()->book_currency,
            'approvalRequired' => $this->queries->approvalRequired(),
            'queries' => $this->queries,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $kinds = $this->queries->recordableKinds($user);
        $kind = in_array($request->query('kind'), PaymentQueries::RECORDABLE_KINDS, true) ? $request->query('kind') : $kinds[0];
        abort_unless(in_array($kind, $kinds, true), 403);

        $preset = [
            'party_id' => $request->integer('party') ?: null,
            'proforma_invoice_id' => $request->integer('pi') ?: null,
            'supplier_order_id' => $request->integer('order') ?: null,
            'container_id' => $request->integer('container') ?: null,
        ];
        if ($preset['proforma_invoice_id'] && ! $preset['party_id']) {
            $preset['party_id'] = ProformaInvoice::query()->whereKey($preset['proforma_invoice_id'])->value('client_id');
        }
        if ($preset['supplier_order_id'] && ! $preset['party_id'] && $kind === 'supplier_payment') {
            $preset['party_id'] = SupplierOrder::query()->whereKey($preset['supplier_order_id'])->value('supplier_id');
        }

        return view('payments.create', [
            'kind' => $kind,
            'kinds' => $kinds,
            'preset' => $preset,
            'accounts' => MoneyAccount::query()->where('active', true)->orderBy('name')->get(),
            'parties' => $this->partyOptions($kind, $user, $preset['party_id']),
            'pis' => $kind === 'client_receipt' ? $this->piOptions($user, $preset) : collect(),
            'orders' => in_array($kind, ['supplier_payment', 'expense'], true) ? $this->orderOptions($kind, $preset) : collect(),
            'containers' => in_array($kind, ['shipping_payment', 'expense'], true) ? $this->containerOptions($preset) : collect(),
            'counterAccounts' => in_array($kind, ['expense', 'other_in', 'other_out'], true)
                ? Account::query()->where('is_postable', true)->where('active', true)->orderBy('code')->get()
                : collect(),
            'book' => BusinessSetting::current()->book_currency,
            'approvalRequired' => $this->queries->approvalRequired(),
            'canTransfer' => $this->queries->canTransfer($user),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'kind' => ['required', Rule::in($this->queries->recordableKinds($user))],
            'paid_on' => ['required', 'date'],
            'money_account_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:30'],
            'rate' => ['nullable', 'string', 'max:30'],
            'party_id' => ['nullable', 'integer'],
            'proforma_invoice_id' => ['nullable', 'integer'],
            'supplier_order_id' => ['nullable', 'integer'],
            'container_id' => ['nullable', 'integer'],
            'counter_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ] + $this->evidenceRules());

        if (Visibility::ownClientsOnly($user) && ! empty($data['party_id'])
            && ! Visibility::scopeClients(Party::query(), $user)->whereKey($data['party_id'])->exists()) {
            abort(403, __('You record payments only for your own clients.'));
        }

        $payment = $this->payments->record($data, $user, $request->file('files', []));

        return redirect()->route('payments.show', $payment)->with('success', __('Payment :number recorded.', ['number' => $payment->number]));
    }

    public function createTransfer(Request $request)
    {
        abort_unless($this->queries->canTransfer($request->user()), 403);

        return view('payments.transfer', [
            'accounts' => MoneyAccount::query()->where('active', true)->orderBy('name')->get(),
            'book' => BusinessSetting::current()->book_currency,
            'approvalRequired' => $this->queries->approvalRequired(),
        ]);
    }

    public function storeTransfer(Request $request)
    {
        abort_unless($this->queries->canTransfer($request->user()), 403);
        $data = $request->validate([
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id'],
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'rate' => ['nullable', 'string', 'max:30'],
            'in_paid_on' => ['nullable', 'date'],
            'in_amount' => ['nullable', 'string', 'max:30'],
            'in_rate' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ] + $this->evidenceRules());

        $legs = $this->payments->transfer($data, $request->user(), $request->file('files', []));

        return redirect()->route('payments.show', $legs['out'])
            ->with('success', __('Transfer recorded: :out and :in.', ['out' => $legs['out']->number, 'in' => $legs['in']->number]));
    }

    public function show(Request $request, Payment $payment)
    {
        $user = $request->user();
        abort_unless($this->queries->canSee($payment, $user), 403);

        $payment->load(['moneyAccount.ledgerAccount', 'party', 'proformaInvoice', 'supplierOrder', 'container.shipment', 'counterAccount',
            'approval.approver', 'reversal', 'reverses.approval', 'user', 'attachments.uploader']);
        $legs = $payment->transfer_group
            ? Payment::query()->with(['moneyAccount', 'approval', 'reversal', 'reverses.approval'])
                ->where('transfer_group', $payment->transfer_group)->whereKeyNot($payment->id)->orderBy('id')->get()
            : collect();

        return view('payments.show', [
            // Read only: the current revision of the payment's PI, with its total and what has been
            // paid on its number, for the paid-against-total bar (only for whoever may see PIs).
            'piCurrent' => $payment->proformaInvoice && $user->can('proforma_invoices.view')
                ? ProformaInvoice::query()->current()->withAmounts()->where('pi_number', $payment->proformaInvoice->pi_number)->first()
                : null,
            'payment' => $payment,
            'legs' => $legs,
            'effective' => $this->queries->isEffective($payment),
            'canApprove' => $this->queries->canBeApproved($payment) && $user->can('payments.approve'),
            'approvalRequired' => $this->queries->approvalRequired(),
            'book' => BusinessSetting::current()->book_currency,
            'queries' => $this->queries,
        ]);
    }

    public function approve(Request $request, Payment $payment)
    {
        abort_unless($this->queries->canSee($payment, $request->user()), 403);
        $data = $request->validate(['approval_note' => ['nullable', 'string', 'max:1000']]);

        $this->payments->approve($payment, $request->user(), $data['approval_note'] ?? null);

        return redirect()->route('payments.show', $payment)->with('success', __('Payment :number approved.', ['number' => $payment->number]));
    }

    public function reverse(Request $request, Payment $payment)
    {
        abort_unless($this->queries->canSee($payment, $request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $reversals = $this->payments->reverse($payment, $request->user(), $data['reason']);

        return redirect()->route('payments.show', $payment)->with('success', __('Reversed by :numbers.', [
            'numbers' => collect($reversals)->pluck('number')->join(', '),
        ]));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? $request->query($key) : null;

        return [
            'kind' => in_array($request->query('kind'), self::ALL_KINDS, true) ? $request->query('kind') : null,
            'account' => $request->integer('account') ?: null,
            'party' => $request->integer('party') ?: null,
            'from' => $date('from'),
            'to' => $date('to'),
            'awaiting' => $request->boolean('awaiting'),
        ];
    }

    private function filtered(array $filters, User $user): Builder
    {
        $query = Payment::query();
        $this->queries->whereVisibleTo($query, $user);
        $query->when($filters['kind'], fn ($q, $kind) => $q->where('payments.kind', $kind))
            ->when($filters['account'], fn ($q, $id) => $q->where('payments.money_account_id', $id))
            ->when($filters['party'], fn ($q, $id) => $q->where('payments.party_id', $id))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('payments.paid_on', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('payments.paid_on', '<=', $to));
        if ($filters['awaiting']) {
            $this->queries->whereAwaitingApproval($query);
        }

        return $query;
    }

    /** @return list<string> the kinds $user can see listed */
    private function visibleKinds(User $user): array
    {
        if (Visibility::ownClientsOnly($user)) {
            return ['client_receipt'];
        }

        return array_values(array_filter(self::ALL_KINDS, fn ($kind) => $kind !== 'supplier_payment' || Visibility::canSeeSupplierPrices($user)));
    }

    /**
     * The open PIs (current revision, issued to confirmed) of the clients $user may see, with
     * their total and what has been paid on their number (ProformaInvoice::withAmounts), for
     * the paid-against-total bars on the list. Read only; nothing when PIs are not $user's.
     */
    private function collections(User $user, array $filters): Collection
    {
        if (! $user->can('proforma_invoices.view')) {
            return collect();
        }

        return ProformaInvoice::query()->current()->withAmounts()->with('client:id,name')
            ->whereIn('status', ['issued', 'accepted', 'confirmed'])
            ->whereIn('client_id', Visibility::scopeClients(Party::query()->clients(), $user)->select('id'))
            ->when($filters['party'], fn ($q, $id) => $q->where('client_id', $id))
            ->orderByDesc('id')->limit(6)->get();
    }

    private function partyOptions(string $kind, User $user, ?int $preset): Collection
    {
        $query = Party::query()->where(fn ($q) => $q->where('active', true)->when($preset, fn ($q) => $q->orWhere('id', $preset)));
        match ($kind) {
            'client_receipt' => Visibility::scopeClients($query->clients(), $user),
            'supplier_payment' => $query->suppliers(),
            'shipping_payment' => $query->shippingCompanies(),
            default => Visibility::canSeeSupplierPrices($user) ? $query : $query->where('is_supplier', false),
        };

        return $query->orderBy('name')->get(['id', 'code', 'name', 'name_local', 'is_client', 'is_supplier', 'is_shipping']);
    }

    private function piOptions(User $user, array $preset): Collection
    {
        $clients = Visibility::scopeClients(Party::query()->clients(), $user)->select('id');

        return ProformaInvoice::query()
            ->whereIn('client_id', $clients)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_current', true)->whereIn('status', ['issued', 'accepted', 'confirmed']))
                ->when($preset['proforma_invoice_id'], fn ($q, $id) => $q->orWhere('id', $id)))
            ->when($preset['party_id'], fn ($q, $id) => $q->where('client_id', $id))
            ->orderByDesc('id')->limit(500)
            ->get(['id', 'pi_number', 'revision', 'client_id', 'currency', 'status']);
    }

    private function orderOptions(string $kind, array $preset): Collection
    {
        return SupplierOrder::query()
            ->where(fn ($q) => $q->where('status', '<>', 'cancelled')->when($preset['supplier_order_id'], fn ($q, $id) => $q->orWhere('id', $id)))
            ->when($kind === 'supplier_payment' && $preset['party_id'], fn ($q) => $q->where('supplier_id', $preset['party_id']))
            ->orderByDesc('id')->limit(500)
            ->get(['id', 'number', 'supplier_id', 'currency', 'status']);
    }

    private function containerOptions(array $preset): Collection
    {
        return Container::query()->with('shipment:id,number')
            ->when($preset['container_id'], fn ($q, $id) => $q->orderByRaw('id = ? DESC', [$id]))
            ->orderByDesc('id')->limit(500)
            ->get(['id', 'container_no', 'shipment_id']);
    }

    /** @return array<string, list<string>> */
    private function evidenceRules(): array
    {
        $maxKb = (int) config('attachments.max_size_kb', 10240);
        $maxFiles = (int) config('attachments.max_files_per_request', 20);
        $mimes = implode(',', config('attachments.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png']));

        return [
            'files' => ['nullable', 'array', "max:{$maxFiles}"],
            'files.*' => ['file', "max:{$maxKb}", "mimes:{$mimes}"],
        ];
    }
}
