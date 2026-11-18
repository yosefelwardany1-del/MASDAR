<?php

use App\Http\Controllers\Quotations\QuotationController;
use Illuminate\Support\Facades\Route;

// Quotations (screen `quotations`, spec §5.2): price offers before the PI. Required inside the
// auth group of routes/web.php. Making a PI from one also needs proforma_invoices.edit (checked
// by the service).
Route::get('quotations', [QuotationController::class, 'index'])->middleware('can:quotations.view')->name('quotations.index');
Route::get('quotations/create', [QuotationController::class, 'create'])->middleware('can:quotations.edit')->name('quotations.create');
Route::post('quotations', [QuotationController::class, 'store'])->middleware('can:quotations.edit')->name('quotations.store');

Route::prefix('quotations/{quotation}')->whereNumber('quotation')->name('quotations.')->group(function () {
    Route::get('/', [QuotationController::class, 'show'])->middleware('can:quotations.view')->name('show');
    Route::get('print', [QuotationController::class, 'print'])->middleware('can:quotations.view')->name('print');
    Route::middleware('can:quotations.edit')->group(function () {
        Route::put('/', [QuotationController::class, 'update'])->name('update');
        Route::delete('/', [QuotationController::class, 'destroy'])->name('destroy');
        Route::post('lines', [QuotationController::class, 'storeLine'])->name('lines.store');
        Route::put('lines/{line}', [QuotationController::class, 'updateLine'])->whereNumber('line')->name('lines.update');
        Route::delete('lines/{line}', [QuotationController::class, 'destroyLine'])->whereNumber('line')->name('lines.destroy');
        Route::post('send', [QuotationController::class, 'send'])->name('send');
        Route::post('accept', [QuotationController::class, 'accept'])->name('accept');
        Route::post('decline', [QuotationController::class, 'decline'])->name('decline');
        Route::post('convert', [QuotationController::class, 'convert'])->name('convert');
        Route::post('duplicate', [QuotationController::class, 'duplicate'])->name('duplicate');
    });
});
