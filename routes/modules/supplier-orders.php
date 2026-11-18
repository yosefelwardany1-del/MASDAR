<?php

use App\Http\Controllers\SupplierOrders\GoodsReceiptController;
use App\Http\Controllers\SupplierOrders\OrderStepController;
use App\Http\Controllers\SupplierOrders\SupplierEvaluationController;
use App\Http\Controllers\SupplierOrders\SupplierOrderController;
use Illuminate\Support\Facades\Route;

// Module 3 — supplier orders and the order follow-up (spec §5.3–§5.4). Screens
// `supplier_orders` (the board, the order page, the evaluation) and `goods_receipts`.
// Required inside the auth group of routes/web.php.

Route::get('supplier-orders', [SupplierOrderController::class, 'index'])->middleware('can:supplier_orders.view')->name('supplier-orders.index');
Route::get('supplier-orders/evaluation', [SupplierEvaluationController::class, 'index'])->middleware('can:supplier_orders.view')->name('supplier-orders.evaluation');
Route::get('supplier-orders/create', [SupplierOrderController::class, 'create'])->middleware('can:supplier_orders.edit')->name('supplier-orders.create');
Route::post('supplier-orders', [SupplierOrderController::class, 'store'])->middleware('can:supplier_orders.edit')->name('supplier-orders.store');

Route::prefix('supplier-orders/{supplierOrder}')->whereNumber('supplierOrder')->name('supplier-orders.')->group(function () {
    Route::get('contract', [SupplierOrderController::class, 'contract'])->middleware('can:supplier_orders.view')->name('contract');
    Route::get('/', [SupplierOrderController::class, 'show'])->middleware('can:supplier_orders.view')->name('show');

    Route::middleware('can:supplier_orders.edit')->group(function () {
        Route::put('/', [SupplierOrderController::class, 'update'])->name('update');
        Route::post('delivery-date', [SupplierOrderController::class, 'changeDeliveryDate'])->name('delivery-date');
        Route::put('merchandiser', [SupplierOrderController::class, 'assignMerchandiser'])->name('merchandiser');
        Route::post('complete', [SupplierOrderController::class, 'complete'])->name('complete');
        Route::post('cancel', [SupplierOrderController::class, 'cancel'])->name('cancel');
        Route::post('reopen', [SupplierOrderController::class, 'reopen'])->name('reopen');
        Route::get('lines/create', [SupplierOrderController::class, 'createLines'])->name('lines.create');
        Route::post('lines', [SupplierOrderController::class, 'storeLines'])->name('lines.store');

        Route::prefix('steps/{step}')->whereNumber('step')->name('steps.')->group(function () {
            Route::post('done', [OrderStepController::class, 'done'])->name('done');
            Route::post('skip', [OrderStepController::class, 'skip'])->name('skip');
            Route::post('reopen', [OrderStepController::class, 'reopen'])->name('reopen');
        });
    });
});

Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('can:goods_receipts.view')->name('goods-receipts.index');
// Goods in the warehouse: received, not yet loaded (spec §5.4, §5.8).
Route::get('goods-receipts/in-the-warehouse', [GoodsReceiptController::class, 'stock'])->middleware('can:goods_receipts.view')->name('goods-receipts.stock');
Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('can:goods_receipts.edit')->name('goods-receipts.store');
Route::post('goods-receipts/{receipt}/correct', [GoodsReceiptController::class, 'correct'])->whereNumber('receipt')->middleware('can:goods_receipts.edit')->name('goods-receipts.correct');
