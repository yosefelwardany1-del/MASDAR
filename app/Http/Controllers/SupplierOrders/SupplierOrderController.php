<?php

namespace App\Http\Controllers\SupplierOrders;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\Setting;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\SupplierOrders\FollowUpBoard;
use App\Services\SupplierOrders\OrderStepService;
use App\Services\SupplierOrders\SupplierOrderService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The order follow-up (spec §5.3): the board that replaces MASDAR's follow-up sheet (S2), the
 * supplier order page, and making orders from a PI. Validates and calls SupplierOrderService;
 * supplier names and prices follow Visibility::canSeeSupplierPrices, and a sales user limited to
 * own clients sees only orders holding their clients' lines.
 */
class SupplierOrderController extends Controller
{
    public function __construct(private SupplierOrderService $orders, private OrderStepService $steps, private FollowUpBoard $board) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $seeSuppliers = Visibility::canSeeSupplierPrices($user);
        $filters = $request->only(['status', 'supplier', 'client', 'merchandiser', 'late', 'search']);
        $filters['status'] = in_array($filters['status'] ?? null, FollowUpBoard::STATUSES, true) ? $filters['status'] : 'open';
        if (! $seeSuppliers) {
            unset($filters['supplier']);
        }

        $clientIds = DB::table('supplier_order_lines as sol')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->select('pi.client_id');

