<?php

namespace App\Services\Shipping;

use App\Models\Container;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Numbering\NumberingService;
use App\Services\SupplierOrders\OrderStepService;
use App\Support\Audit\AuditTrail;
use App\Support\ContainerNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of shipments, containers and shipment_orders (spec §5.5). A shipment is a
 * booking with a shipping company; its containers carry the goods; booking a supplier order
 * on it marks the order's "booking" step done (step 9 of §5.3) through OrderStepService.
 */
class ShipmentService
{
    public const FIELDS = [
        'shipping_company_id', 'mode', 'booking_no', 'port_of_loading', 'port_of_discharge',
        'etd', 'eta', 'vessel', 'voyage', 'bl_number', 'incoterm', 'notes',
    ];

    public const CONTAINER_FIELDS = ['container_no', 'container_type', 'seal_no', 'notes'];

    public function __construct(private NumberingService $numbers, private ShippingQueries $queries) {}

    /** @param array<string, mixed> $data validated FIELDS */
    public function create(array $data, User $by): Shipment
    {
        return DB::transaction(function () use ($data, $by) {
            $shipment = Shipment::create($this->pick($data, self::FIELDS) + [
                'number' => $this->numbers->next('shipment'),
                'created_by' => $by->id,
            ]);
            AuditTrail::record('created', $shipment, [], ['number' => $shipment->number] + $this->snapshot($shipment, self::FIELDS));

            return $shipment;
        });
    }

    /** Changes the booking; every changed field (ETD and ETA included) is audited old → new. */
    public function update(Shipment $shipment, array $data, User $by): Shipment
    {
        return DB::transaction(function () use ($shipment, $data) {
            $old = $this->snapshot($shipment, self::FIELDS);
            $shipment->update($this->pick($data, self::FIELDS));
            [$before, $after] = $this->changes($old, $this->snapshot($shipment->refresh(), self::FIELDS));
            if ($after !== []) {
                AuditTrail::record('updated', $shipment, $before, $after);
            }

            return $shipment;
        });
    }

    public function addContainer(Shipment $shipment, array $data, User $by): Container
    {
        return DB::transaction(function () use ($shipment, $data) {
            $container = Container::create(['shipment_id' => $shipment->id] + $this->pick($data, self::CONTAINER_FIELDS));
            AuditTrail::record('created', $container, [], ['shipment' => $shipment->number] + $this->snapshot($container, self::CONTAINER_FIELDS));

            return $container;
        });
    }

    public function updateContainer(Container $container, array $data, User $by): Container
    {
        return DB::transaction(function () use ($container, $data) {
            $old = $this->snapshot($container, self::CONTAINER_FIELDS);
            $container->update($this->pick($data, self::CONTAINER_FIELDS));
            [$before, $after] = $this->changes($old, $this->snapshot($container->refresh(), self::CONTAINER_FIELDS));
            if ($after !== []) {
                AuditTrail::record('updated', $container, $before, $after);
            }

            return $container;
        });
    }

    /** Books $order on $shipment and marks its "booking" step done (once). */
    public function bookOrder(Shipment $shipment, SupplierOrder $order, User $by): ShipmentOrder
    {
        if ($order->status !== 'open') {
            throw ValidationException::withMessages(['supplier_order_id' => __('Only an open supplier order can be booked.')]);
        }
        if ($this->queries->isBooked($shipment, $order)) {
            throw ValidationException::withMessages(['supplier_order_id' => __('This order is already booked on this shipment.')]);
        }

        try {
            return DB::transaction(function () use ($shipment, $order, $by) {
                $booking = ShipmentOrder::create([
                    'shipment_id' => $shipment->id,
                    'supplier_order_id' => $order->id,
                    'user_id' => $by->id,
                ]);
                AuditTrail::record('order_booked', $shipment, [], ['supplier_order_id' => $order->id, 'supplier_order' => $order->number]);
                app(OrderStepService::class)->recordAutomatic($order, 'booking', $by, 'Booked on '.$shipment->number);

                return $booking;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['supplier_order_id' => __('This order is already booked on this shipment.')]);
            }
            throw $e;
        }
    }

    /**
     * Takes $order off $shipment — only while none of its goods are loaded on the shipment's
     * containers (the trigger is the gate). When the order is then booked on no shipment at
     * all, its booking step opens again.
     */
    public function unbookOrder(Shipment $shipment, SupplierOrder $order, User $by): void
    {
        $booking = ShipmentOrder::query()->where('shipment_id', $shipment->id)->where('supplier_order_id', $order->id)->first();
        if (! $booking) {
            throw ValidationException::withMessages(['supplier_order_id' => __('This order is not booked on this shipment.')]);
        }
        $refusal = __('Goods of :order are loaded on this shipment. Unload them before unbooking the order.', ['order' => $order->number]);
        if ($this->queries->hasGoodsOnShipment($shipment, $order)) {
            throw ValidationException::withMessages(['supplier_order_id' => $refusal]);
        }

        try {
            DB::transaction(function () use ($booking, $shipment, $order, $by) {
                $booking->delete();
                AuditTrail::record('order_unbooked', $shipment, ['supplier_order_id' => $order->id, 'supplier_order' => $order->number], []);
                if (! ShipmentOrder::query()->where('supplier_order_id', $order->id)->exists()) {
                    app(OrderStepService::class)->reopenAutomatic($order, 'booking', $by, 'Unbooked from '.$shipment->number);
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw ValidationException::withMessages(['supplier_order_id' => $refusal]);
            }
            throw $e;
        }
    }

    /** The given fields of $data, trimmed, empty strings as null, container numbers in capitals. */
    private function pick(array $data, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            if ($value !== null && $value !== '' && in_array($field, ['container_no', 'seal_no'], true)) {
                $value = strtoupper(preg_replace('/\s+/', '', $value));
            }
            $out[$field] = $value === '' ? null : $value;
        }
        // B28: a container number given is a real one (ISO 6346: 4 letters, 7 digits, the last a check digit).
        if (($out['container_no'] ?? null) !== null && ! ContainerNumber::isValid($out['container_no'])) {
            throw ValidationException::withMessages(['container_no' => __(':number is not a container number. Enter 4 letters and 7 digits as on the container, e.g. MSKU7712342.', ['number' => $out['container_no']])]);
        }

        return $out;
    }

    /** The fields as plain values for the audit trail (dates as Y-m-d). */
    private function snapshot($model, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            $out[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $out;
    }

    /** [old, new] of only the fields that changed. */
    private function changes(array $old, array $new): array
    {
        $before = $after = [];
        foreach ($new as $field => $value) {
            if ((string) ($old[$field] ?? '') !== (string) ($value ?? '')) {
                $before[$field] = $old[$field] ?? null;
                $after[$field] = $value;
            }
        }

        return [$before, $after];
    }
}
