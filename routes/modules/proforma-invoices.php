<?php

use App\Http\Controllers\ProformaInvoices\PiLineController;
use App\Http\Controllers\ProformaInvoices\ProformaInvoiceController;
use App\Http\Controllers\ProformaInvoices\ProformaInvoiceStatusController;
use Illuminate\Support\Facades\Route;

// Proforma invoices (screen `proforma_invoices`, spec §5.2). Required inside the auth group of
// routes/web.php. Reading needs view, writing edit; issuing needs approve when the business
// option pi_issue_requires_approval is on (checked by ProformaInvoiceService). PIs are never
// deleted: a draft or an issued PI is cancelled with a reason.
Route::get('proforma-invoices', [ProformaInvoiceController::class, 'index'])->middleware('can:proforma_invoices.view')->name('proforma-invoices.index');
Route::get('proforma-invoices/create', [ProformaInvoiceController::class, 'create'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.create');
Route::post('proforma-invoices', [ProformaInvoiceController::class, 'store'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.store');

Route::whereNumber(['pi', 'line'])->group(function () {
    Route::get('proforma-invoices/{pi}', [ProformaInvoiceController::class, 'show'])->middleware('can:proforma_invoices.view')->name('proforma-invoices.show');
    Route::get('proforma-invoices/{pi}/print', [ProformaInvoiceController::class, 'print'])->middleware('can:proforma_invoices.view')->name('proforma-invoices.print');
    Route::get('proforma-invoices/{pi}/edit', [ProformaInvoiceController::class, 'edit'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.edit');
    Route::put('proforma-invoices/{pi}', [ProformaInvoiceController::class, 'update'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.update');

    Route::post('proforma-invoices/{pi}/lines', [PiLineController::class, 'store'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.lines.store');
    Route::put('proforma-invoices/{pi}/lines/{line}', [PiLineController::class, 'update'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.lines.update');
    Route::delete('proforma-invoices/{pi}/lines/{line}', [PiLineController::class, 'destroy'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.lines.destroy');

    Route::post('proforma-invoices/{pi}/issue', [ProformaInvoiceStatusController::class, 'issue'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.issue');
    Route::post('proforma-invoices/{pi}/accept', [ProformaInvoiceStatusController::class, 'accept'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.accept');
    Route::post('proforma-invoices/{pi}/confirm', [ProformaInvoiceStatusController::class, 'confirm'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.confirm');
    Route::post('proforma-invoices/{pi}/cancel', [ProformaInvoiceStatusController::class, 'cancel'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.cancel');
    Route::post('proforma-invoices/{pi}/revisions', [ProformaInvoiceStatusController::class, 'revise'])->middleware('can:proforma_invoices.edit')->name('proforma-invoices.revise');
});
