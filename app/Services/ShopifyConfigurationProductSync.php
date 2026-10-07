<?php

namespace App\Services;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ShopifyConfigurationProductSync
{
    public function __construct(private ConfigurationProductInput $input, private ShopifyGraphqlGateway $graphql) {}

    public function sync(Configuration $configuration): ?string
    {
        $configuration->load('printTypes');
        $shop = $configuration->user;
        if (! $shop instanceof User) {
            throw new RuntimeException('The configuration has no Shopify shop.');
        }

        $complete = $configuration->printTypes->isNotEmpty()
            && $configuration->printTypes->every(fn ($printType) => $printType->collection_id && $printType->product_id && $printType->selected_variant_ids !== []);
        if (! $complete) {
            if ($configuration->shopify_product_id) {
                $this->setDraft($shop, $configuration->shopify_product_id);
            }

            return $configuration->shopify_product_id;
        }

        $input = $this->input->build($configuration);
        $existing = $this->findProduct($shop, $configuration);
        if ($configuration->image_path) {
            if ($existing['media_has_more'] ?? false) {
                throw new RuntimeException('The Shopify product has too many images to safely update its image.');
            }

            $existingMediaIds = collect($existing['media'] ?? [])->pluck('id')->filter()->values();
            $oldImageId = $configuration->shopify_image_id;
            $reuseImage = $oldImageId
                && $configuration->shopify_synced_image_path === $configuration->image_path
                && $existingMediaIds->contains($oldImageId);
            if (! $reuseImage && $existingMediaIds->count() >= 250) {
                throw new RuntimeException('The Shopify product has too many images to safely add a new one.');
            }
            if ($reuseImage) {
                $primaryImage = ['id' => $oldImageId];
            } else {
                $source = Storage::disk('public')->url($configuration->image_path);
                if (! str_starts_with($source, 'https://')) {
                    throw new RuntimeException('Set a public HTTPS APP_URL before syncing a configuration image to Shopify.');
                }
                $primaryImage = ['originalSource' => $source, 'contentType' => 'IMAGE', 'alt' => $configuration->name];
            }

            $input['files'] = [
                $primaryImage,
                ...$existingMediaIds
                    ->reject(fn ($id) => $id === $oldImageId)
                    ->map(fn ($id) => ['id' => $id])
                    ->all(),
            ];
        }
        $existingVariants = collect($existing['variants'] ?? [])
            ->keyBy(fn ($variant) => $this->optionKey($variant['selectedOptions']));
        $existingOptions = collect($existing['options'] ?? [])->keyBy('name');
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

        $identifier = $existing ? ['id' => $existing['id']] : ['handle' => $input['handle']];
        $body = $this->graphql->query($shop, <<<'GRAPHQL'
            mutation SyncConfigurationProduct($identifier: ProductSetIdentifiers, $input: ProductSetInput!) {
              productSet(identifier: $identifier, input: $input, synchronous: true) {
                product { id media(first: 250) { nodes { id } } }
                userErrors { field message }
              }
            }
            GRAPHQL, ['identifier' => $identifier, 'input' => $input]);

        $errors = data_get($body, 'productSet.userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException('Shopify rejected the configuration product: '.implode('; ', array_column($errors, 'message')));
        }
        $productId = data_get($body, 'productSet.product.id');
        if (! is_string($productId) || $productId === '') {
            throw new RuntimeException('Shopify did not return a product ID.');
        }

        $updates = ['shopify_product_id' => $productId];
        if ($configuration->image_path) {
            $mediaIds = collect(data_get($body, 'productSet.product.media.nodes', []))->pluck('id')->filter()->values();
            $imageId = $reuseImage
                ? $oldImageId
                : $mediaIds->first(fn ($id) => ! $existingMediaIds->contains($id));
            if (! is_string($imageId) || $imageId === '') {
                throw new RuntimeException('Shopify did not return the configuration image ID.');
            }
            if ($mediaIds->first() !== $imageId) {
                $reordered = $this->graphql->query($shop, <<<'GRAPHQL'
                    mutation ReorderConfigurationImage($id: ID!, $moves: [MoveInput!]!) {
                      productReorderMedia(id: $id, moves: $moves) {
                        job { id }
                        mediaUserErrors { field message }
                      }
                    }
                    GRAPHQL, ['id' => $productId, 'moves' => [['id' => $imageId, 'newPosition' => 0]]]);
                $reorderErrors = data_get($reordered, 'productReorderMedia.mediaUserErrors', []);
                if ($reorderErrors !== []) {
                    throw new RuntimeException('Shopify could not position the configuration image first: '.implode('; ', array_column($reorderErrors, 'message')));
                }
            }
            $updates['shopify_image_id'] = $imageId;
            $updates['shopify_synced_image_path'] = $configuration->image_path;
        }
        $configuration->update($updates);

        return $productId;
    }

    private function setDraft(User $shop, string $productId): void
    {
        $body = $this->graphql->query($shop, <<<'GRAPHQL'
            mutation DraftConfigurationProduct($identifier: ProductSetIdentifiers, $input: ProductSetInput!) {
              productSet(identifier: $identifier, input: $input, synchronous: true) {
                userErrors { field message }
              }
            }
            GRAPHQL, ['identifier' => ['id' => $productId], 'input' => ['status' => 'DRAFT']]);
        $errors = data_get($body, 'productSet.userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException('Shopify could not set the configuration product to draft: '.implode('; ', array_column($errors, 'message')));
        }
    }

    /** @return array<string, mixed>|null */
    private function findProduct(User $shop, Configuration $configuration): ?array
    {
        $variants = [];
        $cursor = null;
        $productId = $configuration->shopify_product_id;

        do {
            $byId = $productId !== null;
            $query = $byId ? <<<'GRAPHQL'
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
                GRAPHQL : <<<'GRAPHQL'
                query ConfigurationProductByHandle($identifier: ProductIdentifierInput!, $cursor: String) {
                  productByIdentifier(identifier: $identifier) {
                    id
                    options { id name optionValues { id name } }
                    media(first: 250) { nodes { id } pageInfo { hasNextPage } }
                    variants(first: 250, after: $cursor) {
                      nodes { id selectedOptions { name value } }
                      pageInfo { hasNextPage endCursor }
                    }
                  }
                }
                GRAPHQL;
            $variables = $byId
                ? ['id' => $productId, 'cursor' => $cursor]
                : ['identifier' => ['handle' => 'frame-up-configuration-'.$configuration->id], 'cursor' => $cursor];
            $body = $this->graphql->query($shop, $query, $variables);
            $product = $body[$byId ? 'product' : 'productByIdentifier'] ?? null;
            if ($product === null) {
                if ($byId) {
                    throw new RuntimeException('The linked Shopify product no longer exists.');
                }

                return null;
            }
            $productId = $product['id'];
            array_push($variants, ...$product['variants']['nodes']);
            $cursor = $product['variants']['pageInfo']['hasNextPage'] ? $product['variants']['pageInfo']['endCursor'] : null;
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
