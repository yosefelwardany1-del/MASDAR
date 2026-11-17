<?php

namespace App\Http\Controllers\SupplierOrders;

use App\Http\Controllers\Controller;
use App\Services\SupplierOrders\SupplierEvaluation;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;

/**
 * Supplier evaluation (spec §5.3, §7): on-time delivery against the first promised date,
 * slippage, date changes, inspection failures and sample reopenings, per supplier. Supplier
 * names follow Visibility::canSeeSupplierPrices.
 */
class SupplierEvaluationController extends Controller
{
    public function index(Request $request, SupplierEvaluation $evaluation)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return view('supplier-orders.evaluation', [
            'rows' => $evaluation->rows($data['from'] ?? null, $data['to'] ?? null),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'seeSuppliers' => Visibility::canSeeSupplierPrices($request->user()),
        ]);
    }
}
