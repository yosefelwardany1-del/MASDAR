<?php

namespace Tests\Concerns;

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\SupplierOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** B15: from "Loaded & sealed" on a container holds goods — fixtures put some in first. */
trait LoadsContainers
{
    protected function putGoodsIn(Container $container, int $cartons = 1): void
    {
        $line = SupplierOrderLine::factory()->create(['cartons' => $cartons]);
        $user = User::factory()->create();
        GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => $cartons,
            'inspection_result' => 'pass', 'user_id' => $user->id]);
        DB::table('container_loads')->insert(['container_id' => $container->id, 'supplier_order_line_id' => $line->id,
            'cartons' => $cartons, 'loaded_on' => now()->toDateString(), 'user_id' => $user->id]);
    }
}
