<?php

use App\Http\Controllers\Sourcing\SourcingController;
use Illuminate\Support\Facades\Route;

// Factory quotes (screen `sourcing`, spec §5.8). Required inside the auth group of routes/web.php;
// the controller also needs the user to see supplier prices.
Route::prefix('factory-quotes')->name('sourcing.')->group(function () {
    Route::get('/', [SourcingController::class, 'index'])->middleware('can:sourcing.view')->name('index');
    Route::get('create', [SourcingController::class, 'create'])->middleware('can:sourcing.edit')->name('create');
    Route::post('/', [SourcingController::class, 'store'])->middleware('can:sourcing.edit')->name('store');
    Route::get('{sourcing}', [SourcingController::class, 'show'])->whereNumber('sourcing')->middleware('can:sourcing.view')->name('show');
    Route::put('{sourcing}', [SourcingController::class, 'update'])->whereNumber('sourcing')->middleware('can:sourcing.edit')->name('update');
    Route::post('{sourcing}/quotes', [SourcingController::class, 'storeQuote'])->whereNumber('sourcing')->middleware('can:sourcing.edit')->name('quotes.store');
    Route::put('{sourcing}/quotes/{quote}', [SourcingController::class, 'updateQuote'])->whereNumber(['sourcing', 'quote'])->middleware('can:sourcing.edit')->name('quotes.update');
    Route::delete('{sourcing}/quotes/{quote}', [SourcingController::class, 'destroyQuote'])->whereNumber(['sourcing', 'quote'])->middleware('can:sourcing.edit')->name('quotes.destroy');
    Route::post('{sourcing}/quotes/{quote}/choose', [SourcingController::class, 'choose'])->whereNumber(['sourcing', 'quote'])->middleware('can:sourcing.edit')->name('choose');
    Route::post('{sourcing}/close', [SourcingController::class, 'close'])->whereNumber('sourcing')->middleware('can:sourcing.edit')->name('close');
    Route::post('{sourcing}/reopen', [SourcingController::class, 'reopen'])->whereNumber('sourcing')->middleware('can:sourcing.edit')->name('reopen');
    Route::post('{sourcing}/to-quotation', [SourcingController::class, 'toQuotation'])->whereNumber('sourcing')->middleware('can:sourcing.edit')->name('to-quotation');
});
