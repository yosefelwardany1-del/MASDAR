<?php

use App\Http\Controllers\Reports\AnalyticsController;
use Illuminate\Support\Facades\Route;

// Sales and purchase analysis (screen `analytics`, spec §5.8). Required inside the auth group of routes/web.php.
Route::get('analysis', [AnalyticsController::class, 'index'])->middleware('can:analytics.view')->name('analytics.index');
