<?php

use App\Http\Controllers\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Accounting\FinanceReportController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\PeriodController;
use App\Http\Controllers\Accounting\PostingQueueController;
use App\Http\Controllers\Accounting\PostingRuleController;
use Illuminate\Support\Facades\Route;

// Accounting (screen `accounting`, spec §3.6, §5.6) and the financial reports (screen
// `finance_reports`, spec §7). Required inside the auth group of routes/web.php. Reading needs
// view; the chart, rules, drafts and the posting queue need edit; posting or reversing a
// manual entry and closing or reopening a period need approve.
Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::get('/', [ChartOfAccountsController::class, 'index'])->middleware('can:accounting.view')->name('index');
    Route::get('accounts/create', [ChartOfAccountsController::class, 'create'])->middleware('can:accounting.edit')->name('accounts.create');
    Route::post('accounts', [ChartOfAccountsController::class, 'store'])->middleware('can:accounting.edit')->name('accounts.store');
    Route::get('accounts/{account}/edit', [ChartOfAccountsController::class, 'edit'])->whereNumber('account')->middleware('can:accounting.edit')->name('accounts.edit');
    Route::put('accounts/{account}', [ChartOfAccountsController::class, 'update'])->whereNumber('account')->middleware('can:accounting.edit')->name('accounts.update');

    Route::get('posting-rules', [PostingRuleController::class, 'index'])->middleware('can:accounting.view')->name('posting-rules.index');
    Route::put('posting-rules/{rule}', [PostingRuleController::class, 'update'])->whereNumber('rule')->middleware('can:accounting.edit')->name('posting-rules.update');

    Route::get('periods', [PeriodController::class, 'index'])->middleware('can:accounting.view')->name('periods.index');
    Route::post('periods', [PeriodController::class, 'store'])->middleware('can:accounting.edit')->name('periods.store');
    Route::post('periods/{period}/close', [PeriodController::class, 'close'])->whereNumber('period')->middleware('can:accounting.approve')->name('periods.close');
    Route::post('periods/{period}/reopen', [PeriodController::class, 'reopen'])->whereNumber('period')->middleware('can:accounting.approve')->name('periods.reopen');

    Route::get('journal', [JournalController::class, 'index'])->middleware('can:accounting.view')->name('journal.index');
    Route::get('journal/create', [JournalController::class, 'create'])->middleware('can:accounting.edit')->name('journal.create');
    Route::post('journal', [JournalController::class, 'store'])->middleware('can:accounting.edit')->name('journal.store');
    Route::get('journal/{entry}', [JournalController::class, 'show'])->whereNumber('entry')->middleware('can:accounting.view')->name('journal.show');
    Route::get('journal/{entry}/edit', [JournalController::class, 'edit'])->whereNumber('entry')->middleware('can:accounting.edit')->name('journal.edit');
    Route::put('journal/{entry}', [JournalController::class, 'update'])->whereNumber('entry')->middleware('can:accounting.edit')->name('journal.update');
    Route::delete('journal/{entry}', [JournalController::class, 'destroy'])->whereNumber('entry')->middleware('can:accounting.edit')->name('journal.destroy');
    Route::post('journal/{entry}/post', [JournalController::class, 'post'])->whereNumber('entry')->middleware('can:accounting.approve')->name('journal.post');
    Route::post('journal/{entry}/reverse', [JournalController::class, 'reverse'])->whereNumber('entry')->middleware('can:accounting.approve')->name('journal.reverse');

    Route::middleware('can:accounting.edit')->prefix('to-post')->name('to-post.')->group(function () {
        Route::get('/', [PostingQueueController::class, 'index'])->name('index');
        Route::post('payments', [PostingQueueController::class, 'postAllPayments'])->name('payments');
        Route::post('payments/{payment}', [PostingQueueController::class, 'postPayment'])->whereNumber('payment')->name('payment');
        Route::post('invoices', [PostingQueueController::class, 'postAllInvoices'])->name('invoices');
        Route::post('invoices/{pi}', [PostingQueueController::class, 'postInvoice'])->whereNumber('pi')->name('invoice');
        Route::post('bills', [PostingQueueController::class, 'postAllBills'])->name('bills');
        Route::post('bills/{order}', [PostingQueueController::class, 'postBill'])->whereNumber('order')->name('bill');
        Route::post('payrolls', [PostingQueueController::class, 'postAllPayrolls'])->name('payrolls');
        Route::post('payrolls/{run}', [PostingQueueController::class, 'postPayroll'])->whereNumber('run')->name('payroll');
        Route::post('shipping-bill', [PostingQueueController::class, 'postShippingBill'])->name('shipping-bill');
    });
});

Route::prefix('finance-reports')->name('finance-reports.')->middleware('can:finance_reports.view')->group(function () {
    Route::get('/', [FinanceReportController::class, 'index'])->name('index');
    Route::get('trial-balance', [FinanceReportController::class, 'trialBalance'])->name('trial-balance');
    Route::get('profit-and-loss', [FinanceReportController::class, 'profitAndLoss'])->name('profit-and-loss');
    Route::get('balance-sheet', [FinanceReportController::class, 'balanceSheet'])->name('balance-sheet');
    Route::get('account-ledger', [FinanceReportController::class, 'accountLedger'])->name('account-ledger');
    Route::get('party-statement', [FinanceReportController::class, 'partyStatement'])->name('party-statement');
    Route::get('cash-position', [FinanceReportController::class, 'cashPosition'])->name('cash-position');
});
