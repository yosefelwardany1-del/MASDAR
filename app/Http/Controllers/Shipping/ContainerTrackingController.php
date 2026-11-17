<?php

namespace App\Http\Controllers\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\SupplierOrderLine;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShippingQueries;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A container's status events and its loads and unloads (spec §5.5). Validates and hands
 * each write to ContainerTrackingService, which turns the database's refusals into messages.
 */
class ContainerTrackingController extends Controller
{
    public function __construct(private ContainerTrackingService $tracking, private ShippingQueries $queries) {}

    public function storeEvent(Request $request, Container $container)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_map('strval', array_keys($this->queries->statusOptions())))],
            'happened_at' => 'required|date',
            'place' => 'nullable|string|max:160',
            'note' => 'nullable|string|max:2000',
            'corrects_event_id' => ['nullable', 'integer', Rule::exists('container_events', 'id')->where('container_id', $container->id)],
        ]);
        $event = $this->tracking->recordStatus($container, $data, $request->user());

        return redirect()->route('containers.show', $container)->with('success', $event->isCorrection()
            ? __('Status corrected.')
            : __('Status recorded.'));
    }

    public function storeLoad(Request $request, Container $container)
    {
        $data = $request->validate([
            'supplier_order_line_id' => 'required|integer|exists:supplier_order_lines,id',
            'cartons' => 'required|numeric|gt:0|max:99999999',
            'loaded_on' => 'required|date',
        ]);
        $line = SupplierOrderLine::findOrFail($data['supplier_order_line_id']);
        $result = $this->tracking->load($container, $line, (string) $data['cartons'], $data['loaded_on'], $request->user());

        $redirect = redirect()->route('containers.show', $container)->with('success', __('Cartons loaded.'));

        return $result['warnings'] === [] ? $redirect : $redirect->with('warning', implode(' ', $result['warnings']));
    }

    public function storeUnload(Request $request, Container $container)
    {
        $data = $request->validate([
            'supplier_order_line_id' => 'required|integer|exists:supplier_order_lines,id',
            'cartons' => 'required|numeric|gt:0|max:99999999',
            'unloaded_on' => 'required|date',
        ]);
        $line = SupplierOrderLine::findOrFail($data['supplier_order_line_id']);
        $this->tracking->unload($container, $line, (string) $data['cartons'], $data['unloaded_on'], $request->user());

        return redirect()->route('containers.show', $container)->with('success', __('Cartons unloaded.'));
    }
}
