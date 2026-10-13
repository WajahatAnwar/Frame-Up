<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontProductPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_preview_keeps_surface_and_mount_metadata_separate_for_each_print_type(): void
    {
        $this->freezeTime();
        config()->set('shopify-app.api_secret', 'preview-test-secret');
        $shop = User::factory()->create(['name' => 'preview-test.myshopify.com']);
        DB::table('collections')->insert(['id' => 10, 'user_id' => 999, 'title' => 'Metal']);
        DB::table('products')->insert([
            ['id' => 20, 'title' => 'Gloss', 'addons_check' => 1],
            ['id' => 21, 'title' => 'Satin', 'addons_check' => 1],
            ['id' => 30, 'title' => 'Stand', 'addons_check' => 1],
        ]);
        DB::table('product_settings')->insert(['product_id' => 20, 'material_type' => 'Metal', 'surface_roughness' => .04]);
        DB::table('product_settings')->insert(['product_id' => 21, 'material_type' => 'Metal', 'surface_roughness' => .35]);
        DB::table('product_settings')->insert([
            'product_id' => 30, 'addon_options' => 'advance', 'selectMount' => 'stands', 'selectHangerType' => 'acrylic-stand',
            'FloatDepth' => '.3', 'FrameColor' => '#8B4513', 'inner_inset' => .66, 'image_file' => 'private-source.jpg',
        ]);
        foreach ([20, 21] as $id) {
            DB::table('collection_product')->insert(['collection_id' => 10, 'product_id' => $id]);
            DB::table('product_varients')->insert(['id' => $id, 'product_id' => $id, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 12]);
            DB::table('addon_product')->insert(['product_id' => $id, 'addon_id' => 30, 'status' => 1]);
        }
        $configuration = Configuration::create(['user_id' => $shop->id, 'name' => 'Metal art', 'status' => 'active', 'shopify_product_type' => 'Art']);
        $configuration->shopifyProducts()->create(['shopify_product_id' => 'gid://shopify/Product/123']);
        foreach ([20, 21] as $id) {
            $configuration->printTypes()->create(['collection_id' => 10, 'product_id' => $id, 'position' => $id, 'selected_variant_ids' => [$id], 'selected_addon_ids' => [30]]);
        }
        $response = $this->getJson($this->signedUrl($shop->name));
        $response->assertOk()->assertJsonPath('enabled', true)
            ->assertJsonPath('data.print_types.0.option', 'Metal - Gloss')
            ->assertJsonPath('data.print_types.1.option', 'Metal - Satin')
            ->assertJsonPath('data.print_types.0.mounts.0.settings.selectHangerType', 'acrylic-stand')
            ->assertJsonPath('data.print_types.1.mounts.0.settings.FloatDepth', '.3');
        $this->assertEquals(.04, $response->json('data.print_types.0.surface.settings.surface_roughness'));
        $this->assertEquals(.35, $response->json('data.print_types.1.surface.settings.surface_roughness'));
        $this->assertArrayNotHasKey('image_file', $response->json('data.print_types.0.mounts.0.settings'));
        $this->getJson($this->signedUrl($shop->name, '124'))->assertOk()->assertJsonPath('enabled', false);
        $other = User::factory()->create(['name' => 'other-preview.myshopify.com']);
        $this->getJson($this->signedUrl($other->name))->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_unsigned_preview_is_rejected(): void
    {
        $this->getJson('/storefront/preview?product_id=123&product_type=Art')->assertUnauthorized();
    }

    public function test_preview_assets_are_served_from_public_with_correct_module_and_hdr_types(): void
    {
        $this->get('/storefront/assets/preview-runtime.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $hdr = $this->get('/storefront/assets/lebombo_1k.hdr');
        $hdr->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame(public_path('frame-up-preview/lebombo_1k.hdr'), $hdr->baseResponse->getFile()->getPathname());
        $this->get('/storefront/assets/product-preview.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/storefront/assets/room-living.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/storefront/assets/room-console.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_preview_asset_route_cannot_expose_other_public_files_or_php(): void
    {
        $this->get('/storefront/assets/index.php')->assertNotFound();
        $this->get('/storefront/assets/.env')->assertNotFound();
        $this->get('/storefront/assets/missing.js')->assertNotFound();
    }

    private function signedUrl(string $shop, string $productId = '123'): string
    {
        $params = ['shop' => $shop, 'timestamp' => (string) now()->timestamp, 'product_id' => $productId, 'product_type' => 'Art'];
        ksort($params);
        $message = collect($params)->map(fn ($value, $key) => $key.'='.$value)->implode('');
        $params['signature'] = hash_hmac('sha256', $message, 'preview-test-secret');

        return '/storefront/preview?'.http_build_query($params);
    }
}
