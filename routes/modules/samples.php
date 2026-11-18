<?php

use App\Http\Controllers\Samples\SampleController;
use App\Http\Controllers\Samples\ServiceInvoiceController;
use Illuminate\Support\Facades\Route;

// Samples (screen `samples`) and service invoices (screen `service_invoices`), spec §5.8.
// Required inside the auth group of routes/web.php.
Route::prefix('samples')->name('samples.')->group(function () {
    Route::get('/', [SampleController::class, 'index'])->middleware('can:samples.view')->name('index');
    Route::get('create', [SampleController::class, 'create'])->middleware('can:samples.edit')->name('create');
    Route::post('/', [SampleController::class, 'store'])->middleware('can:samples.edit')->name('store');
    Route::get('{sample}', [SampleController::class, 'show'])->whereNumber('sample')->middleware('can:samples.view')->name('show');
    Route::post('{sample}/events', [SampleController::class, 'record'])->whereNumber('sample')->middleware('can:samples.edit')->name('record');
});

Route::prefix('service-invoices')->name('service-invoices.')->group(function () {
    Route::get('/', [ServiceInvoiceController::class, 'index'])->middleware('can:service_invoices.view')->name('index');
    Route::get('create', [ServiceInvoiceController::class, 'create'])->middleware('can:service_invoices.edit')->name('create');
    Route::post('/', [ServiceInvoiceController::class, 'store'])->middleware('can:service_invoices.edit')->name('store');
    Route::get('{invoice}', [ServiceInvoiceController::class, 'show'])->whereNumber('invoice')->middleware('can:service_invoices.view')->name('show');
    Route::get('{invoice}/print', [ServiceInvoiceController::class, 'print'])->whereNumber('invoice')->middleware('can:service_invoices.view')->name('print');
    Route::put('{invoice}', [ServiceInvoiceController::class, 'update'])->whereNumber('invoice')->middleware('can:service_invoices.edit')->name('update');
    Route::delete('{invoice}', [ServiceInvoiceController::class, 'destroy'])->whereNumber('invoice')->middleware('can:service_invoices.edit')->name('destroy');
    Route::post('{invoice}/lines', [ServiceInvoiceController::class, 'storeLine'])->whereNumber('invoice')->middleware('can:service_invoices.edit')->name('lines.store');
    Route::delete('{invoice}/lines/{line}', [ServiceInvoiceController::class, 'destroyLine'])->whereNumber(['invoice', 'line'])->middleware('can:service_invoices.edit')->name('lines.destroy');
    Route::post('{invoice}/issue', [ServiceInvoiceController::class, 'issue'])->whereNumber('invoice')->middleware('can:service_invoices.approve')->name('issue');
    Route::post('{invoice}/void', [ServiceInvoiceController::class, 'void'])->whereNumber('invoice')->middleware('can:service_invoices.approve')->name('void');
});
