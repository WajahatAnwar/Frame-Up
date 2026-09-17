<?php

use App\Http\Controllers\TemporaryAuthCheckController;
use Illuminate\Support\Facades\Route;

// TEMPORARY: remove this route and TemporaryAuthCheckController after validation.
Route::get('/temporary-auth-check', TemporaryAuthCheckController::class)
    ->middleware(['temporary.auth.json', 'verify.shopify', 'throttle:temporary-auth'])
    ->name('temporary.auth.check');
