<?php

namespace App\Services\SupplierOrders;

use App\Models\OrderStepDefinition;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderStepEvent;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of supplier_order_step_events (spec §5.3). A step's state is its latest
 * event: done, skipped, or reopened (= open again). Other modules complete their own steps
 * through recordAutomatic(): shipping marks "booking" and "loading", goods receipt marks
 * "receive_goods".
 */
class OrderStepService
{
    /**
     * Latest event per step definition id for $order.
     *
     * @return Collection<int, SupplierOrderStepEvent> keyed by step_definition_id
     */
    public function latestEvents(SupplierOrder $order): Collection
    {
        return SupplierOrderStepEvent::query()
            ->where('supplier_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->keyBy('step_definition_id');
    }

    public function isDone(SupplierOrder $order, OrderStepDefinition $step): bool
    {
        $last = $this->latestEvents($order)->get($step->id);

        return $last !== null && in_array($last->status, ['done', 'skipped'], true);
    }

    /**
     * Mark the step with $stepCode done on behalf of another module, unless it already is (or
     * the step was switched off on the Lists & options screen). Returns the new event, or null.
     */
    public function recordAutomatic(SupplierOrder $order, string $stepCode, User $by, string $note = '', ?int $paymentId = null): ?SupplierOrderStepEvent
    {
        $step = OrderStepDefinition::where('code', $stepCode)->where('active', true)->first();
        if (! $step || $this->isDone($order, $step)) {
            return null;
        }

        return $this->record($order, $step, 'done', $by, now()->toDateString(), $note ?: null, $paymentId);
    }

    /** Record one event; the single place a step event is written. */
    public function record(SupplierOrder $order, OrderStepDefinition $step, string $status, User $by, string $happenedOn, ?string $note = null, ?int $paymentId = null): SupplierOrderStepEvent
    {
        return DB::transaction(function () use ($order, $step, $status, $by, $happenedOn, $note, $paymentId) {
            $event = SupplierOrderStepEvent::create([
                'supplier_order_id' => $order->id,
                'step_definition_id' => $step->id,
                'status' => $status,
                'happened_on' => $happenedOn,
                'note' => $note,
                'payment_id' => $paymentId,
                'user_id' => $by->id,
            ]);
            AuditTrail::record('step_'.$status, $order, [], ['step' => $step->code, 'happened_on' => $happenedOn, 'note' => $note]);

            return $event;
        });
    }
}
