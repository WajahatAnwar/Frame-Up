<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class TemporaryAuthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_token_is_rejected_as_json(): void
    {
        $response = $this->getJson('/temporary-auth-check');

        $response->assertUnauthorized()->assertJsonStructure(['error']);
    }

    public function test_invalid_token_is_rejected_as_json(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer not-a-shopify-token')
            ->getJson('/temporary-auth-check');

        $response->assertUnauthorized()->assertJson([
            'error' => 'Shopify session token is missing or invalid.',
        ]);
    }

    public function test_verified_shop_context_returns_safe_schema(): void
    {
        $shop = User::factory()->create([
            'name' => 'diagnostic-shop.myshopify.com',
            'password' => 'not-returned',
        ]);

        $response = $this->withoutMiddleware(VerifyShopify::class)
            ->actingAs($shop)
            ->getJson('/temporary-auth-check');

        $response->assertOk()
            ->assertJsonStructure(['ok', 'message', 'shop', 'server_time'])
            ->assertJsonPath('ok', true)
            ->assertJsonPath('shop', 'diagnostic-shop.myshopify.com')
            ->assertJsonMissing(['password' => 'not-returned']);
        $this->assertStringNotContainsString('access_token', $response->getContent());
        $this->assertStringNotContainsString('session_token', $response->getContent());
    }

    public function test_temporary_route_has_package_auth_and_throttle_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('temporary.auth.check');

        $this->assertNotNull($route);
        $this->assertContains('verify.shopify', $route->middleware());
        $this->assertContains('temporary.auth.json', $route->middleware());
        $this->assertContains('throttle:temporary-auth', $route->middleware());
    }
}
