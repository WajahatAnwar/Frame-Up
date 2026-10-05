<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $merchantConfigurations = Configuration::query()->where('user_id', $request->user()->id);

        return Inertia::render('Dashboard', [
            'stats' => [
                'configurations' => (clone $merchantConfigurations)->count(),
                'active' => (clone $merchantConfigurations)->where('status', 'active')->count(),
                'draft' => (clone $merchantConfigurations)->where('status', 'draft')->count(),
                'printTypes' => DB::table('collections')->where('is_active', true)->count(),
                'surfaces' => DB::table('collection_product')->distinct('product_id')->count('product_id'),
            ],
            'recentConfigurations' => (clone $merchantConfigurations)
                ->latest()->limit(5)->get(['id', 'name', 'shopify_product_type', 'status']),
            'indexUrl' => route('configurations.index', absolute: false),
            'createUrl' => route('configurations.create', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
        ]);
    }
}
