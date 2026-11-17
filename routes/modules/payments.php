<?php

use App\Http\Controllers\Finance\FxRateController;
use App\Http\Controllers\Finance\MoneyAccountController;
use App\Http\Controllers\Finance\PaymentController;
use Illuminate\Support\Facades\Route;

// Money (screen `payments`, spec §3.5 and §4): payments, money accounts and FX rates. Required
// inside the auth group of routes/web.php, where $pres is in scope. Payments are append-only:
// no edit, update or destroy — a correction is a reversal, an approval is a row.

Route::get('payments/transfer', [PaymentController::class, 'createTransfer'])->middleware('can:payments.edit')->name('payments.transfer.create');
Route::post('payments/transfer', [PaymentController::class, 'storeTransfer'])->middleware('can:payments.edit')->name('payments.transfer.store');

$pres('payments', PaymentController::class, 'payments', except: ['edit', 'update', 'destroy']);

Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->whereNumber('payment')->middleware('can:payments.approve')->name('payments.approve');
Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->whereNumber('payment')->middleware('can:payments.edit')->name('payments.reverse');

Route::get('money-accounts', [MoneyAccountController::class, 'index'])->middleware('can:payments.view')->name('money-accounts.index');
Route::get('money-accounts/create', [MoneyAccountController::class, 'create'])->middleware('can:payments.edit')->name('money-accounts.create');
Route::post('money-accounts', [MoneyAccountController::class, 'store'])->middleware('can:payments.edit')->name('money-accounts.store');
Route::get('money-accounts/{account}', [MoneyAccountController::class, 'show'])->whereNumber('account')->middleware('can:payments.view')->name('money-accounts.show');
Route::get('money-accounts/{account}/edit', [MoneyAccountController::class, 'edit'])->whereNumber('account')->middleware('can:payments.edit')->name('money-accounts.edit');
Route::put('money-accounts/{account}', [MoneyAccountController::class, 'update'])->whereNumber('account')->middleware('can:payments.edit')->name('money-accounts.update');

Route::get('fx-rates', [FxRateController::class, 'index'])->middleware('can:payments.view')->name('fx-rates.index');
Route::get('fx-rates/create', [FxRateController::class, 'create'])->middleware('can:payments.edit')->name('fx-rates.create');
Route::post('fx-rates', [FxRateController::class, 'store'])->middleware('can:payments.edit')->name('fx-rates.store');
Route::get('fx-rates/{rate}/edit', [FxRateController::class, 'edit'])->whereNumber('rate')->middleware('can:payments.edit')->name('fx-rates.edit');
Route::put('fx-rates/{rate}', [FxRateController::class, 'update'])->whereNumber('rate')->middleware('can:payments.edit')->name('fx-rates.update');
