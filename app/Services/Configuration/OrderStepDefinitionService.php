<?php

namespace App\Services\Configuration;

use App\Models\OrderStepDefinition;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of order_step_definitions: the columns of the follow-up board. A step is
 * renamed, reordered or switched off — never deleted, since past orders' events refer to it.
 */
class OrderStepDefinitionService
{
    /** @param array<string, mixed> $data validated */
    public function create(array $data): OrderStepDefinition
    {
        return DB::transaction(function () use ($data) {
            $step = OrderStepDefinition::create($data + ['active' => true]);
            AuditTrail::record('created', $step, [], $step->only(array_keys($data)));

            return $step;
        });
    }

    /** @param array<string, mixed> $data validated */
    public function update(OrderStepDefinition $step, array $data): OrderStepDefinition
    {
        return DB::transaction(function () use ($step, $data) {
            $old = $step->only(array_keys($data));
            $step->update($data);
            AuditTrail::record('updated', $step, $old, $step->only(array_keys($data)));

            return $step;
        });
    }
}
