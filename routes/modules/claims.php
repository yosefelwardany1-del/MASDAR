<?php

use App\Http\Controllers\Claims\ClaimController;
use App\Http\Controllers\Claims\TradeNoteController;
use Illuminate\Support\Facades\Route;

// Claims and the credit and debit notes that settle them (screen `claims`, spec §5.8).
// Required inside the auth group of routes/web.php. Issuing or voiding a note needs approve.
Route::prefix('claims')->name('claims.')->group(function () {
    Route::get('/', [ClaimController::class, 'index'])->middleware('can:claims.view')->name('index');
    Route::get('create', [ClaimController::class, 'create'])->middleware('can:claims.edit')->name('create');
    Route::post('/', [ClaimController::class, 'store'])->middleware('can:claims.edit')->name('store');
    Route::get('{claim}', [ClaimController::class, 'show'])->whereNumber('claim')->middleware('can:claims.view')->name('show');
    Route::get('{claim}/edit', [ClaimController::class, 'edit'])->whereNumber('claim')->middleware('can:claims.edit')->name('edit');
    Route::put('{claim}', [ClaimController::class, 'update'])->whereNumber('claim')->middleware('can:claims.edit')->name('update');
    Route::post('{claim}/status', [ClaimController::class, 'move'])->whereNumber('claim')->middleware('can:claims.edit')->name('move');
});

Route::prefix('notes')->name('trade-notes.')->group(function () {
    Route::get('/', [TradeNoteController::class, 'index'])->middleware('can:claims.view')->name('index');
    Route::get('create', [TradeNoteController::class, 'create'])->middleware('can:claims.edit')->name('create');
    Route::post('/', [TradeNoteController::class, 'store'])->middleware('can:claims.edit')->name('store');
    Route::get('{note}', [TradeNoteController::class, 'show'])->whereNumber('note')->middleware('can:claims.view')->name('show');
    Route::get('{note}/print', [TradeNoteController::class, 'print'])->whereNumber('note')->middleware('can:claims.view')->name('print');
    Route::put('{note}', [TradeNoteController::class, 'update'])->whereNumber('note')->middleware('can:claims.edit')->name('update');
    Route::delete('{note}', [TradeNoteController::class, 'destroy'])->whereNumber('note')->middleware('can:claims.edit')->name('destroy');
    Route::post('{note}/lines', [TradeNoteController::class, 'storeLine'])->whereNumber('note')->middleware('can:claims.edit')->name('lines.store');
    Route::put('{note}/lines/{line}', [TradeNoteController::class, 'updateLine'])->whereNumber(['note', 'line'])->middleware('can:claims.edit')->name('lines.update');
    Route::delete('{note}/lines/{line}', [TradeNoteController::class, 'destroyLine'])->whereNumber(['note', 'line'])->middleware('can:claims.edit')->name('lines.destroy');
    Route::post('{note}/issue', [TradeNoteController::class, 'issue'])->whereNumber('note')->middleware('can:claims.approve')->name('issue');
    Route::post('{note}/void', [TradeNoteController::class, 'void'])->whereNumber('note')->middleware('can:claims.approve')->name('void');
});
