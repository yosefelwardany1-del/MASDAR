<?php

use App\Http\Controllers\Products\ProductController;
use Illuminate\Support\Facades\Route;

// The product catalog (screen `products`, spec §3.2). Products are deactivated, never deleted.
// Required inside the auth group of routes/web.php, where $pres is in scope.

// The photo is served to any signed-in user (the PI print and the follow-up board show it).
// Registered before the resource so products/{product} never captures it.
Route::get('products/{product}/photo', [ProductController::class, 'photo'])->whereNumber('product')->name('products.photo');
Route::delete('products/{product}/photo', [ProductController::class, 'destroyPhoto'])->whereNumber('product')->middleware('can:products.edit')->name('products.photo.destroy');
Route::post('products/{product}/deactivate', [ProductController::class, 'deactivate'])->whereNumber('product')->middleware('can:products.edit')->name('products.deactivate');
Route::post('products/{product}/reactivate', [ProductController::class, 'reactivate'])->whereNumber('product')->middleware('can:products.edit')->name('products.reactivate');

$pres('products', ProductController::class, 'products', except: ['destroy']);
