<?php

namespace App\Http\Requests;

use App\Models\Configuration;
use App\Services\CatalogConfigurationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $configuration = $this->route('configuration');

        return $this->user() !== null
            && (! $configuration instanceof Configuration || $configuration->user_id === $this->user()->id);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'shopify_product_type' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'active'])],
            'print_types' => ['present', 'array', 'max:20'],
            'print_types.*.collection_id' => ['nullable', 'integer'],
            'print_types.*.product_id' => ['nullable', 'integer'],
            'print_types.*.variant_ids' => ['present', 'array'],
            'print_types.*.variant_ids.*' => ['integer', 'distinct'],
            'print_types.*.addon_ids' => ['present', 'array'],
            'print_types.*.addon_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $printTypes = $this->input('print_types', []);
            $active = $this->input('status') === 'active';
            if ($active && $printTypes === []) {
                $validator->errors()->add('print_types', 'Add a print type before activating this configuration.');
            }

            $collections = collect(app(CatalogConfigurationOptions::class)->all())->keyBy('id');
            foreach ($printTypes as $index => $printType) {
                $collectionId = $printType['collection_id'] ?? null;
                $productId = $printType['product_id'] ?? null;
                $variantIds = array_map('intval', $printType['variant_ids'] ?? []);
                $addonIds = array_map('intval', $printType['addon_ids'] ?? []);
                $collection = $collections->get($collectionId);
                $surface = $collection ? collect($collection['surfaces'])->firstWhere('id', $productId) : null;

                if (($active || $productId || $variantIds !== [] || $addonIds !== []) && ! $collection) {
                    $validator->errors()->add("print_types.{$index}.collection_id", 'Choose an available print type.');

                    continue;
                }
                if (($active || $productId || $variantIds !== [] || $addonIds !== []) && ! $surface) {
                    $validator->errors()->add("print_types.{$index}.product_id", 'Choose a surface in this print type.');

                    continue;
                }
                if (! $surface) {
                    continue;
                }

                $availableVariants = array_column($surface['variants'], 'id');
                $availableAddons = array_column($surface['addons'], 'id');
                if (array_diff($variantIds, $availableVariants)) {
                    $validator->errors()->add("print_types.{$index}.variant_ids", 'Select preset sizes available for this surface.');
                }
                if (array_diff($addonIds, $availableAddons)) {
                    $validator->errors()->add("print_types.{$index}.addon_ids", 'Select add-ons related to this surface.');
                }
                if ($active && $variantIds === []) {
                    $validator->errors()->add("print_types.{$index}.variant_ids", 'Select at least one preset size.');
                }

                if ($active) {
                    $exclusiveGroups = collect($surface['addons'])
                        ->where('category', 'exclusive')
                        ->groupBy('group');
                    foreach ($exclusiveGroups as $group => $addons) {
                        if (array_intersect($addonIds, $addons->pluck('id')->all()) === []) {
                            $validator->errors()->add("print_types.{$index}.addon_ids", "Select at least one {$group} option.");
                        }
                    }
                }
            }
        }];
    }
}
