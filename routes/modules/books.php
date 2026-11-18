<?php

use App\Http\Controllers\Books\BankReconciliationController;
use App\Http\Controllers\Books\BooksToolsController;
use App\Http\Controllers\Books\BudgetController;
use App\Http\Controllers\Books\ExpenseClaimController;
use App\Http\Controllers\Books\FixedAssetController;
use Illuminate\Support\Facades\Route;

// Batch C (spec §5.8): bank reconciliation, expense claims, budgets, fixed assets, and on the
// Accounting screen the exchange revaluation and opening balances. Required inside the auth
// group of routes/web.php.
Route::prefix('bank-reconciliation')->name('bank-reconciliation.')->group(function () {
    Route::get('/', [BankReconciliationController::class, 'index'])->middleware('can:bank_reconciliation.view')->name('index');
    Route::post('/', [BankReconciliationController::class, 'store'])->middleware('can:bank_reconciliation.edit')->name('store');
    Route::get('{statement}', [BankReconciliationController::class, 'show'])->whereNumber('statement')->middleware('can:bank_reconciliation.view')->name('show');
    Route::delete('{statement}', [BankReconciliationController::class, 'destroy'])->whereNumber('statement')->middleware('can:bank_reconciliation.edit')->name('destroy');
    Route::post('{statement}/match-suggested', [BankReconciliationController::class, 'matchSuggested'])->whereNumber('statement')->middleware('can:bank_reconciliation.edit')->name('match-suggested');
    Route::post('{statement}/lines/{line}/match', [BankReconciliationController::class, 'match'])->whereNumber(['statement', 'line'])->middleware('can:bank_reconciliation.edit')->name('match');
    Route::post('{statement}/lines/{line}/unmatch', [BankReconciliationController::class, 'unmatch'])->whereNumber(['statement', 'line'])->middleware('can:bank_reconciliation.edit')->name('unmatch');
});

// Everyone's own claims: signed in only.
Route::prefix('my-expenses')->name('my-expenses.')->group(function () {
    Route::get('/', [ExpenseClaimController::class, 'mine'])->name('index');
    Route::post('/', [ExpenseClaimController::class, 'store'])->name('store');
    Route::get('{claim}', [ExpenseClaimController::class, 'showMine'])->whereNumber('claim')->name('show');
    Route::post('{claim}/lines', [ExpenseClaimController::class, 'storeLine'])->whereNumber('claim')->name('lines.store');
    Route::delete('{claim}/lines/{line}', [ExpenseClaimController::class, 'destroyLine'])->whereNumber(['claim', 'line'])->name('lines.destroy');
    Route::post('{claim}/submit', [ExpenseClaimController::class, 'submit'])->whereNumber('claim')->name('submit');
});
Route::prefix('expense-claims')->name('expense-claims.')->group(function () {
    Route::get('/', [ExpenseClaimController::class, 'index'])->middleware('can:expense_claims.view')->name('index');
    Route::get('{claim}', [ExpenseClaimController::class, 'show'])->whereNumber('claim')->middleware('can:expense_claims.view')->name('show');
    Route::post('{claim}/decide', [ExpenseClaimController::class, 'decide'])->whereNumber('claim')->middleware('can:expense_claims.approve')->name('decide');
});

Route::prefix('budgets')->name('budgets.')->group(function () {
    Route::get('/', [BudgetController::class, 'index'])->middleware('can:budgets.view')->name('index');
    Route::post('/', [BudgetController::class, 'store'])->middleware('can:budgets.edit')->name('store');
    Route::get('{budget}', [BudgetController::class, 'show'])->whereNumber('budget')->middleware('can:budgets.view')->name('show');
    Route::put('{budget}', [BudgetController::class, 'update'])->whereNumber('budget')->middleware('can:budgets.edit')->name('update');
});

Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
    Route::get('/', [FixedAssetController::class, 'index'])->middleware('can:fixed_assets.view')->name('index');
    Route::get('create', [FixedAssetController::class, 'create'])->middleware('can:fixed_assets.edit')->name('create');
    Route::post('/', [FixedAssetController::class, 'store'])->middleware('can:fixed_assets.edit')->name('store');
    Route::post('depreciate', [FixedAssetController::class, 'depreciate'])->middleware(['can:fixed_assets.edit', 'can:accounting.edit'])->name('depreciate');
    Route::get('{asset}', [FixedAssetController::class, 'show'])->whereNumber('asset')->middleware('can:fixed_assets.view')->name('show');
    Route::get('{asset}/edit', [FixedAssetController::class, 'edit'])->whereNumber('asset')->middleware('can:fixed_assets.edit')->name('edit');
    Route::put('{asset}', [FixedAssetController::class, 'update'])->whereNumber('asset')->middleware('can:fixed_assets.edit')->name('update');
    Route::post('{asset}/dispose', [FixedAssetController::class, 'dispose'])->whereNumber('asset')->middleware('can:fixed_assets.edit')->name('dispose');
});

Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::get('revaluation', [BooksToolsController::class, 'revaluation'])->middleware('can:accounting.view')->name('revaluation');
    Route::post('revaluation', [BooksToolsController::class, 'postRevaluation'])->middleware('can:accounting.approve')->name('revaluation.post');
    Route::get('opening-balances', [BooksToolsController::class, 'opening'])->middleware('can:accounting.edit')->name('opening');
    Route::get('opening-balances/template', [BooksToolsController::class, 'openingTemplate'])->middleware('can:accounting.edit')->name('opening.template');
    Route::post('opening-balances', [BooksToolsController::class, 'importOpening'])->middleware('can:accounting.edit')->name('opening.import');
});
