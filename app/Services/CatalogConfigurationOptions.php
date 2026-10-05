<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CatalogConfigurationOptions
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $collections = DB::table('collections')
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('title')
            ->get(['id', 'title']);
        $collectionIds = $collections->pluck('id');
        $links = DB::table('collection_product')
            ->whereIn('collection_id', $collectionIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['collection_id', 'product_id']);
        $surfaceIds = $links->pluck('product_id')->unique()->values();
        $surfaces = DB::table('products')->whereIn('id', $surfaceIds)->get(['id', 'title'])->keyBy('id');
        $variants = DB::table('product_varients')
            ->whereIn('product_id', $surfaceIds)
            ->where('variant_type', 'predefined')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('sku')->orWhere('sku', '!=', 'CUSTOM-SIZE'))
            ->orderBy('width')->orderBy('height')->orderBy('id')
            ->get(['id', 'product_id', 'title', 'width', 'height', 'price'])
            ->groupBy('product_id');
        $addonLinks = DB::table('addon_product')
            ->whereIn('product_id', $surfaceIds)
            ->where('status', true)
            ->orderBy('position')->orderBy('id')
            ->get(['product_id', 'addon_id']);
        $addonIds = $addonLinks->pluck('addon_id')->unique()->values();
        $addons = DB::table('products')
            ->whereIn('id', $addonIds)
            ->where('addons_check', true)
            ->get(['id', 'title'])->keyBy('id');
        $settings = DB::table('product_settings')
            ->whereIn('product_id', $addons->keys())
            ->get(['product_id', 'addon_options', 'exclusive_option', 'exclusive_product_type'])
            ->keyBy('product_id');
        $addonsBySurface = $addonLinks->groupBy('product_id')->map(function ($rows) use ($addons, $settings) {
            return $rows->map(function ($link) use ($addons, $settings) {
                $addon = $addons->get($link->addon_id);
                $setting = $settings->get($link->addon_id);
                if (! $addon) {
                    return null;
                }

                $category = $setting?->addon_options;
                if (! in_array($category, ['basic', 'advance', 'exclusive'], true)) {
                    $category = 'other';
                }

                return [
                    'id' => $addon->id,
                    'title' => $addon->title,
                    'category' => $category,
                    'group' => $this->groupFor($category, $setting?->exclusive_option, $setting?->exclusive_product_type),
                ];
            })->filter()->values()->all();
        });
        $linksByCollection = $links->groupBy('collection_id');

        return $collections->map(function ($collection) use ($linksByCollection, $surfaces, $variants, $addonsBySurface) {
            $collectionSurfaces = ($linksByCollection->get($collection->id) ?? collect())
                ->map(function ($link) use ($surfaces, $variants, $addonsBySurface) {
                    $surface = $surfaces->get($link->product_id);
                    if (! $surface) {
                        return null;
                    }

                    return [
                        'id' => $surface->id,
                        'title' => $surface->title,
                        'variants' => ($variants->get($surface->id) ?? collect())->map(fn ($variant) => [
                            'id' => $variant->id,
                            'title' => $variant->title,
                            'width' => $variant->width,
                            'height' => $variant->height,
                            'price' => $variant->price,
                        ])->values()->all(),
                        'addons' => $addonsBySurface->get($surface->id, []),
                    ];
                })->filter()->values()->all();

            return ['id' => $collection->id, 'title' => $collection->title, 'surfaces' => $collectionSurfaces];
        })->all();
    }

    private function groupFor(string $category, ?string $option, ?string $productType): string
    {
        if ($category !== 'exclusive') {
            return $category;
        }

        if (str_contains((string) $option, 'gallery-wrap')) {
            return 'Wrap thickness';
        }

        return match ($option) {
            'exc-op-mirror-effect', 'exc-op-border-color' => 'Wrap style',
            'exc-op-wood-mount' => 'Wood mount',
            'exc-op-wood-border' => 'Wood border',
            default => str_contains((string) $option, 'matting') ? 'Matting' : match ($productType) {
                'exclusive-acrylic' => 'Paper finish',
                default => 'Exclusive options',
            },
        };
    }
}
