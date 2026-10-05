<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class InertiaHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_route_renders_the_dashboard_for_a_verified_shop(): void
    {
        $shop = User::factory()->create([
            'name' => 'inertia-shop.myshopify.com',
            'password' => 'not-returned',
        ]);

        $response = $this
            ->withoutMiddleware([VerifyShopify::class, Billable::class])
            ->actingAs($shop)
            ->get('/?shop=inertia-shop.myshopify.com&host=test-host');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.configurations', 0)
            ->where('stats.printTypes', 0)
            ->where('indexUrl', '/configurations')
            ->where('createUrl', '/configurations/create')
            ->where('catalogUrl', '/catalog')
        );
    }

    public function test_catalog_route_renders_the_pull_page_for_a_verified_shop(): void
    {
        $shop = User::factory()->create(['name' => 'catalog-shop.myshopify.com']);

        $this->withoutMiddleware([VerifyShopify::class, Billable::class])
            ->actingAs($shop)
            ->get('/catalog?shop=catalog-shop.myshopify.com&host=test-host')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog')
                ->where('catalogSyncUrl', '/catalog/sync')
                ->where('homeUrl', '/')
            );
    }

    public function test_home_route_uses_package_authentication_and_billing_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('home');

        $this->assertNotNull($route);
        $this->assertContains('verify.shopify', $route->middleware());
        $this->assertContains('billable', $route->middleware());

        $catalogRoute = app('router')->getRoutes()->getByName('catalog.page');
        $this->assertNotNull($catalogRoute);
        $this->assertContains('verify.shopify', $catalogRoute->middleware());
        $this->assertContains('billable', $catalogRoute->middleware());
    }
}
