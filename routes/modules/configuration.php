<?php

use App\Http\Controllers\Configuration\ConfigurationController;
use Illuminate\Support\Facades\Route;

// Lists & options (screen `configuration`): the business options, the editable lists and the
// order steps. Required inside the auth group of routes/web.php.
Route::get('configuration', [ConfigurationController::class, 'index'])->middleware('can:configuration.view')->name('configuration.index');
Route::put('configuration/options', [ConfigurationController::class, 'updateOptions'])->middleware('can:configuration.edit')->name('configuration.options.update');
Route::post('configuration/lookups', [ConfigurationController::class, 'storeLookup'])->middleware('can:configuration.edit')->name('configuration.lookups.store');
Route::put('configuration/lookups/{lookup}', [ConfigurationController::class, 'updateLookup'])->whereNumber('lookup')->middleware('can:configuration.edit')->name('configuration.lookups.update');
Route::post('configuration/steps', [ConfigurationController::class, 'storeStep'])->middleware('can:configuration.edit')->name('configuration.steps.store');
Route::put('configuration/steps/{step}', [ConfigurationController::class, 'updateStep'])->whereNumber('step')->middleware('can:configuration.edit')->name('configuration.steps.update');
