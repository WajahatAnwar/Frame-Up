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

    public function test_home_route_renders_the_inertia_react_page_for_a_verified_shop(): void
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
            ->component('Home')
            ->where('shop.domain', 'inertia-shop.myshopify.com')
            ->where('embeddedContext.shop', 'inertia-shop.myshopify.com')
            ->where('embeddedContext.host', 'test-host')
            ->where('temporaryAuthCheckUrl', '/temporary-auth-check')
        );
    }

    public function test_home_route_uses_package_authentication_and_billing_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('home');

        $this->assertNotNull($route);
        $this->assertContains('verify.shopify', $route->middleware());
        $this->assertContains('billable', $route->middleware());
    }
}
