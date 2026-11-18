<?php

use App\Http\Controllers\Reports\ProfitController;
use Illuminate\Support\Facades\Route;

// Profit per sales order and per container (screen `profit`, spec §5.8). Required inside the
// auth group of routes/web.php; the controller also needs the user to see supplier prices.
Route::prefix('profit')->name('profit.')->middleware('can:profit.view')->group(function () {
    Route::get('/', [ProfitController::class, 'index'])->name('index');
    Route::get('containers', [ProfitController::class, 'containers'])->name('containers');
    Route::get('pi/{pi}', [ProfitController::class, 'pi'])->whereNumber('pi')->name('pi');
});
