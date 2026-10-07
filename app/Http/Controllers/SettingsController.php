<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Settings', [
            'priceMultiplier' => $request->user()->price_multiplier,
            'updateUrl' => route('settings.update', absolute: false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'price_multiplier' => ['required', 'integer', 'min:1'],
        ]);

        $merchant = $request->user();
        $merchant->price_multiplier = (int) $validated['price_multiplier'];
        $merchant->save();

        return redirect()->route('settings.edit', array_filter([
            'shop' => $request->query('shop'),
            'host' => $request->query('host'),
        ]))->with('success', 'Price multiplier saved. Save a configuration to update its Shopify prices.');
    }
}
