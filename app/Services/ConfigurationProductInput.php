<?php

namespace App\Services;

use App\Models\Configuration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigurationProductInput
{
    private const MOUNT_OPTION = 'Mounts and Frames';

    public const MAX_VARIANTS = 2000;

    public function __construct(private CatalogConfigurationOptions $catalog) {}

    public function variantCount(Configuration $configuration): int
    {
        return count($this->build($configuration, enforceLimit: false)['variants']);
    }

    /** @return array<int, array{index: int, min_price: string, max_price: string, variant_count: int}> */
    public function priceSummaries(Configuration $configuration): array
    {
        return $this->build($configuration, withPricing: true)['pricingSummaries'];
    }

    /** @return array<string, mixed> */
    public function build(Configuration $configuration, bool $enforceLimit = true, bool $withPricing = false): array
    {
        $multiplier = (int) ($configuration->user?->price_multiplier ?? 2);
        if ($multiplier < 1) {
            throw new RuntimeException('The store price multiplier must be at least 1.');
        }

        $collections = collect($this->catalog->all())->keyBy('id');
        $selectedAddonIds = $configuration->printTypes->flatMap(fn ($printType) => $printType->selected_addon_ids ?? [])->unique()->values();
        $addonVariants = DB::table('product_varients')
            ->whereIn('product_id', $selectedAddonIds)
            ->where('is_active', true)
            ->get(['product_id', 'variant_type', 'width', 'height', 'price', 'min_width', 'max_width', 'min_height', 'max_height', 'price_per_sq_inch'])
            ->groupBy('product_id');
        $addonSettings = DB::table('product_settings')
            ->whereIn('product_id', $selectedAddonIds)
            ->get(['product_id', 'custom_price_type', 'is_negative'])
            ->keyBy('product_id');
        $addonSizeRanges = DB::table('addons_collections_sizes')
            ->whereIn('product_id', $selectedAddonIds)
            ->get(['product_id', 'collection_id', 'min_width', 'max_width', 'min_height', 'max_height'])
            ->groupBy('product_id');

        $variants = [];
        $summaryCents = [];
        $optionValues = ['Print Type' => [], 'Sizes' => [], self::MOUNT_OPTION => []];
        $combinations = [];

        $usedSurfaceIds = [];
        foreach ($configuration->printTypes as $printTypeIndex => $printType) {
            if (isset($usedSurfaceIds[$printType->product_id])) {
                throw new RuntimeException('Each surface can only be used once in a configuration.');
            }
            $usedSurfaceIds[$printType->product_id] = true;
            $collection = $collections->get($printType->collection_id);
            $surface = $collection ? collect($collection['surfaces'])->firstWhere('id', $printType->product_id) : null;
            if (! $surface) {
                throw new RuntimeException('A selected print type or surface is no longer available in the catalog.');
            }

            $printName = $collection['title'].' - '.$surface['title'];
            $selectedAddons = collect($surface['addons'])->whereIn('id', $printType->selected_addon_ids ?? []);
            $includedAddons = $selectedAddons->whereIn('category', ['basic', 'exclusive']);
            $mounts = $selectedAddons->where('category', 'advance')->values();

            foreach ($surface['variants'] as $size) {
                if (! in_array($size['id'], $printType->selected_variant_ids ?? [], true)) {
                    continue;
                }
                if ($size['price'] === null) {
                    throw new RuntimeException("No surface price is available for {$surface['title']} at {$this->sizeName($size)}.");
                }

                $sizeName = $this->sizeName($size);
                $includedPrice = 0;
                foreach ($includedAddons as $addon) {
                    if (! $this->matchesSizeRange($addon['id'], $printType->collection_id, $size, $addonSizeRanges)) {
                        continue;
                    }
                    $addonPrice = $this->addonPrice($addon, $size, $addonVariants, $addonSettings);
                    if ($addonPrice === null) {
                        continue;
                    }
                    $includedPrice += $addonPrice;
                }

                $availableMounts = $mounts
                    ->filter(fn ($mount) => $this->matchesSizeRange($mount['id'], $printType->collection_id, $size, $addonSizeRanges))
                    ->map(fn ($mount) => ['mount' => $mount, 'price' => $this->addonPrice($mount, $size, $addonVariants, $addonSettings)])
                    ->filter(fn ($choice) => $choice['price'] !== null);
                if ($availableMounts->isEmpty()) {
                    $availableMounts = collect([['mount' => ['id' => null, 'title' => 'None'], 'price' => 0]]);
                }

                foreach ($availableMounts as $choice) {
                    $mount = $choice['mount'];
                    $mountName = $mount['title'];
                    $key = json_encode([$printName, $sizeName, $mountName], JSON_THROW_ON_ERROR);
                    if (isset($combinations[$key])) {
                        throw new RuntimeException('Two selected print types produce the same Shopify option combination.');
                    }
                    $combinations[$key] = true;

                    $basePrice = (int) round((float) $size['price'] * 100) + $includedPrice + $choice['price'];
                    if ($basePrice < 0) {
                        throw new RuntimeException('An add-on makes a Shopify variant price negative.');
                    }
                    $price = $basePrice * $multiplier;

                    $summaryCents[$printTypeIndex] ??= ['min' => $price, 'max' => $price, 'count' => 0];
                    $summaryCents[$printTypeIndex]['min'] = min($summaryCents[$printTypeIndex]['min'], $price);
                    $summaryCents[$printTypeIndex]['max'] = max($summaryCents[$printTypeIndex]['max'], $price);
                    $summaryCents[$printTypeIndex]['count']++;

                    $values = ['Print Type' => $printName, 'Sizes' => $sizeName, self::MOUNT_OPTION => $mountName];
                    foreach ($values as $option => $value) {
                        $optionValues[$option][$value] = ['name' => $value];
                    }
                    $variants[] = [
                        'optionValues' => collect($values)->map(fn ($value, $option) => ['optionName' => $option, 'name' => $value])->values()->all(),
                        'price' => number_format($price / 100, 2, '.', ''),
                        'sku' => implode('-', ['frameup', $configuration->id, $printType->collection_id, $printType->product_id, $size['id'], $mount['id'] ?? 0]),
                        'inventoryPolicy' => 'CONTINUE',
                    ];
                }
            }
        }

        if ($variants === []) {
            throw new RuntimeException('No selected size has complete pricing for a Shopify variant.');
        }
        if ($enforceLimit && count($variants) > self::MAX_VARIANTS) {
            throw new RuntimeException('This configuration exceeds the 2,000 variant limit.');
        }

        $input = [
            'productOptions' => collect($optionValues)->map(fn ($values, $name) => ['name' => $name, 'values' => array_values($values)])->values()->all(),
            'variants' => $variants,
        ];

        if ($withPricing) {
            $input['pricingSummaries'] = collect($summaryCents)->map(fn ($summary, $index) => [
                'index' => $index,
                'min_price' => number_format($summary['min'] / 100, 2, '.', ''),
                'max_price' => number_format($summary['max'] / 100, 2, '.', ''),
                'variant_count' => $summary['count'],
            ])->values()->all();
        }

        return $input;
    }

    /** @param array<string, mixed> $size */
    private function matchesSizeRange(int $addonId, int $collectionId, array $size, $rangesByAddon): bool
    {
        $ranges = collect($rangesByAddon->get($addonId, []))->where('collection_id', $collectionId);
        if ($ranges->isEmpty()) {
            return true;
        }
        if ($size['width'] === null || $size['height'] === null) {
            return false;
        }

        $orientations = [[(float) $size['width'], (float) $size['height']], [(float) $size['height'], (float) $size['width']]];

        return $ranges->contains(function ($range) use ($orientations): bool {
            foreach ($orientations as [$width, $height]) {
                if (($range->min_width === null || $width >= (float) $range->min_width)
                    && ($range->max_width === null || $width <= (float) $range->max_width)
                    && ($range->min_height === null || $height >= (float) $range->min_height)
                    && ($range->max_height === null || $height <= (float) $range->max_height)) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @param array<string, mixed> $size */
    private function sizeName(array $size): string
    {
        if ($size['width'] === null || $size['height'] === null) {
            return (string) $size['title'];
        }

        return rtrim(rtrim(number_format((float) $size['width'], 2, '.', ''), '0'), '.').'x'
            .rtrim(rtrim(number_format((float) $size['height'], 2, '.', ''), '0'), '.');
    }

    /** @param array<string, mixed> $addon @param array<string, mixed> $size */
    private function addonPrice(array $addon, array $size, $variantsByAddon, $settingsByAddon): ?int
    {
        $width = $size['width'] === null ? null : (float) $size['width'];
        $height = $size['height'] === null ? null : (float) $size['height'];
        $prices = collect($variantsByAddon->get($addon['id'], []))->map(function ($variant) use ($width, $height, $settingsByAddon, $addon) {
            if ($variant->variant_type === 'predefined' && $variant->width === null && $variant->height === null && $variant->price !== null) {
                return ['priority' => 2, 'price' => (float) $variant->price];
            }
            if ($width === null || $height === null) {
                return null;
            }
            $orientations = [[$width, $height], [$height, $width]];
            if ($variant->variant_type === 'predefined') {
                foreach ($orientations as [$matchedWidth, $matchedHeight]) {
                    if ((float) $variant->width === $matchedWidth && (float) $variant->height === $matchedHeight && $variant->price !== null) {
                        return ['priority' => 0, 'price' => (float) $variant->price];
                    }
                }

                return null;
            }
            foreach ($orientations as [$matchedWidth, $matchedHeight]) {
                if ($variant->min_height === null || $variant->max_height === null || $matchedHeight < (float) $variant->min_height || $matchedHeight > (float) $variant->max_height) {
                    continue;
                }
                if ($variant->min_width !== null && $matchedWidth < (float) $variant->min_width) {
                    continue;
                }
                if ($variant->max_width !== null && $matchedWidth > (float) $variant->max_width) {
                    continue;
                }
                if ($variant->price_per_sq_inch === null) {
                    continue;
                }
                $rate = (float) $variant->price_per_sq_inch;
                $type = $settingsByAddon->get($addon['id'])?->custom_price_type ?? 'per_square_inch';
                $amount = match ($type) {
                    'linear_inches' => $rate * (($width + $height) * 2),
                    'per_square_inch' => $rate * $width * $height,
                    default => $rate,
                };

                return ['priority' => 1, 'price' => $amount];
            }

            return null;
        })->filter()->sort(fn ($a, $b) => [$a['priority'], $a['price']] <=> [$b['priority'], $b['price']]);

        if ($prices->isEmpty()) {
            return null;
        }

        $price = (float) $prices->first()['price'];
        if ($settingsByAddon->get($addon['id'])?->is_negative) {
            $price = -abs($price);
        }

        return (int) round($price * 100);
    }
}
