<?php

namespace App\Services;

use App\Models\User;
use Generator;
use RuntimeException;

class ShopifyProductCatalog
{
    public function __construct(private ShopifyGraphqlGateway $graphql) {}

    /** @return array<int, string> */
    public function productTypes(User $shop): array
    {
        $types = [];
        $cursor = null;

        do {
            $body = $this->graphql->query($shop, <<<'GRAPHQL'
                query ConfigurationProductTypes($cursor: String) {
                  productTypes(first: 250, after: $cursor) {
                    nodes
                    pageInfo { hasNextPage endCursor }
                  }
                }
                GRAPHQL, ['cursor' => $cursor]);
            $connection = data_get($body, 'productTypes');
            if (! is_array($connection)) {
                throw new RuntimeException('Shopify did not return product types.');
            }
            foreach ($connection['nodes'] ?? [] as $type) {
                if (is_string($type) && trim($type) !== '') {
                    $types[$type] = $type;
                }
            }
            $nextCursor = $this->nextCursor($connection);
            if ($nextCursor !== null && $nextCursor === $cursor) {
                throw new RuntimeException('Shopify returned a repeated product type cursor.');
            }
            $cursor = $nextCursor;
        } while ($cursor !== null);

        natcasesort($types);

        return array_values($types);
    }

    /** @return Generator<int, array{id: string, title: string, productType: string, handle?: string}> */
    public function productsOfType(User $shop, string $type): Generator
    {
        $cursor = null;
        $search = 'product_type:'.json_encode($type, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        do {
            $body = $this->graphql->query($shop, <<<'GRAPHQL'
                query ConfigurationTargetProducts($cursor: String, $search: String!) {
                  products(first: 250, after: $cursor, query: $search) {
                    nodes { id title productType handle }
                    pageInfo { hasNextPage endCursor }
                  }
                }
                GRAPHQL, ['cursor' => $cursor, 'search' => $search]);
            $connection = data_get($body, 'products');
            if (! is_array($connection)) {
                throw new RuntimeException('Shopify did not return products for the selected product type.');
            }
            foreach ($connection['nodes'] ?? [] as $product) {
                if (($product['productType'] ?? null) === $type
                    && is_string($product['id'] ?? null)
                    && ! str_starts_with((string) ($product['handle'] ?? ''), 'frame-up-configuration-')) {
                    yield $product;
                }
            }
            $nextCursor = $this->nextCursor($connection);
            if ($nextCursor !== null && $nextCursor === $cursor) {
                throw new RuntimeException('Shopify returned a repeated product cursor.');
            }
            $cursor = $nextCursor;
        } while ($cursor !== null);
    }

    /** @param array<string, mixed> $connection */
    private function nextCursor(array $connection): ?string
    {
        if (! data_get($connection, 'pageInfo.hasNextPage', false)) {
            return null;
        }
        $cursor = data_get($connection, 'pageInfo.endCursor');
        if (! is_string($cursor) || $cursor === '') {
            throw new RuntimeException('Shopify pagination ended without a cursor.');
        }

        return $cursor;
    }
}
