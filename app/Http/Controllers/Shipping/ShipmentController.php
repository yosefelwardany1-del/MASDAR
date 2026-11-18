<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingQueries;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shipments (bookings with a shipping company, spec §5.5): their containers and the supplier
 * orders booked on them. Validates and hands every write to ShipmentService.
 */
class ShipmentController extends Controller
{
    public function __construct(private ShipmentService $service, private ShippingQueries $queries) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $companyId = $request->integer('shipping_company_id') ?: null;
        $clientIds = $this->queries->visibleClientIds($request->user());

        $shipments = Shipment::query()
            ->with('shippingCompany')
            ->withCount(['containers', 'bookings'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'ilike', "%{$search}%")->orWhere('booking_no', 'ilike', "%{$search}%")
                ->orWhere('bl_number', 'ilike', "%{$search}%")->orWhere('vessel', 'ilike', "%{$search}%")))
            ->when($companyId, fn ($q) => $q->where('shipping_company_id', $companyId))
            ->when($clientIds !== null, fn ($q) => $this->queries->scopeShipmentsForClients($q, $clientIds))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('shipments.index', [
            'shipments' => $shipments,
            'search' => $search,
            'companyId' => $companyId,
            'companies' => Party::query()->shippingCompanies()->orderBy('name')->get(['id', 'name']),
            'modes' => $this->queries->names('shipping_mode'),
            'ports' => $this->queries->names('port'),
        ]);
    }

    public function create()
    {
        return view('shipments.create', $this->formOptions(null));
    }

    public function store(Request $request)
    {
        $shipment = $this->service->create($request->validate($this->rules(null)), $request->user());

        return redirect()->route('shipments.show', $shipment)->with('success', __('Shipment :number created.', ['number' => $shipment->number]));
    }

    public function show(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        abort_unless($this->queries->canSeeShipment($user, $shipment), 404);
        $clientIds = $this->queries->visibleClientIds($user);

        $containers = $this->queries->board($user)->where('containers.shipment_id', $shipment->id)->get();
        $bookings = $shipment->bookings()->with(['supplierOrder.supplier', 'user'])->get();
        $orderClients = $this->queries->clientsByOrder($bookings->pluck('supplier_order_id')->all());
        if ($clientIds !== null) {
            $bookings = $bookings->filter(fn ($b) => ($orderClients[$b->supplier_order_id] ?? collect())->pluck('id')->intersect($clientIds)->isNotEmpty())->values();
        }

        $bookable = collect();
        if ($user->can('containers.edit')) {
            $bookable = SupplierOrder::query()
                ->with('supplier')
                ->where('status', 'open')
                ->whereNotIn('id', $shipment->bookings()->select('supplier_order_id'))
                ->orderBy('number')
                ->get();
            $orderClients = $orderClients->union($this->queries->clientsByOrder($bookable->pluck('id')->all()));
        }

        return view('shipments.show', [
            'shipment' => $shipment->load(['shippingCompany', 'creator']),
            'containers' => $containers,
            'containerClients' => $this->queries->clientsByContainer($containers->pluck('id')->all(), $clientIds),
            'bookings' => $bookings,
            'bookable' => $bookable,
            'orderClients' => $orderClients,
            'seesSuppliers' => Visibility::canSeeSupplierPrices($user),
            'statuses' => $this->queries->names('container_status'),
            'types' => $this->queries->names('container_type'),
            'typeOptions' => Lookup::options('container_type'),
            'modes' => $this->queries->names('shipping_mode'),
            'ports' => $this->queries->names('port'),
            'arrivedSort' => $this->queries->arrivedSort(),
            // Read-only, for the status stepper: each container status's place along the path.
            'statusSorts' => Lookup::query()->where('list', 'container_status')->pluck('sort', 'code')->map(fn ($sort) => (int) $sort)->all(),
        ]);
    }

    public function edit(Shipment $shipment)
    {
        return view('shipments.edit', ['shipment' => $shipment] + $this->formOptions($shipment));
    }

    public function update(Request $request, Shipment $shipment)
    {
        $this->service->update($shipment, $request->validate($this->rules($shipment)), $request->user());

        return redirect()->route('shipments.show', $shipment)->with('success', __('Shipment updated.'));
    }

    public function bookOrder(Request $request, Shipment $shipment)
    {
        $data = $request->validate(['supplier_order_id' => 'required|integer|exists:supplier_orders,id']);
        $order = SupplierOrder::findOrFail($data['supplier_order_id']);
        $this->service->bookOrder($shipment, $order, $request->user());

        return redirect()->route('shipments.show', $shipment)->with('success', __(':order booked on :shipment.', ['order' => $order->number, 'shipment' => $shipment->number]));
    }

    public function unbookOrder(Request $request, Shipment $shipment, SupplierOrder $supplierOrder)
    {
        $this->service->unbookOrder($shipment, $supplierOrder, $request->user());

        return redirect()->route('shipments.show', $shipment)->with('success', __(':order taken off :shipment.', ['order' => $supplierOrder->number, 'shipment' => $shipment->number]));
    }

    private function formOptions(?Shipment $shipment): array
    {
        $companies = Party::query()->shippingCompanies()
            ->where(fn ($q) => $q->where('active', true)->when($shipment?->shipping_company_id, fn ($q, $id) => $q->orWhere('id', $id)))
            ->orderBy('name')->get(['id', 'name']);

        return [
            'companies' => $companies,
            'modes' => $this->withCurrent(Lookup::options('shipping_mode'), 'shipping_mode', $shipment?->mode),
            'loadingPorts' => $this->withCurrent($this->queries->portOptions('loading'), 'port', $shipment?->port_of_loading),
            'dischargePorts' => $this->withCurrent($this->queries->portOptions('discharge'), 'port', $shipment?->port_of_discharge),
        ];
    }

    /** The active options plus the record's current value, should it have been switched off since. */
    private function withCurrent(array $options, string $list, ?string $current): array
    {
        if ($current !== null && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = Lookup::label($list, $current);
        }

        return $options;
    }

    private function rules(?Shipment $shipment): array
    {
        $codes = fn (array $options) => array_map('strval', array_keys($options));
        $options = $this->formOptions($shipment);

        return [
            'shipping_company_id' => ['required', 'integer', Rule::in($options['companies']->pluck('id')->all())],
            'mode' => ['nullable', Rule::in($codes($options['modes']))],
            'booking_no' => 'nullable|string|max:80',
            'port_of_loading' => ['nullable', Rule::in($codes($options['loadingPorts']))],
            'port_of_discharge' => ['nullable', Rule::in($codes($options['dischargePorts']))],
            'etd' => 'nullable|date',
            'eta' => array_filter(['nullable', 'date', request()->filled('etd') ? 'after_or_equal:etd' : null]),
            'vessel' => 'nullable|string|max:120',
            'voyage' => 'nullable|string|max:60',
            'bl_number' => 'nullable|string|max:80',
            'incoterm' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
