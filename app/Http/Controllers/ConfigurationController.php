<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConfigurationRequest;
use App\Models\Configuration;
use App\Services\CatalogConfigurationOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('shopify_product_type', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with('printTypes:id,configuration_id,collection_id')
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Configuration $configuration): array => [
                'id' => $configuration->id,
                'name' => $configuration->name,
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

    public function create(CatalogConfigurationOptions $options): Response
    {
        return Inertia::render('Configurations/Form', [
            'mode' => 'create',
            'configuration' => null,
            'catalog' => $options->all(),
            'submitUrl' => route('configurations.store', absolute: false),
            'indexUrl' => route('configurations.index', absolute: false),
            'dashboardUrl' => route('home', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
        ]);
    }

    public function store(SaveConfigurationRequest $request): RedirectResponse
    {
        $configuration = DB::transaction(function () use ($request): Configuration {
            $configuration = Configuration::create([
                ...$request->safe()->only(['name', 'shopify_product_type', 'status']),
                'user_id' => $request->user()->id,
            ]);
            $this->savePrintTypes($configuration, $request->validated('print_types'));

            return $configuration;
        });

        return $this->redirectTo($request, 'configurations.show', $configuration);
    }

    public function show(Request $request, Configuration $configuration, CatalogConfigurationOptions $options): Response
    {
        $this->assertOwner($request, $configuration);

        return $this->formPage($configuration, $options, 'show');
    }

    public function edit(Request $request, Configuration $configuration, CatalogConfigurationOptions $options): Response
    {
        $this->assertOwner($request, $configuration);

        return $this->formPage($configuration, $options, 'edit');
    }

    public function update(SaveConfigurationRequest $request, Configuration $configuration): RedirectResponse
    {
        $this->assertOwner($request, $configuration);
        DB::transaction(function () use ($configuration, $request): void {
            $configuration->update($request->safe()->only(['name', 'shopify_product_type', 'status']));
            $configuration->printTypes()->delete();
            $this->savePrintTypes($configuration, $request->validated('print_types'));
        });

        return $this->redirectTo($request, 'configurations.show', $configuration);
    }

    public function destroy(Request $request, Configuration $configuration): RedirectResponse
    {
        $this->assertOwner($request, $configuration);
        $configuration->delete();

        return $this->redirectTo($request, 'configurations.index');
    }

    private function formPage(Configuration $configuration, CatalogConfigurationOptions $options, string $mode): Response
    {
        $configuration->load('printTypes');

        return Inertia::render('Configurations/Form', [
            'mode' => $mode,
            'configuration' => $configuration,
            'catalog' => $options->all(),
            'submitUrl' => route('configurations.update', $configuration, false),
            'editUrl' => route('configurations.edit', $configuration, false),
            'deleteUrl' => route('configurations.destroy', $configuration, false),
            'indexUrl' => route('configurations.index', absolute: false),
            'dashboardUrl' => route('home', absolute: false),
            'catalogUrl' => route('catalog.page', absolute: false),
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
                'selected_variant_ids' => $printType['variant_ids'],
                'selected_addon_ids' => $printType['addon_ids'],
            ]);
        }
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
}
