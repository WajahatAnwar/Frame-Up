<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use App\Services\ConfigurationProductInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class SettingsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyShopify::class, Billable::class]);
    }

    public function test_multiplier_defaults_to_two_and_can_be_changed_per_merchant(): void
    {
        $merchant = User::factory()->create();
        $other = User::factory()->create();

        $this->assertSame(2, $merchant->fresh()->price_multiplier);
        $this->actingAs($merchant)->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings')
            ->where('priceMultiplier', 2));

        $this->actingAs($merchant)->put('/settings', ['price_multiplier' => 0])->assertSessionHasErrors('price_multiplier');
        $this->actingAs($merchant)->put('/settings', ['price_multiplier' => 1.5])->assertSessionHasErrors('price_multiplier');
        $this->actingAs($merchant)->put('/settings', ['price_multiplier' => 3])->assertRedirect();

        $this->assertSame(3, $merchant->fresh()->price_multiplier);
        $this->assertSame(2, $other->fresh()->price_multiplier);
    }

    public function test_variant_price_and_print_type_total_use_the_saved_multiplier(): void
    {
        $merchant = User::factory()->create();
        DB::table('collections')->insert(['id' => 10, 'user_id' => 999, 'title' => 'Canvas']);
        DB::table('products')->insert([
            ['id' => 20, 'title' => 'Canvas surface', 'addons_check' => 1],
            ['id' => 30, 'title' => 'Image enhancement', 'addons_check' => 1],
        ]);
        DB::table('collection_product')->insert(['id' => 40, 'collection_id' => 10, 'product_id' => 20]);
        DB::table('product_varients')->insert([
            ['id' => 50, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 4, 'height' => 6, 'price' => 10],
            ['id' => 51, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 20],
            ['id' => 80, 'product_id' => 30, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 5],
        ]);
        DB::table('product_settings')->insert(['id' => 60, 'product_id' => 30, 'addon_options' => 'basic']);
        DB::table('addon_product')->insert(['id' => 70, 'product_id' => 20, 'addon_id' => 30, 'status' => 1]);

        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'draft',
        ]);
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50, 51], 'selected_addon_ids' => [30],
        ]);

        $this->assertSame(['30.00', '50.00'], array_column(app(ConfigurationProductInput::class)->build($configuration)['variants'], 'price'));

        $payload = [
            'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'draft',
            'print_types' => [[
                'collection_id' => 10, 'product_id' => 20, 'variant_ids' => [50, 51], 'addon_ids' => [30],
            ]],
        ];
        $this->actingAs($merchant)->postJson('/configurations/price-preview', $payload)
            ->assertOk()
            ->assertJsonPath('print_types.0.min_price', '30.00')
            ->assertJsonPath('print_types.0.max_price', '50.00')
            ->assertJsonPath('print_types.0.variant_count', 2);

        $this->actingAs($merchant)->put('/settings', ['price_multiplier' => 3])->assertRedirect();
        $this->assertSame(['45.00', '75.00'], array_column(app(ConfigurationProductInput::class)->build($configuration->fresh())['variants'], 'price'));
        $this->actingAs($merchant)->postJson('/configurations/price-preview', $payload)
            ->assertJsonPath('print_types.0.min_price', '45.00')
            ->assertJsonPath('print_types.0.max_price', '75.00');
    }
}
