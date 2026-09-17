<?php

use Illuminate\Support\Facades\Route;

// The Shopify package owns the authenticated home route. This fallback is only
// used when the package route is excluded or the app is opened outside Shopify.
Route::get('/standalone', fn () => view('welcome'))->name('standalone');
