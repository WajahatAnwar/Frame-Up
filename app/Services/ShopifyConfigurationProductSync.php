<?php

namespace App\Services;

use App\Models\Configuration;
use App\Models\ConfigurationShopifyProduct;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ShopifyConfigurationProductSync
{
    public function __construct(
        private ConfigurationProductInput $input,
        private ShopifyGraphqlGateway $graphql,
        private ShopifyProductCatalog $catalog,
    ) {}

    public function sync(Configuration $configuration): int
    {
        $configuration->load('printTypes');
        $shop = $configuration->user;
        if (! $shop instanceof User) {
            throw new RuntimeException('The configuration has no Shopify shop.');
        }

        $complete = $configuration->printTypes->isNotEmpty()
            && $configuration->printTypes->every(fn ($printType) => $printType->collection_id && $printType->product_id && $printType->selected_variant_ids !== []);
        if ($configuration->status !== 'active' || ! $complete) {
            return 0;
        }

        $input = $this->input->build($configuration);
        $products = iterator_to_array($this->catalog->productsOfType($shop, $configuration->shopify_product_type), false);
        $synced = 0;
        foreach ($products as $product) {
            $this->syncProduct($shop, $configuration, $product, $input);
            $synced++;
        }

        return $synced;
    }

    /** @param array{id: string, title: string, productType: string, handle?: string} $product @param array<string, mixed> $template */
    private function syncProduct(User $shop, Configuration $configuration, array $product, array $template): void
    {
        $productId = $product['id'];
        $existing = $this->findProduct($shop, $productId);
        $target = $configuration->shopifyProducts()->firstOrNew(['shopify_product_id' => $productId]);
        $input = [
            'productOptions' => $template['productOptions'],
            'variants' => $template['variants'],
        ];

        if ($configuration->image_path) {
            $this->addImageInput($configuration, $product, $existing, $target, $input);
        }

        $existingVariants = collect($existing['variants'])
            ->keyBy(fn ($variant) => $this->optionKey($variant['selectedOptions']));
        $existingOptions = collect($existing['options'])->keyBy('name');
        foreach ($input['productOptions'] as &$option) {
            $oldOption = $existingOptions->get($option['name']);
            if (! $oldOption) {
                continue;
            }
            $option['id'] = $oldOption['id'];
            $oldValues = collect($oldOption['optionValues'])->keyBy('name');
            foreach ($option['values'] as &$value) {
                if ($oldValues->has($value['name'])) {
                    $value['id'] = $oldValues->get($value['name'])['id'];
                }
            }
            unset($value);
        }
        unset($option);

        foreach ($input['variants'] as &$variant) {
            $key = $this->optionKey(collect($variant['optionValues'])
                ->map(fn ($value) => ['name' => $value['optionName'], 'value' => $value['name']])->all());
            if ($existingVariants->has($key)) {
                $variant['id'] = $existingVariants->get($key)['id'];
                unset($variant['sku'], $variant['inventoryPolicy']);
            }
        }
        unset($variant);

        $body = $this->graphql->query($shop, <<<'GRAPHQL'
            mutation SyncConfigurationProduct($identifier: ProductSetIdentifiers, $input: ProductSetInput!) {
              productSet(identifier: $identifier, input: $input, synchronous: true) {
                product { id media(first: 250) { nodes { id } } }
                userErrors { field message }
              }
            }
            GRAPHQL, ['identifier' => ['id' => $productId], 'input' => $input]);

        $errors = data_get($body, 'productSet.userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException('Shopify rejected the configuration for '.$product['title'].': '.implode('; ', array_column($errors, 'message')));
        }
        if (data_get($body, 'productSet.product.id') !== $productId) {
            throw new RuntimeException('Shopify did not return the expected product ID for '.$product['title'].'.');
        }

        if ($configuration->image_path) {
            $this->saveImageResult($shop, $configuration, $product, $existing, $target, $body);
        }
        $target->save();
    }

    /** @param array<string, mixed> $product @param array<string, mixed> $existing @param array<string, mixed> $input */
    private function addImageInput(Configuration $configuration, array $product, array $existing, ConfigurationShopifyProduct $target, array &$input): void
    {
        if ($existing['media_has_more']) {
            throw new RuntimeException('The Shopify product '.$product['title'].' has too many images to safely update its image.');
        }

        $existingMediaIds = collect($existing['media'])->pluck('id')->filter()->values();
        $oldImageId = $target->shopify_image_id;
        $reuseImage = $oldImageId
            && $target->shopify_synced_image_path === $configuration->image_path
            && $existingMediaIds->contains($oldImageId);
        if (! $reuseImage && $existingMediaIds->count() >= 250) {
            throw new RuntimeException('The Shopify product '.$product['title'].' has too many images to safely add a new one.');
        }

        if ($reuseImage) {
            $primaryImage = ['id' => $oldImageId];
        } else {
            $source = Storage::disk('public')->url($configuration->image_path);
            if (! str_starts_with($source, 'https://')) {
                throw new RuntimeException('Set a public HTTPS APP_URL before syncing a configuration image to Shopify.');
            }
            $primaryImage = ['originalSource' => $source, 'contentType' => 'IMAGE', 'alt' => $product['title']];
        }

        $input['files'] = [
            $primaryImage,
            ...$existingMediaIds
                ->reject(fn ($id) => $id === $oldImageId)
                ->map(fn ($id) => ['id' => $id])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $product @param array<string, mixed> $existing @param array<string, mixed> $body */
    private function saveImageResult(User $shop, Configuration $configuration, array $product, array $existing, ConfigurationShopifyProduct $target, array $body): void
    {
        $mediaIds = collect(data_get($body, 'productSet.product.media.nodes', []))->pluck('id')->filter()->values();
        $existingMediaIds = collect($existing['media'])->pluck('id')->filter()->values();
        $oldImageId = $target->shopify_image_id;
        $reuseImage = $oldImageId
            && $target->shopify_synced_image_path === $configuration->image_path
            && $existingMediaIds->contains($oldImageId);
        $imageId = $reuseImage
            ? $oldImageId
            : $mediaIds->first(fn ($id) => ! $existingMediaIds->contains($id));
        if (! is_string($imageId) || $imageId === '') {
            throw new RuntimeException('Shopify did not return the configuration image ID for '.$product['title'].'.');
        }

        if ($mediaIds->first() !== $imageId) {
            $reordered = $this->graphql->query($shop, <<<'GRAPHQL'
                mutation ReorderConfigurationImage($id: ID!, $moves: [MoveInput!]!) {
                  productReorderMedia(id: $id, moves: $moves) {
                    job { id }
                    mediaUserErrors { field message }
                  }
                }
                GRAPHQL, ['id' => $product['id'], 'moves' => [['id' => $imageId, 'newPosition' => 0]]]);
            $errors = data_get($reordered, 'productReorderMedia.mediaUserErrors', []);
            if ($errors !== []) {
                throw new RuntimeException('Shopify could not position the configuration image first for '.$product['title'].': '.implode('; ', array_column($errors, 'message')));
            }
        }

        $target->shopify_image_id = $imageId;
        $target->shopify_synced_image_path = $configuration->image_path;
    }

    /** @return array<string, mixed> */
    private function findProduct(User $shop, string $productId): array
    {
        $variants = [];
        $cursor = null;

        do {
            $body = $this->graphql->query($shop, <<<'GRAPHQL'
                query ConfigurationProduct($id: ID!, $cursor: String) {
                  product(id: $id) {
                    id
                    options { id name optionValues { id name } }
                    media(first: 250) { nodes { id } pageInfo { hasNextPage } }
                    variants(first: 250, after: $cursor) {
                      nodes { id selectedOptions { name value } }
                      pageInfo { hasNextPage endCursor }
                    }
                  }
                }
                GRAPHQL, ['id' => $productId, 'cursor' => $cursor]);
            $product = $body['product'] ?? null;
            if (! is_array($product) || $product['id'] !== $productId) {
                throw new RuntimeException('A targeted Shopify product no longer exists.');
            }
            array_push($variants, ...$product['variants']['nodes']);
            $nextCursor = $product['variants']['pageInfo']['hasNextPage'] ? $product['variants']['pageInfo']['endCursor'] : null;
            if ($product['variants']['pageInfo']['hasNextPage'] && (! is_string($nextCursor) || $nextCursor === '' || $nextCursor === $cursor)) {
                throw new RuntimeException('Shopify did not return a valid variant cursor for a targeted product.');
            }
            $cursor = $nextCursor;
        } while ($cursor !== null);

        return [
            'id' => $productId,
            'variants' => $variants,
            'options' => $product['options'],
            'media' => $product['media']['nodes'] ?? [],
            'media_has_more' => $product['media']['pageInfo']['hasNextPage'] ?? false,
        ];
    }

    /** @param array<int, array<string, string>> $options */
    private function optionKey(array $options): string
    {
        return json_encode(collect($options)->pluck('value', 'name')->sortKeys()->all(), JSON_THROW_ON_ERROR);
    }
}