        return view('supplier-orders.index', $this->board->page($filters, $user) + [
            // Read-only, for the page header: how many orders under these filters have a late step.
            'lateCount' => $this->board->query(['late' => 1] + $filters, $user)->count(),
            'filters' => $filters,
            'seeSuppliers' => $seeSuppliers,
            'suppliers' => $seeSuppliers
                ? Party::query()->whereIn('id', SupplierOrder::query()->select('supplier_id'))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'clients' => Visibility::scopeClients(Party::query()->whereIn('id', $clientIds), $user)->orderBy('name')->get(['id', 'name']),
            'merchandisers' => User::query()->whereIn('id', SupplierOrder::query()->whereNotNull('merchandiser_id')->select('merchandiser_id'))->orderBy('name')->get(['id', 'name']),
            // Sales orders with lines not yet on a purchase order (B01): where new orders start.
            'waiting' => $seeSuppliers && $user->can('supplier_orders.edit') ? $this->waitingPis($user)->limit(10)->get() : collect(),
            'waitingCount' => $seeSuppliers && $user->can('supplier_orders.edit') ? $this->waitingPis($user)->count() : 0,
        ]);
    }

    /** PIs the user may see that can be ordered and still have lines on no purchase order, newest first. */
    private function waitingPis(User $user)
    {
        return $this->orders->piQueryWithUnassignedLines()->with('client:id,name')
            ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
            ->orderByDesc('id');
    }

    /** Make supplier orders from a PI: its unassigned lines, grouped by planned supplier. */
    public function create(Request $request)
    {
        $this->abortUnlessSeesSuppliers($request->user());
        if (! $request->filled('pi')) {
            // "New purchase order" without a PI: choose the sales order it is made from.
            return view('supplier-orders.pick-pi', ['pis' => $this->waitingPis($request->user())->get()]);
        }
        $pi = ProformaInvoice::with('client')->findOrFail((int) $request->query('pi'));
        $this->abortUnlessClientVisible($pi, $request->user());
        if (! SupplierOrderService::isOrderable($pi)) {
            return redirect()->route('proforma-invoices.show', $pi)->with('error', SupplierOrderService::notOrderableMessage());
        }

        $lines = $this->orders->unassignedLines($pi);
        // One group per planned supplier, in PI line order; lines with no planned supplier last.
        $groups = $lines->groupBy(fn ($line) => (int) $line->supplier_id)
            ->sortBy(fn ($group, $supplierId) => $supplierId === 0 ? PHP_INT_MAX : $group->min('line_no'));
        $setting = BusinessSetting::current();

        return view('supplier-orders.create', [
            'pi' => $pi,
            'groups' => $groups,
            'suppliers' => Party::suppliers()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'deposit_percent']),
            'currencies' => Lookup::options('currency'),
            'defaultCurrency' => $setting->supplier_currency,
            'merchandisers' => $this->merchandiserOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->abortUnlessSeesSuppliers($request->user());
        $data = $request->validate([
            'pi' => ['required', 'integer', 'exists:proforma_invoices,id'],
            'groups' => ['required', 'array'],
            'groups.*' => ['array'],
            'groups.*.supplier_id' => ['nullable', 'integer'],
            ...$this->headerRules('groups.*.'),
            'groups.*.delivery_date' => ['nullable', 'date'],
            'groups.*.merchandiser_id' => ['nullable', 'integer', 'exists:users,id'],
            'groups.*.lines' => ['nullable', 'array'],
            'groups.*.lines.*.include' => ['nullable', 'boolean'],
            'groups.*.lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
        ]);
        $pi = ProformaInvoice::findOrFail($data['pi']);
        $this->abortUnlessClientVisible($pi, $request->user());

        $groups = [];
        foreach ($data['groups'] as $group) {
            $lines = [];
            foreach ($group['lines'] ?? [] as $lineId => $line) {
                if (! empty($line['include'])) {
                    $lines[(int) $lineId] = $line['unit_price'] ?? null;
                }
            }
            $groups[] = ['lines' => $lines] + collect($group)->only([
                'supplier_id', 'confirmation_date', 'delivery_date', 'currency', 'deposit_percent', 'payment_terms', 'merchandiser_id', 'notes',
            ])->all();
        }

        $created = $this->orders->createFromPi($pi, $groups, $request->user());

        if ($created->count() === 1) {
            return redirect()->route('supplier-orders.show', $created->first())->with('success', __('Supplier order :number created.', ['number' => $created->first()->number]));
        }

        return redirect()->route('supplier-orders.index')->with('success', __('Supplier orders created: :numbers', ['numbers' => $created->pluck('number')->join(', ')]));
    }

    /**
     * The purchase contract for the factory (spec §5.3, S2 step 1 "order contract confirmation &
     * stamp"): English and Chinese, MASDAR as the buyer, the factory's prices — and never the
     * client's name or price. Only for whoever sees supplier prices.
     */
    public function contract(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless(Visibility::canSeeSupplierPrices($request->user()), 403);
        $supplierOrder->load(['supplier.contacts', 'lines.piLine.product']);
        $setting = Setting::current();
        $business = BusinessSetting::current();
        $lines = $supplierOrder->lines->filter(fn (SupplierOrderLine $l) => $l->released_at === null)->values();

        return view('supplier-orders.contract', [
            'order' => $supplierOrder,
            'lines' => $lines,
            'companyName' => $setting->company_name,
            'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'address' => $business->company_address,
            'phone' => $business->company_phone,
            'terms' => $business->purchaseContractTerms(),
            'units' => Lookup::query()->where('list', 'unit')->get(['code', 'name', 'name_zh'])->keyBy('code'),
            'contact' => $supplierOrder->supplier?->primaryContact(),
        ]);
    }

    public function show(Request $request, SupplierOrder $supplierOrder)
    {
        $user = $request->user();
        abort_unless($this->board->visibleTo($supplierOrder, $user), 404);
        $order = $supplierOrder->load(['supplier', 'merchandiser', 'creator', 'dateChanges.user', 'attachments.uploader']);
        $ownOnly = Visibility::ownClientsOnly($user);

        $lines = SupplierOrderLine::query()
            ->where('supplier_order_id', $order->id)
            ->with(['piLine.product', 'piLine.proformaInvoice.client'])
            ->withSum('receipts as received_cartons', 'cartons')
            ->withSum('loads as loaded_cartons', 'cartons')
            ->when($ownOnly, fn ($q) => $q->whereHas('piLine.proformaInvoice.client', fn ($c) => $c->where('account_manager_id', $user->id)))
            ->orderBy('id')
            ->get();

        $steps = $this->steps->activeSteps();
        $canEdit = $user->can('supplier_orders.edit');

        return view('supplier-orders.show', [
            'order' => $order,
            'lines' => $lines,
            'steps' => $steps,
            'states' => $this->steps->statesFor($order, $steps),
            'events' => $order->stepEvents()->with(['step', 'user', 'payment'])->get()->reverse()->values(),
            'payments' => $steps->contains('kind', 'payment')
                ? Payment::query()->where('supplier_order_id', $order->id)->whereNull('reverses_payment_id')
                    ->whereDoesntHave('reversal')->orderBy('paid_on')->orderBy('id')->get()
                : collect(),
            'statusHistory' => AuditLog::query()->with('user')
                ->where('model_type', SupplierOrder::class)->where('model_id', $order->id)
                ->whereIn('action', ['created', 'completed', 'cancelled', 'reopened'])
                ->orderByDesc('id')->get(),
            'seeSuppliers' => Visibility::canSeeSupplierPrices($user),
            'canEdit' => $canEdit,
            'currencies' => $canEdit ? Lookup::options('currency') : [],
            'merchandisers' => $canEdit ? $this->merchandiserOptions() : collect(),
            'orderablePis' => $canEdit && $order->isOpen()
                ? $this->orders->piQueryWithUnassignedLines()->with('client:id,name')
                    ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
                    ->orderByDesc('id')->limit(200)->get(['id', 'pi_number', 'revision', 'client_id', 'status'])
                : collect(),
            'firstPromised' => $order->firstPromisedDate(),
        ]);
    }

    public function update(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate($this->headerRules(''));
        $this->orders->updateHeader($supplierOrder, $data, $request->user());

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Supplier order updated.'));
    }

    public function changeDeliveryDate(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate([
            'delivery_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->orders->changeDeliveryDate($supplierOrder, $data['delivery_date'], $data['reason'], $request->user());

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Delivery date changed.'));
    }

    public function assignMerchandiser(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate(['merchandiser_id' => ['nullable', 'integer', 'exists:users,id']]);
        $this->orders->assignMerchandiser($supplierOrder, isset($data['merchandiser_id']) ? User::find($data['merchandiser_id']) : null, $request->user());

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Merchandiser saved.'));
    }

    public function complete(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $this->orders->complete($supplierOrder, $request->user(), $request->string('reason')->toString() ?: null);

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Supplier order completed.'));
    }

    public function cancel(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->orders->cancel($supplierOrder, $request->user(), $data['reason']);

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Supplier order cancelled.'));
    }

    public function reopen(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->orders->reopen($supplierOrder, $request->user(), $data['reason'] ?? null);

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Supplier order reopened.'));
    }

    /** Pick lines of a PI to add to this open order. */
    public function createLines(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $this->abortUnlessSeesSuppliers($request->user());
        $pi = ProformaInvoice::with('client')->findOrFail((int) $request->query('pi'));
        $this->abortUnlessClientVisible($pi, $request->user());
        if (! $supplierOrder->isOpen() || ! SupplierOrderService::isOrderable($pi)) {
            return redirect()->route('supplier-orders.show', $supplierOrder)->with('error', __('Lines can be added only to an open order, from an issued, accepted or confirmed PI.'));
        }

        return view('supplier-orders.add-lines', [
            'order' => $supplierOrder->load('supplier'),
            'pi' => $pi,
            'lines' => $this->orders->unassignedLines($pi),
            'seeSuppliers' => Visibility::canSeeSupplierPrices($request->user()),
        ]);
    }

    public function storeLines(Request $request, SupplierOrder $supplierOrder)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $this->abortUnlessSeesSuppliers($request->user());
        $data = $request->validate([
            'pi' => ['required', 'integer', 'exists:proforma_invoices,id'],
            'lines' => ['required', 'array'],
            'lines.*.include' => ['nullable', 'boolean'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
        ]);
        $pi = ProformaInvoice::findOrFail($data['pi']);
        $this->abortUnlessClientVisible($pi, $request->user());

        $lines = [];
        foreach ($data['lines'] as $lineId => $line) {
            if (! empty($line['include'])) {
                $lines[(int) $lineId] = $line['unit_price'] ?? null;
            }
        }
        $this->orders->addLinesFromPi($supplierOrder, $pi, $lines, $request->user());

        return redirect()->route('supplier-orders.show', $supplierOrder)->with('success', __('Lines added.'));
    }

    /** @return array<string, mixed> */
    private function headerRules(string $prefix): array
    {
        return [
            $prefix.'confirmation_date' => ['nullable', 'date'],
            $prefix.'currency' => ['required', 'string', Rule::in(array_keys(Lookup::options('currency')))],
            $prefix.'deposit_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            $prefix.'payment_terms' => ['nullable', 'string', 'max:255'],
            $prefix.'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Active users who may follow supplier orders. */
    private function merchandiserOptions()
    {
        return User::permission('supplier_orders.edit')->where('status', 'active')->orderBy('name')->get(['users.id', 'users.name']);
    }

    /** Ordering means picking suppliers and their prices: only for those who may see them. */
    private function abortUnlessSeesSuppliers(User $user): void
    {
        abort_unless(Visibility::canSeeSupplierPrices($user), 403, __('You may not see suppliers and their prices.'));
    }

    private function abortUnlessClientVisible(ProformaInvoice $pi, User $user): void
    {
        abort_if(Visibility::ownClientsOnly($user) && (int) $pi->client?->account_manager_id !== $user->id, 404);
    }
}
