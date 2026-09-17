<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\TemporaryAuthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)
    ->middleware(['verify.shopify', 'billable'])
    ->name('home');

// TEMPORARY: remove this route and controller after authentication validation.
Route::get('/temporary-auth-check', TemporaryAuthCheckController::class)
    ->middleware(['temporary.auth.json', 'verify.shopify', 'throttle:temporary-auth'])
    ->name('temporary.auth.check');

Route::get('/standalone', fn () => view('welcome'))->name('standalone');
