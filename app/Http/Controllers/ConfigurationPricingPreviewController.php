<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConfigurationRequest;
use App\Models\Configuration;
use App\Models\ConfigurationPrintType;
use App\Services\ConfigurationProductInput;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ConfigurationPricingPreviewController extends Controller
{
    public function __invoke(SaveConfigurationRequest $request, ConfigurationProductInput $productInput): JsonResponse
    {
        $rows = collect($request->validated('print_types'))
            ->map(fn (array $row, int $index) => ['index' => $index, 'row' => $row])
            ->filter(fn (array $entry) => ! empty($entry['row']['collection_id'])
                && ! empty($entry['row']['product_id'])
                && ! empty($entry['row']['variant_ids']))
            ->values();

        if ($rows->isEmpty()) {
            return response()->json(['print_types' => []]);
        }

        $configuration = new Configuration([
            'name' => $request->validated('name'),
            'shopify_product_type' => $request->validated('shopify_product_type'),
            'status' => 'draft',
        ]);
        $configuration->setRelation('user', $request->user());
        $configuration->setRelation('printTypes', $rows->map(fn (array $entry) => new ConfigurationPrintType([
            'collection_id' => $entry['row']['collection_id'],
            'product_id' => $entry['row']['product_id'],
            'selected_variant_ids' => $entry['row']['variant_ids'],
            'selected_addon_ids' => $entry['row']['addon_ids'],
        ])));

        try {
            $summaries = collect($productInput->priceSummaries($configuration))
                ->map(fn (array $summary) => [
                    ...$summary,
                    'index' => $rows[$summary['index']]['index'],
                ])->all();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['print_types' => $summaries]);
    }
}
