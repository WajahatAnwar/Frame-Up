<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class ShopifyGraphqlGateway
{
    /** @param array<string, mixed> $variables @return array<string, mixed> */
    public function query(User $shop, string $query, array $variables): array
    {
        $result = $shop->api()->graph($query, $variables);
        if (($result['status'] ?? null) === 401) {
            throw new RuntimeException('Shopify rejected the shop access token (HTTP 401). Reauthorize the shop before syncing this product.');
        }

        $body = $result['body'] ?? null;
        $body = is_object($body) && method_exists($body, 'toArray') ? $body->toArray() : $body;
        if ($result['errors'] ?? false) {
            $errors = is_array($body) ? ($body['errors'] ?? $result['errors']) : $result['errors'];
            $messages = collect(is_array($errors) ? $errors : [$errors])
                ->map(fn ($error) => is_array($error) ? ($error['message'] ?? null) : (is_string($error) ? $error : null))
                ->filter()
                ->implode('; ');

            throw new RuntimeException('Shopify GraphQL request failed (HTTP '.($result['status'] ?? 'unknown').')'.($messages !== '' ? ': '.$messages : '.'));
        }

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('Shopify returned no GraphQL data.');
        }

        return $body['data'];
    }
}
