<?php

use App\Http\Controllers\Portal\PortalAccessController;
use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

// The client portal (spec §5.8): a client's own account only, while it is switched on.
// Required inside the auth group of routes/web.php; EnsureStaffUser keeps portal accounts here.
Route::prefix('portal')->name('portal.')->middleware('portal')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('orders/{pi}', [PortalController::class, 'pi'])->whereNumber('pi')->name('pi');
    Route::get('containers/{container}', [PortalController::class, 'container'])->whereNumber('container')->name('container');
    Route::get('statement', [PortalController::class, 'statement'])->name('statement');
});

// Staff side: a client's portal accounts, from the client's page.
Route::post('clients/{client}/portal', [PortalAccessController::class, 'store'])->whereNumber('client')->middleware(['can:clients.edit', 'can:users.edit'])->name('clients.portal.store');
Route::delete('clients/{client}/portal/{account}', [PortalAccessController::class, 'destroy'])->whereNumber(['client', 'account'])->middleware(['can:clients.edit', 'can:users.edit'])->name('clients.portal.destroy');
