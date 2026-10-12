<?php

namespace App\Services;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StorefrontProductPreview
{
    private const SETTINGS = [
        'product_id', 'material_type', 'wood_finish', 'frame_type', 'frame_color', 'frame_texture',
        'border_size', 'border_color', 'inner_inset', 'depth', 'glass_opacity', 'glass_depth',
        'canvas_type', 'thickness', 'depth_side_color', 'ex_op_canvas_frame_type',
        'metal_color', 'metal_thickness', 'surface_roughness', 'reflection_intensity',
        'exclusive_option', 'exclusive_product_type', 'selectMount', 'FrameColor',
        'selectHangerType', 'FrameBorderWidth', 'FrameDepth', 'SidePadding', 'FloatDepth',
        'FloatWidth', 'FloatHeight', 'PostDepth', 'PostDistance', 'hangerHeight', 'Scale',
        'X_Position', 'Y_Position', 'Z_Position', 'image_effect', 'front_no_color',
        'metalness', 'metal_roughness', 'metal_bump_strength', 'metal_env_map_intensity',
        'back_metal_color', 'back_metalness', 'back_metal_roughness', 'back_plain_color',
        'surface_texture', 'texture_strength', 'substrate_color', 'back_color',
        'basic_option_name', 'wood_mount_type', 'size_dependency_width', 'size_dependency_height',
    ];

    public function __construct(private CatalogConfigurationOptions $catalog) {}

    /** @return array<string, mixed>|null */
    public function forProduct(User $shop, string $productId, string $productType): ?array
    {
        $gid = str_starts_with($productId, 'gid://') ? $productId : 'gid://shopify/Product/'.$productId;
        $configuration = Configuration::query()
            ->where('user_id', $shop->id)
            ->where('status', 'active')
            ->where('shopify_product_type', $productType)
            ->where(function ($query) use ($gid): void {
                $query->whereHas('shopifyProducts', fn ($products) => $products->where('shopify_product_id', $gid))
                    ->orWhere('shopify_product_id', $gid);
            })
            ->with('printTypes')
            ->first();

        if (! $configuration) {
            return null;
        }

        $collections = collect($this->catalog->all())->keyBy('id');
        $ids = $configuration->printTypes->flatMap(fn ($row) => [$row->product_id, ...$row->selected_addon_ids])->unique();
        $settings = DB::table('product_settings')->whereIn('product_id', $ids)->get(self::SETTINGS)->keyBy('product_id');
        $printTypes = [];

        foreach ($configuration->printTypes as $row) {
            $collection = $collections->get($row->collection_id);
            $surface = collect($collection['surfaces'] ?? [])->firstWhere('id', $row->product_id);
            if (! $surface || ! $settings->has($row->product_id)) {
                continue;
            }
            $addons = collect($surface['addons'])->whereIn('id', $row->selected_addon_ids)->map(fn ($addon) => [
                'id' => $addon['id'], 'label' => $addon['title'], 'category' => $addon['category'], 'group' => $addon['group'],
                'settings' => (array) $settings->get($addon['id'], (object) []),
            ]);
            $sizes = collect($surface['variants'])->whereIn('id', $row->selected_variant_ids)
                ->filter(fn ($size) => (float) $size['width'] > 0 && (float) $size['height'] > 0)
                ->map(function ($size): array {
                    $format = fn ($number) => rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');

                    return [
                        'option' => $size['width'] !== null && $size['height'] !== null
                            ? $format($size['width']).'x'.$format($size['height']) : (string) $size['title'],
                        'width' => $size['width'], 'height' => $size['height'],
                    ];
                })->values()->all();
            $mounts = $addons->where('category', 'advance')->values()->all();
            if ($sizes === [] || $mounts === []) {
                continue;
            }
            $printTypes[] = [
                'option' => $collection['title'].' - '.$surface['title'],
                'surface' => ['id' => $surface['id'], 'label' => $surface['title'], 'settings' => (array) $settings->get($surface['id'])],
                'sizes' => $sizes,
                'mounts' => $mounts,
                'included_addons' => $addons->whereIn('category', ['basic', 'exclusive'])->values()->all(),
            ];
        }

        return $printTypes === [] ? null : [
            'product_id' => $gid,
            'configuration_id' => $configuration->id,
            'product_type' => $configuration->shopify_product_type,
            'print_types' => $printTypes,
        ];
    }
}
