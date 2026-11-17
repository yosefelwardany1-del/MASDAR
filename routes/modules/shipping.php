<?php

use App\Http\Controllers\Shipping\ContainerController;
use App\Http\Controllers\Shipping\ContainerTrackingController;
use App\Http\Controllers\Shipping\ShipmentController;
use Illuminate\Support\Facades\Route;

// Shipping and the container follow-up (spec §5.5), screen `containers`. Required inside the
// auth group of routes/web.php, where $pres is in scope. Reading needs containers.view,
// every write containers.edit.

// Shipments (bookings) are never deleted: containers and order steps refer to them.
$pres('shipments', ShipmentController::class, 'containers', except: ['destroy']);
Route::post('shipments/{shipment}/orders', [ShipmentController::class, 'bookOrder'])
    ->whereNumber('shipment')->middleware('can:containers.edit')->name('shipments.orders.store');
Route::delete('shipments/{shipment}/orders/{supplierOrder}', [ShipmentController::class, 'unbookOrder'])
    ->whereNumber(['shipment', 'supplierOrder'])->middleware('can:containers.edit')->name('shipments.orders.destroy');

// Containers: the board (index), a container's page, its edit form. A container is added on
// its shipment's page and never deleted.
$pres('containers', ContainerController::class, 'containers', except: ['create', 'store', 'destroy']);
Route::post('shipments/{shipment}/containers', [ContainerController::class, 'store'])
    ->whereNumber('shipment')->middleware('can:containers.edit')->name('containers.store');
Route::get('containers/{container}/packing-list', [ContainerController::class, 'packingList'])
    ->whereNumber('container')->middleware('can:containers.view')->name('containers.packing-list');

// Status events and loads: append-only rows (I2, I6, I8).
Route::post('containers/{container}/events', [ContainerTrackingController::class, 'storeEvent'])
    ->whereNumber('container')->middleware('can:containers.edit')->name('containers.events.store');
Route::post('containers/{container}/loads', [ContainerTrackingController::class, 'storeLoad'])
    ->whereNumber('container')->middleware('can:containers.edit')->name('containers.loads.store');
Route::post('containers/{container}/unloads', [ContainerTrackingController::class, 'storeUnload'])
    ->whereNumber('container')->middleware('can:containers.edit')->name('containers.unloads.store');
