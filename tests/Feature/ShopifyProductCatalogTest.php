<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ShopifyGraphqlGateway;
use App\Services\ShopifyProductCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class ShopifyProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_type_endpoint_returns_all_paginated_shopify_types(): void
    {
        $shop = User::factory()->create();
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($user, $query, $variables) => $user->is($shop)
                && str_contains($query, 'productTypes(first: 250')
                && $variables['cursor'] === null)
            ->andReturn(['productTypes' => [
                'nodes' => ['Frame', 'Canvas'],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'],
            ]]);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($user, $query, $variables) => $user->is($shop)
                && str_contains($query, 'productTypes(first: 250')
                && $variables['cursor'] === 'next')
            ->andReturn(['productTypes' => [
                'nodes' => ['Metal', 'Frame'],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]);

        $this->withoutMiddleware([VerifyShopify::class, Billable::class])
            ->actingAs($shop)
            ->getJson('/shopify/product-types')
            ->assertOk()
            ->assertExactJson(['product_types' => ['Canvas', 'Frame', 'Metal']]);
    }

    public function test_product_search_pages_and_ignores_inexact_type_matches(): void
    {
        $shop = User::factory()->create();
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($user, $query, $variables) => $user->is($shop)
                && str_contains($query, 'products(first: 250')
                && $variables === ['cursor' => null, 'search' => 'product_type:"Frame"'])
            ->andReturn(['products' => [
                'nodes' => [
                    ['id' => 'gid://shopify/Product/1', 'title' => 'A', 'productType' => 'Frame'],
                    ['id' => 'gid://shopify/Product/2', 'title' => 'B', 'productType' => 'Frame supplies'],
                    ['id' => 'gid://shopify/Product/legacy', 'title' => 'Old config', 'productType' => 'Frame', 'handle' => 'frame-up-configuration-12'],
                ],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'],
            ]]);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($user, $query, $variables) => $user->is($shop)
                && $variables === ['cursor' => 'next', 'search' => 'product_type:"Frame"'])
            ->andReturn(['products' => [
                'nodes' => [['id' => 'gid://shopify/Product/3', 'title' => 'C', 'productType' => 'Frame']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]);

        $products = iterator_to_array(app(ShopifyProductCatalog::class)->productsOfType($shop, 'Frame'));

        $this->assertSame(['gid://shopify/Product/1', 'gid://shopify/Product/3'], array_column($products, 'id'));
    }
}
