<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingQueries;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The container board (spec §5.5, §7) and a container's page: its status timeline, what is
 * inside, the load form, the fill gauge and its documents; plus the printable packing list.
 * Writes go to ShipmentService (the container itself) and ContainerTrackingController.
 */
class ContainerController extends Controller
{
    public function __construct(private ShipmentService $service, private ShippingQueries $queries) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $statusNames = $this->queries->names('container_status');
        $status = $request->query('status');
        $status = ($status === '_none' || array_key_exists((string) $status, $statusNames)) ? $status : null;
        $filters = [
            'status' => $status,
            'shipping_company_id' => $request->integer('shipping_company_id') ?: null,
            'client_id' => $request->integer('client_id') ?: null,
            'soon' => $request->boolean('soon'),
            'late' => $request->boolean('late'),
        ];

        $containers = $this->queries->board($user, $filters)->paginate(25)->withQueryString();
        $clientIds = $this->queries->visibleClientIds($user);

        return view('containers.index', [
            'containers' => $containers,
            'filters' => $filters,
            'clients' => $this->queries->clientsByContainer($containers->pluck('id')->all(), $clientIds),
            'statuses' => $statusNames,
            'statusOptions' => $this->queries->statusOptions(),
            'types' => $this->queries->names('container_type'),
            'modes' => $this->queries->names('shipping_mode'),
            'ports' => $this->queries->names('port'),
            'arrivedSort' => $this->queries->arrivedSort(),
            'companies' => Party::query()->shippingCompanies()->orderBy('name')->get(['id', 'name']),
            'clientOptions' => Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'name']),
            'warningDays' => BusinessSetting::current()->eta_warning_days,
        ]);
    }

    public function show(Request $request, Container $container)
    {
        $user = $request->user();
        abort_unless($this->queries->canSeeContainer($user, $container), 404);
        $clientIds = $this->queries->visibleClientIds($user);
        $container->load(['shipment.shippingCompany', 'events.user', 'events.corrects']);
        $row = $this->queries->boardRow($container);

        return view('containers.show', [
            'container' => $container,
            'shipment' => $container->shipment,
            'row' => $row,
            'eta' => $this->queries->etaFlag($container->shipment->eta?->toDateString(), $row->status_sort),
            'contents' => $this->queries->contents([$container->id], $clientIds),
            'totals' => $this->queries->contentTotals([$container->id], $clientIds),
            'loadable' => $user->can('containers.edit') ? $this->queries->loadableLines($container->shipment, $clientIds) : collect(),
            'seesSuppliers' => Visibility::canSeeSupplierPrices($user),
            'statuses' => $this->queries->names('container_status'),
            'statusOptions' => $this->queries->statusOptions(),
            'types' => $this->queries->names('container_type'),
            'modes' => $this->queries->names('shipping_mode'),
            'ports' => $this->queries->names('port'),
        ]);
    }

    public function store(Request $request, Shipment $shipment)
    {
        $container = $this->service->addContainer($shipment, $request->validate($this->rules($shipment, null)), $request->user());

        return redirect()->route('shipments.show', $shipment)
            ->with('success', __('Container :number added.', ['number' => $container->container_no ?: '#'.$container->id]));
    }

    public function edit(Container $container)
    {
        return view('containers.edit', [
            'container' => $container->load('shipment'),
            'typeOptions' => $this->typeOptions($container),
        ]);
    }

    public function update(Request $request, Container $container)
    {
        $this->service->updateContainer($container, $request->validate($this->rules($container->shipment, $container)), $request->user());

        return redirect()->route('containers.show', $container)->with('success', __('Container updated.'));
    }

    /** The packing list, from the loads: a standalone printable page, always in English. */
    public function packingList(Request $request, Container $container)
    {
        $user = $request->user();
        abort_unless($this->queries->canSeeContainer($user, $container), 404);
        $clientIds = $this->queries->visibleClientIds($user);
        $shipment = $container->shipment;
        $english = fn (string $list, ?string $code) => $code ? (Lookup::query()->where('list', $list)->where('code', $code)->value('name') ?? $code) : '';

        return view('containers.packing-list', [
            'container' => $container,
            'shipment' => $shipment,
            'lines' => $this->queries->contents([$container->id], $clientIds),
            'totals' => $this->queries->contentTotals([$container->id], $clientIds),
            'company' => Setting::current(),
            'business' => BusinessSetting::current(),
            'type' => $english('container_type', $container->container_type),
            'pol' => $english('port', $shipment->port_of_loading),
            'pod' => $english('port', $shipment->port_of_discharge),
            'shippingCompany' => $shipment->shippingCompany?->name,
        ]);
    }

    private function typeOptions(?Container $container): array
    {
        $options = Lookup::options('container_type');
        if ($container?->container_type && ! array_key_exists($container->container_type, $options)) {
            $options[$container->container_type] = Lookup::label('container_type', $container->container_type);
        }

        return $options;
    }

    private function rules(Shipment $shipment, ?Container $container): array
    {
        // Validate what will be stored: container and seal numbers in capitals, no spaces.
        foreach (['container_no', 'seal_no'] as $field) {
            if (is_string(request()->input($field))) {
                request()->merge([$field => strtoupper(preg_replace('/\s+/', '', request()->input($field)))]);
            }
        }

        return [
            'container_no' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/',
                Rule::unique('containers', 'container_no')->where('shipment_id', $shipment->id)->ignore($container?->id)],
            'container_type' => ['nullable', Rule::in(array_map('strval', array_keys($this->typeOptions($container))))],
            'seal_no' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
