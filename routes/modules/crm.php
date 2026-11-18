<?php

use App\Http\Controllers\Crm\CrmController;
use App\Http\Controllers\Crm\CrmInteractionController;
use App\Http\Controllers\Crm\CrmTaskController;
use Illuminate\Support\Facades\Route;

// The CRM log (screen `crm`, spec §3.7): follow-up tasks, and interactions recorded on a
// party's page. Reading the task list needs crm.view; every write needs crm.edit. Required
// inside the auth group of routes/web.php.
Route::get('crm', [CrmController::class, 'index'])->middleware('can:crm.view')->name('crm.index');
Route::get('crm/pipeline', [CrmController::class, 'pipeline'])->middleware(['can:crm.view', 'can:clients.view'])->name('crm.pipeline');
Route::post('crm/pipeline/move', [CrmController::class, 'move'])->middleware(['can:crm.edit', 'can:clients.edit'])->name('crm.pipeline.move');
Route::get('crm/activity', [CrmController::class, 'activity'])->middleware('can:crm.view')->name('crm.activity');
Route::post('crm/activity', [CrmController::class, 'storeActivity'])->middleware('can:crm.edit')->name('crm.activity.store');
Route::get('crm/tasks', [CrmTaskController::class, 'index'])->middleware('can:crm.view')->name('crm.tasks.index');
Route::post('crm/tasks', [CrmTaskController::class, 'store'])->middleware('can:crm.edit')->name('crm.tasks.store');
Route::post('crm/tasks/{task}/complete', [CrmTaskController::class, 'complete'])->whereNumber('task')->middleware('can:crm.edit')->name('crm.tasks.complete');
Route::post('crm/tasks/{task}/reopen', [CrmTaskController::class, 'reopen'])->whereNumber('task')->middleware('can:crm.edit')->name('crm.tasks.reopen');
Route::post('crm/parties/{party}/interactions', [CrmInteractionController::class, 'store'])->whereNumber('party')->middleware('can:crm.edit')->name('crm.interactions.store');
