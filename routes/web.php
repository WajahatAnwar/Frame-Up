<?php

use App\Http\Controllers\CatalogPageController;
use App\Http\Controllers\CatalogSyncController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\ConfigurationPricingPreviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyProductTypesController;
use App\Http\Controllers\TemporaryAuthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)
    ->middleware(['verify.shopify', 'billable'])
    ->name('home');

// TEMPORARY: remove this route and controller after authentication validation.
Route::get('/temporary-auth-check', TemporaryAuthCheckController::class)
    ->middleware(['temporary.auth.json', 'verify.shopify', 'throttle:temporary-auth'])
    ->name('temporary.auth.check');

Route::get('/standalone', fn () => view('welcome'))->name('standalone');

Route::post('/catalog/sync', CatalogSyncController::class)
    ->middleware(['verify.shopify', 'throttle:6,1'])
    ->name('catalog.sync');

Route::get('/catalog', CatalogPageController::class)
    ->middleware(['verify.shopify', 'billable'])
    ->name('catalog.page');

Route::get('/shopify/product-types', ShopifyProductTypesController::class)
    ->middleware(['verify.shopify', 'billable'])
    ->name('shopify.product-types');

Route::get('/plans', PlansController::class)
    ->middleware(['verify.shopify'])
    ->name('plans.page');

Route::post('/configurations/price-preview', ConfigurationPricingPreviewController::class)
    ->middleware(['verify.shopify', 'billable', 'throttle:60,1'])
    ->name('configurations.price-preview');

Route::resource('configurations', ConfigurationController::class)
    ->middleware(['verify.shopify', 'billable']);

Route::get('/settings', [SettingsController::class, 'edit'])
    ->middleware(['verify.shopify', 'billable'])
    ->name('settings.edit');
Route::put('/settings', [SettingsController::class, 'update'])
    ->middleware(['verify.shopify', 'billable'])
    ->name('settings.update');
