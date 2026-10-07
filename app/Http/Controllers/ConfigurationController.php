<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConfigurationRequest;
use App\Models\Configuration;
use App\Services\CatalogConfigurationOptions;
use App\Services\ShopifyConfigurationProductSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ConfigurationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,active'],
        ]);
        $collectionNames = DB::table('collections')->pluck('title', 'id');
        $configurations = Configuration::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('shopify_product_type', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with('printTypes:id,configuration_id,collection_id')
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Configuration $configuration): array => [
                'id' => $configuration->id,
                'shopify_product_type' => $configuration->shopify_product_type,
                'status' => $configuration->status,
                'print_type_names' => $configuration->printTypes
                    ->pluck('collection_id')
                    ->map(fn ($id) => $collectionNames->get($id))
                    ->filter()->unique()->values()->all(),
            ]);

        return Inertia::render('Configurations/Index', [
            'configurations' => $configurations,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'indexUrl' => route('configurations.index', absolute: false),
            'createUrl' => route('configurations.create', absolute: false),
            'dashboardUrl' => route('home', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
        ]);
    }

    public function create(Request $request, CatalogConfigurationOptions $options): Response
    {
        return Inertia::render('Configurations/Form', [
            'mode' => 'create',
            'configuration' => null,
            'catalog' => $options->all(),
            'submitUrl' => route('configurations.store', absolute: false),
            'indexUrl' => route('configurations.index', absolute: false),
            'dashboardUrl' => route('home', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
            'pricingPreviewUrl' => route('configurations.price-preview', absolute: false),
            'settingsUrl' => route('settings.edit', absolute: false),
            'priceMultiplier' => $request->user()->price_multiplier,
            'productTypesUrl' => route('shopify.product-types', absolute: false),
        ]);
    }

    public function store(SaveConfigurationRequest $request, ShopifyConfigurationProductSync $shopifySync): RedirectResponse
    {
        $imagePath = $this->storeImage($request);
        try {
            $configuration = DB::transaction(function () use ($request, $imagePath): Configuration {
                $configuration = Configuration::create([
                    ...$request->safe()->only(['shopify_product_type', 'status']),
                    'name' => $request->validated('shopify_product_type'),
                    'user_id' => $request->user()->id,
                    'image_path' => $imagePath,
                ]);
                $this->savePrintTypes($configuration, $request->validated('print_types'));

                return $configuration;
            });
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        return $this->syncAndRedirect($request, $configuration, $shopifySync, 'Configuration created successfully.');
    }

    public function show(Request $request, Configuration $configuration, CatalogConfigurationOptions $options): Response
    {
        $this->assertOwner($request, $configuration);

        return $this->formPage($request, $configuration, $options, 'show');
    }

    public function edit(Request $request, Configuration $configuration, CatalogConfigurationOptions $options): Response
    {
        $this->assertOwner($request, $configuration);

        return $this->formPage($request, $configuration, $options, 'edit');
    }

    public function update(SaveConfigurationRequest $request, Configuration $configuration, ShopifyConfigurationProductSync $shopifySync): RedirectResponse
    {
        $this->assertOwner($request, $configuration);
        $oldImagePath = $configuration->image_path;
        $imagePath = $this->storeImage($request);
        try {
            DB::transaction(function () use ($configuration, $request, $imagePath): void {
                $configuration->update([
                    ...$request->safe()->only(['shopify_product_type', 'status']),
                    'name' => $request->validated('shopify_product_type'),
                    ...($imagePath ? ['image_path' => $imagePath] : []),
                ]);
                $configuration->printTypes()->delete();
                $this->savePrintTypes($configuration, $request->validated('print_types'));
            });
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }
        if ($imagePath && $oldImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return $this->syncAndRedirect($request, $configuration, $shopifySync, 'Configuration updated successfully.');
    }

    public function destroy(Request $request, Configuration $configuration): RedirectResponse
    {
        $this->assertOwner($request, $configuration);
        $imagePath = $configuration->image_path;
        $configuration->delete();
        if ($imagePath) {
            Storage::disk('public')->delete($imagePath);
        }

        return $this->redirectTo($request, 'configurations.index');
    }

    private function formPage(Request $request, Configuration $configuration, CatalogConfigurationOptions $options, string $mode): Response
    {
        $configuration->load('printTypes');

        return Inertia::render('Configurations/Form', [
            'mode' => $mode,
            'configuration' => [
                ...$configuration->toArray(),
                'image_url' => $configuration->image_path ? Storage::disk('public')->url($configuration->image_path) : null,
            ],
            'catalog' => $options->all(),
            'submitUrl' => route('configurations.update', $configuration, false),
            'editUrl' => route('configurations.edit', $configuration, false),
            'deleteUrl' => route('configurations.destroy', $configuration, false),
            'indexUrl' => route('configurations.index', absolute: false),
            'dashboardUrl' => route('home', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
            'pricingPreviewUrl' => route('configurations.price-preview', absolute: false),
            'settingsUrl' => route('settings.edit', absolute: false),
            'priceMultiplier' => $request->user()->price_multiplier,
            'productTypesUrl' => route('shopify.product-types', absolute: false),
        ]);
    }

    /** @param array<int, array<string, mixed>> $printTypes */
    private function savePrintTypes(Configuration $configuration, array $printTypes): void
    {
        foreach ($printTypes as $position => $printType) {
            $configuration->printTypes()->create([
                'collection_id' => $printType['collection_id'] ?? null,
                'product_id' => $printType['product_id'] ?? null,
                'position' => $position,
                'selected_variant_ids' => array_values(array_unique(array_map('intval', $printType['variant_ids']))),
                'selected_addon_ids' => array_values(array_unique(array_map('intval', $printType['addon_ids']))),
            ]);
        }
    }

    private function storeImage(SaveConfigurationRequest $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $path = $request->file('image')->storePublicly('configuration-images', 'public');
        if (! $path) {
            throw new RuntimeException('The configuration image could not be stored.');
        }

        return $path;
    }

    private function assertOwner(Request $request, Configuration $configuration): void
    {
        abort_unless($configuration->user_id === $request->user()->id, 404);
    }

    private function redirectTo(Request $request, string $route, ?Configuration $configuration = null): RedirectResponse
    {
        return redirect()->route($route, array_filter([
            'configuration' => $configuration?->id,
            'shop' => $request->query('shop'),
            'host' => $request->query('host'),
        ]));
    }

    private function syncAndRedirect(Request $request, Configuration $configuration, ShopifyConfigurationProductSync $shopifySync, string $successMessage): RedirectResponse
    {
        try {
            $syncedProducts = $shopifySync->sync($configuration);
            $message = $configuration->status === 'active'
                ? ($syncedProducts > 0
                    ? $successMessage.' Applied to '.$syncedProducts.' Shopify '.($syncedProducts === 1 ? 'product.' : 'products.')
                    : $successMessage.' No Shopify products currently match this product type.')
                : $successMessage;

            return $this->redirectTo($request, 'configurations.show', $configuration)
                ->with('success', $message);
        } catch (Throwable $exception) {
            Log::error('Configuration Shopify product sync failed', [
                'configuration_id' => $configuration->id,
                'shop_id' => $configuration->user_id,
                'error' => $exception->getMessage(),
            ]);

            $reason = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Please try saving again or check the Shopify connection.';

            return $this->redirectTo($request, 'configurations.edit', $configuration)
                ->withErrors(['shopify' => 'The configuration was saved, but one or more Shopify products could not be updated: '.$reason]);
        }
    }
}
