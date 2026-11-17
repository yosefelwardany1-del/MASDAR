<?php

namespace App\Services\Shipping;

use App\Jobs\SendContainerUpdates;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\ContainerEvent;
use App\Models\ContainerLoad;
use App\Models\Lookup;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\SupplierOrders\OrderStepService;
use App\Support\Audit\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of container_events and container_loads (spec §5.5). Both are append-only
 * (I2). PostgreSQL is the gate for the two invariants — a status moves only forward unless it
 * corrects an earlier event (I8), and what is loaded never exceeds what was received (I6);
 * the checks here only turn a refusal into a message people can act on.
 */
class ContainerTrackingService
{
    public function __construct(private ShippingQueries $queries) {}

    /**
     * Records a status of $container. Going back along the path is allowed only as a
     * correction naming the event it corrects.
     *
     * @param  array{status: string, happened_at: string, place?: ?string, note?: ?string, corrects_event_id?: ?int}  $data
     */
    public function recordStatus(Container $container, array $data, User $by): ContainerEvent
    {
        $status = (string) $data['status'];
        $corrects = filled($data['corrects_event_id'] ?? null) ? (int) $data['corrects_event_id'] : null;
        $backwards = __('A container status only moves forward. To go back, record the new status as a correction of the event it corrects.');

        $newSort = Lookup::query()->where('list', 'container_status')->where('code', $status)->value('sort');
        if ($newSort === null) {
            throw ValidationException::withMessages(['status' => __('Unknown container status.')]);
        }
        if ($corrects !== null) {
            if (! ContainerEvent::query()->whereKey($corrects)->where('container_id', $container->id)->exists()) {
                throw ValidationException::withMessages(['corrects_event_id' => __('A correction must name an event of this container.')]);
            }
        } else {
            $lastSort = DB::table('container_events as e')
                ->join('lookups as l', fn ($j) => $j->on('l.code', '=', 'e.status')->where('l.list', 'container_status'))
                ->where('e.container_id', $container->id)
                ->orderByDesc('e.id')
                ->value('l.sort');
            if ($lastSort !== null && (int) $newSort < (int) $lastSort) {
                throw ValidationException::withMessages(['status' => $backwards]);
            }
        }

        try {
            return DB::transaction(function () use ($container, $data, $status, $corrects, $by) {
                $event = ContainerEvent::create([
                    'container_id' => $container->id,
                    'status' => $status,
                    'happened_at' => $data['happened_at'],
                    'place' => filled($data['place'] ?? null) ? trim($data['place']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'corrects_event_id' => $corrects,
                    'user_id' => $by->id,
                ]);
                AuditTrail::record($corrects ? 'status_corrected' : 'status_recorded', $container, [], [
                    'event_id' => $event->id, 'status' => $status, 'happened_at' => $event->happened_at?->toIso8601String(),
                    'place' => $event->place, 'note' => $event->note, 'corrects_event_id' => $corrects,
                ]);
                // Clients hear of it over WhatsApp when MASDAR switched that on — after commit.
                if (! $corrects && BusinessSetting::current()->whatsapp_container_updates) {
                    SendContainerUpdates::dispatch($event->id);
                }

                return $event;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw ValidationException::withMessages(['status' => $backwards]);
            }
            throw $e;
        }
    }

    /**
     * Loads $cartons of $line into $container. Refused (as a validation error) when the line's
     * order is not booked on the container's shipment, when the PI is unpaid and loading
     * unpaid goods is blocked (unless $by may approve payments — an audited exception), or
     * when the cartons exceed received − loaded (the I6 trigger is the gate). Allowed with a
     * warning when the container goes over its type's capacity. Marks the order's "loading"
     * step done once every carton received for it is loaded.
     *
     * @return array{load: ContainerLoad, warnings: list<string>}
     */
    public function load(Container $container, SupplierOrderLine $line, string $cartons, string $loadedOn, User $by): array
    {
        $cartons = trim($cartons);
        if (! Num::isPositive($cartons)) {
            throw ValidationException::withMessages(['cartons' => __('Enter the number of cartons to load, more than zero.')]);
        }
        $line->loadMissing('supplierOrder', 'piLine.proformaInvoice');
        $order = $line->supplierOrder;
        $shipment = $container->shipment;

        if (! $this->queries->isBooked($shipment, $order)) {
            throw ValidationException::withMessages(['supplier_order_line_id' => __('Book the order :order on this shipment before loading it.', ['order' => $order->number])]);
        }

        $exception = null;
        if (BusinessSetting::current()->block_loading_unpaid) {
            $paid = $this->queries->piPaidStatus($line->piLine);
            if (! $paid['fully_paid']) {
                if (! $by->can('payments.approve')) {
                    throw ValidationException::withMessages(['supplier_order_line_id' => __('PI :pi is not fully paid (:paid of :total :currency received). Only a manager may load it.', [
                        'pi' => $paid['pi_number'], 'paid' => Num::show($paid['paid']), 'total' => Num::show($paid['total']), 'currency' => $paid['currency'],
                    ])]);
                }
                $exception = $paid;
            }
        }

        try {
            $load = DB::transaction(function () use ($container, $line, $order, $shipment, $cartons, $loadedOn, $by, $exception) {
                $load = ContainerLoad::create([
                    'container_id' => $container->id,
                    'supplier_order_line_id' => $line->id,
                    'cartons' => $cartons,
                    'loaded_on' => $loadedOn,
                    'user_id' => $by->id,
                ]);
                AuditTrail::record('loaded', $container, [], [
                    'load_id' => $load->id, 'supplier_order_line_id' => $line->id, 'supplier_order' => $order->number,
                    'cartons' => $cartons, 'loaded_on' => $loadedOn,
                ]);
                if ($exception !== null) {
                    AuditTrail::record('loading_exception', $container, [], [
                        'load_id' => $load->id, 'supplier_order_line_id' => $line->id, 'pi_number' => $exception['pi_number'],
                        'currency' => $exception['currency'], 'pi_total' => $exception['total'], 'paid' => $exception['paid'],
                        'reason' => 'PI not fully paid; loaded by a user who may approve payments',
                    ]);
                }
                if ($this->queries->orderFullyLoaded($order)) {
                    app(OrderStepService::class)->recordAutomatic($order, 'loading', $by, 'Loaded in '.($container->container_no ?: $shipment->number));
                }

                return $load;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23514') {
                throw $e;
            }
            $figures = $this->queries->lineCartons($line->id);
            throw ValidationException::withMessages(['cartons' => __('Only :available cartons of this line are received and not yet loaded (:received received, :loaded loaded).', [
                'available' => Num::show($figures->available), 'received' => Num::show($figures->received), 'loaded' => Num::show($figures->loaded),
            ])]);
        }

        return ['load' => $load, 'warnings' => $this->capacityWarnings($container)];
    }

    /** Takes $cartons of $line out of $container: a negative row, never an edit (I2). */
    public function unload(Container $container, SupplierOrderLine $line, string $cartons, string $unloadedOn, User $by): ContainerLoad
    {
        $cartons = trim($cartons);
        if (! Num::isPositive($cartons)) {
            throw ValidationException::withMessages(['cartons' => __('Enter the number of cartons to unload, more than zero.')]);
        }

        try {
            return DB::transaction(function () use ($container, $line, $cartons, $unloadedOn, $by) {
                $load = ContainerLoad::create([
                    'container_id' => $container->id,
                    'supplier_order_line_id' => $line->id,
                    'cartons' => '-'.$cartons,
                    'loaded_on' => $unloadedOn,
                    'user_id' => $by->id,
                ]);
                AuditTrail::record('unloaded', $container, [], [
                    'load_id' => $load->id, 'supplier_order_line_id' => $line->id,
                    'supplier_order' => $line->supplierOrder?->number, 'cartons' => $cartons, 'unloaded_on' => $unloadedOn,
                ]);
                // Goods taken out again: the order is no longer fully loaded.
                $order = $line->supplierOrder;
                if ($order && ! $this->queries->orderFullyLoaded($order)) {
                    app(OrderStepService::class)->reopenAutomatic($order, 'loading', $by, 'Unloaded from '.($container->container_no ?: $container->shipment?->number));
                }

                return $load;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23514') {
                throw $e;
            }
            throw ValidationException::withMessages(['cartons' => __('This container holds only :held cartons of this line.', [
                'held' => Num::show($this->queries->cartonsInContainer($container, $line->id)),
            ])]);
        }
    }

    /** @return list<string> */
    private function capacityWarnings(Container $container): array
    {
        $row = $this->queries->boardRow($container);
        $warnings = [];
        if ($row?->over_cbm) {
            $warnings[] = __('The container is over its capacity: :loaded CBM loaded of :capacity CBM.', [
                'loaded' => Num::show($row->loaded_cbm), 'capacity' => Num::show($row->capacity_cbm),
            ]);
        }
        if ($row?->over_kg) {
            $warnings[] = __('The container is over its maximum weight: :loaded KG loaded of :capacity KG.', [
                'loaded' => Num::show($row->loaded_kg), 'capacity' => Num::show($row->capacity_kg),
            ]);
        }

        return $warnings;
    }
}
