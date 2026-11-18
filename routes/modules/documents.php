<?php

use App\Http\Controllers\Documents\DocumentController;
use Illuminate\Support\Facades\Route;

// Documents as PDF and sent by email or WhatsApp (spec §5.8). Required inside the auth group of
// routes/web.php. Each document's own print page decides who may see it; sending needs the edit
// level of its screen (DocumentRegistry::maySend).
Route::prefix('documents/{type}/{id}')->whereNumber('id')->where(['type' => '[a-z-]+'])->name('documents.')->group(function () {
    Route::get('pdf', [DocumentController::class, 'pdf'])->name('pdf');
    Route::get('send', [DocumentController::class, 'create'])->name('send.create');
    Route::post('send', [DocumentController::class, 'store'])->name('send.store');
});
