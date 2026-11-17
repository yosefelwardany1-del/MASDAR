<?php

namespace App\Http\Controllers\SupplierOrders;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Party;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\SupplierOrders\FollowUpBoard;
use App\Services\SupplierOrders\GoodsReceiptService;
use App\Services\SupplierOrders\Quantity;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Goods receipt & inspection — step 8 of S2 (spec §5.3, §5.4): the open order lines still
 * awaiting goods, each with a receive form, and the recent receipts with their corrections.
 * Recording needs goods_receipts.edit; GoodsReceiptService writes.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(private GoodsReceiptService $receipts, private FollowUpBoard $board) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $seeSuppliers = Visibility::canSeeSupplierPrices($user);
        $supplier = $seeSuppliers ? (int) $request->query('supplier') : 0;
        $orderId = (int) $request->query('order');
        $ownOnly = Visibility::ownClientsOnly($user);
        $ownLines = fn ($q) => $q->whereHas('piLine.proformaInvoice.client', fn ($c) => $c->where('account_manager_id', $user->id));

        $awaiting = SupplierOrderLine::query()
            ->select('supplier_order_lines.*')
            ->join('supplier_orders', 'supplier_orders.id', '=', 'supplier_order_lines.supplier_order_id')
            ->where('supplier_orders.status', 'open')
            ->whereRaw('supplier_order_lines.cartons > COALESCE((SELECT SUM(g.cartons) FROM goods_receipts g WHERE g.supplier_order_line_id = supplier_order_lines.id), 0)')
            ->withSum('receipts as received_cartons', 'cartons')
            ->when($supplier > 0, fn ($q) => $q->where('supplier_orders.supplier_id', $supplier))
            ->when($orderId > 0, fn ($q) => $q->where('supplier_order_lines.supplier_order_id', $orderId))
            ->when($ownOnly, $ownLines)
            ->with(['supplierOrder.supplier:id,name', 'piLine.product', 'piLine.proformaInvoice.client:id,name'])
            ->orderByRaw('supplier_orders.delivery_date ASC NULLS LAST')
            ->orderBy('supplier_orders.number')
            ->orderBy('supplier_order_lines.id')
            ->paginate(50)
            ->withQueryString();

        $recent = GoodsReceipt::query()
            ->with(['line.supplierOrder.supplier:id,name', 'line.piLine.product', 'user:id,name', 'corrects'])
            ->withSum('corrections as corrected_cartons', 'cartons')
            ->when($supplier > 0, fn ($q) => $q->whereHas('line.supplierOrder', fn ($o) => $o->where('supplier_id', $supplier)))
            ->when($orderId > 0, fn ($q) => $q->whereHas('line', fn ($l) => $l->where('supplier_order_id', $orderId)))
            ->when($ownOnly, fn ($q) => $q->whereHas('line', $ownLines))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('goods-receipts.index', [
            'awaiting' => $awaiting,
            'recent' => $recent,
            'seeSuppliers' => $seeSuppliers,
            'supplier' => $supplier,
            'orderId' => $orderId,
            'suppliers' => $seeSuppliers
                ? Party::query()->whereIn('id', SupplierOrder::query()->where('status', 'open')->select('supplier_id'))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'orders' => SupplierOrder::query()->where('status', 'open')
                ->when($supplier > 0, fn ($q) => $q->where('supplier_id', $supplier))
                ->orderBy('number')->get(['id', 'number']),
            'canSeeOrders' => $user->can('supplier_orders.view'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_order_line_id' => ['required', 'integer', 'exists:supplier_order_lines,id'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'cartons' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'inspection_result' => ['required', Rule::in(GoodsReceiptService::RESULTS)],
            'measured_cbm' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'measured_kg' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'location' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $line = SupplierOrderLine::with('supplierOrder')->findOrFail($data['supplier_order_line_id']);
        $this->abortUnlessVisible($line, $request->user());

        $this->receipts->receive($line, $data, $request->user());

        return back()->with('success', __(':cartons cartons received for :number.', [
            'cartons' => Quantity::trim($data['cartons']), 'number' => $line->supplierOrder->number,
        ]));
    }

    public function correct(Request $request, GoodsReceipt $receipt)
    {
        $this->abortUnlessVisible($receipt->line()->with('supplierOrder')->firstOrFail(), $request->user());
        $data = $request->validate([
            'cartons' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'note' => ['required', 'string', 'max:2000'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $this->receipts->correct($receipt, $data, $request->user());

        return back()->with('success', __('Receipt corrected.'));
    }

    private function abortUnlessVisible(SupplierOrderLine $line, User $user): void
    {
        abort_unless($this->board->visibleTo($line->supplierOrder, $user), 404);
    }
}
