<?php

namespace App\Http\Requests;

use App\Models\Configuration;
use App\Models\ConfigurationPrintType;
use App\Services\CatalogConfigurationOptions;
use App\Services\ConfigurationProductInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

class SaveConfigurationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $collections = collect(app(CatalogConfigurationOptions::class)->all())->keyBy('id');
        $printTypes = collect($this->input('print_types', []))->map(function (array $printType) use ($collections): array {
            $printType['variant_ids'] = collect($printType['variant_ids'] ?? [])
                ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            $printType['addon_ids'] = collect($printType['addon_ids'] ?? [])
                ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            $collection = $collections->get($printType['collection_id'] ?? null);
            $surface = $collection ? collect($collection['surfaces'])->firstWhere('id', $printType['product_id'] ?? null) : null;
            $exclusiveGroups = collect($surface['addons'] ?? [])->where('category', 'exclusive')->groupBy('group');
            foreach ($exclusiveGroups as $addons) {
                if (array_intersect($printType['addon_ids'], $addons->pluck('id')->all()) === []) {
                    $printType['addon_ids'][] = $addons->first()['id'];
                }
            }

            return $printType;
        })->values()->all();

        $this->merge([
            'print_types' => $printTypes,
            'status' => $this->routeIs('configurations.price-preview') ? 'draft' : 'active',
        ]);
    }

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
            'name' => [$this->routeIs('configurations.price-preview') ? 'nullable' : 'required', 'string', 'max:255'],
            'shopify_product_type' => [
                'required', 'string', 'max:255',
                ...($this->routeIs('configurations.price-preview') ? [] : [
                    Rule::unique('configurations', 'shopify_product_type')
                        ->where('user_id', $this->user()->id)
                        ->ignore($this->route('configuration')?->id),
                ]),
            ],
            'status' => ['required', Rule::in(['draft', 'active'])],
            'image' => ['prohibited'],
            'print_types' => ['present', 'array', 'max:20'],
            'print_types.*.collection_id' => ['nullable', 'integer'],
            'print_types.*.product_id' => ['nullable', 'integer'],
            'print_types.*.variant_ids' => ['present', 'array'],
            'print_types.*.variant_ids.*' => ['integer'],
            'print_types.*.addon_ids' => ['present', 'array'],
            'print_types.*.addon_ids.*' => ['integer'],
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
                $validator->errors()->add('print_types', 'Add at least one print type.');
            }

            $collections = collect(app(CatalogConfigurationOptions::class)->all())->keyBy('id');
            $surfaceIndexes = [];
            $maximumPossibleVariants = 0;
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

                if ($productId !== null && $productId !== '' && isset($surfaceIndexes[(int) $productId])) {
                    $validator->errors()->add("print_types.{$index}.product_id", 'Each surface can only be used once in a configuration.');
                } else {
                    $surfaceIndexes[(int) $productId] = $index;
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

                $selectedMountCount = collect($surface['addons'])
                    ->where('category', 'advance')
                    ->whereIn('id', $addonIds)
                    ->count();
                if ($active && collect($surface['addons'])->contains('category', 'advance') && $selectedMountCount === 0) {
                    $validator->errors()->add("print_types.{$index}.addon_ids", 'Select at least one advanced add-on.');
                }
                $maximumPossibleVariants += count($variantIds) * max(1, $selectedMountCount);

                $exclusiveGroups = collect($surface['addons'])
                    ->where('category', 'exclusive')
                    ->groupBy('group');
                foreach ($exclusiveGroups as $group => $addons) {
                    $selectedCount = count(array_intersect($addonIds, $addons->pluck('id')->all()));
                    if ($selectedCount > 1 || ($active && $selectedCount === 0)) {
                        $validator->errors()->add("print_types.{$index}.addon_ids", "Select exactly one {$group} option.");
                    }
                }
            }

            if ($validator->errors()->isNotEmpty() || $maximumPossibleVariants <= ConfigurationProductInput::MAX_VARIANTS) {
                return;
            }

            $configuration = $this->route('configuration') instanceof Configuration
                ? $this->route('configuration')
                : new Configuration;
            $configuration->setRelation('printTypes', collect($printTypes)->map(fn (array $printType) => new ConfigurationPrintType([
                'collection_id' => $printType['collection_id'] ?? null,
                'product_id' => $printType['product_id'] ?? null,
                'selected_variant_ids' => $printType['variant_ids'],
                'selected_addon_ids' => $printType['addon_ids'],
            ])));

            try {
                $variantCount = app(ConfigurationProductInput::class)->variantCount($configuration);
            } catch (RuntimeException) {
                return;
            }

            if ($variantCount > ConfigurationProductInput::MAX_VARIANTS) {
                $validator->errors()->add('print_types', "This configuration would create {$variantCount} variants. The maximum is 2,000.");
            }
        }];
    }
}
