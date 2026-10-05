<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class ConfigurationFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyShopify::class, Billable::class]);
    }

    public function test_dashboard_and_paginated_list_are_scoped_to_the_merchant(): void
    {
        $merchant = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 12) as $number) {
            Configuration::create([
                'user_id' => $merchant->id,
                'name' => "Configuration {$number}",
                'shopify_product_type' => 'Wall art',
                'status' => $number === 1 ? 'active' : 'draft',
            ]);
        }
        Configuration::create([
            'user_id' => $other->id,
            'name' => 'Other shop',
            'shopify_product_type' => 'Posters',
            'status' => 'active',
        ]);
        DB::table('collections')->insert(['id' => 10, 'user_id' => 999, 'title' => 'Canvas']);

        $this->actingAs($merchant)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.configurations', 12)
            ->where('stats.active', 1)
            ->where('stats.draft', 11)
            ->where('stats.printTypes', 1));

        $this->actingAs($merchant)->get('/configurations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurations/Index')
            ->where('configurations.total', 12)
            ->has('configurations.data', 10));

        $this->actingAs($merchant)->get('/configurations?search=Other')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('configurations.total', 0));
    }

    public function test_merchant_can_create_edit_and_delete_a_configuration_using_catalog_relationships(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();

        $this->actingAs($merchant)->post('/configurations', $payload)->assertRedirect();

        $configuration = Configuration::firstOrFail();
        $this->assertSame($merchant->id, $configuration->user_id);
        $this->assertSame('active', $configuration->status);
        $this->assertSame([50], $configuration->printTypes->first()->selected_variant_ids);
        $this->assertSame([30, 31, 32, 33, 34], $configuration->printTypes->first()->selected_addon_ids);

        $this->actingAs($merchant)->get('/configurations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('configurations.data.0.print_type_names.0', 'Canvas'));

        $this->actingAs($merchant)->get("/configurations/{$configuration->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurations/Form')
            ->where('mode', 'show')
            ->where('configuration.id', $configuration->id)
            ->where('catalog.0.surfaces.0.addons.2.group', 'Wrap style')
            ->where('catalog.0.surfaces.0.addons.3.category', 'advance')
            ->where('catalog.0.surfaces.0.addons.4.category', 'other')
            ->has('catalog', 1));

        $payload['name'] = 'Updated canvas';
        $payload['status'] = 'draft';
        $payload['print_types'] = [];
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)->assertRedirect();
        $this->assertSame('Updated canvas', $configuration->fresh()->name);
        $this->assertSame(0, $configuration->printTypes()->count());

        $this->actingAs($merchant)->delete("/configurations/{$configuration->id}")->assertRedirect();
        $this->assertDatabaseMissing('configurations', ['id' => $configuration->id]);
    }

    public function test_active_configuration_rejects_unrelated_variants_and_missing_exclusive_options(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $payload['print_types'][0]['variant_ids'] = [51];
        $payload['print_types'][0]['addon_ids'] = [30];

        $this->actingAs($merchant)->post('/configurations', $payload)
            ->assertSessionHasErrors(['print_types.0.variant_ids', 'print_types.0.addon_ids']);
        $this->assertDatabaseCount('configurations', 0);
    }

    public function test_merchants_cannot_access_each_others_configurations(): void
    {
        $owner = User::factory()->create();
        $merchant = User::factory()->create();
        $configuration = Configuration::create([
            'user_id' => $owner->id,
            'name' => 'Private',
            'shopify_product_type' => 'Art',
            'status' => 'draft',
        ]);

        $this->actingAs($merchant)->get("/configurations/{$configuration->id}")->assertNotFound();
        $this->actingAs($merchant)->delete("/configurations/{$configuration->id}")->assertNotFound();
        $this->actingAs($merchant)->putJson("/configurations/{$configuration->id}", $this->validPayload())->assertForbidden();
        $this->assertDatabaseHas('configurations', ['id' => $configuration->id]);
    }

    private function seedCatalog(): void
    {
        DB::table('collections')->insert(['id' => 10, 'user_id' => 999, 'title' => 'Canvas']);
        DB::table('products')->insert([
            ['id' => 20, 'title' => 'Giclée Canvas', 'addons_check' => 1],
            ['id' => 21, 'title' => 'Other surface', 'addons_check' => 1],
            ['id' => 30, 'title' => 'Image enhancement', 'addons_check' => 1],
            ['id' => 31, 'title' => 'Gallery wrap', 'addons_check' => 1],
            ['id' => 32, 'title' => 'Mirror edge', 'addons_check' => 1],
            ['id' => 33, 'title' => 'Floating frame', 'addons_check' => 1],
            ['id' => 34, 'title' => 'Mount', 'addons_check' => 1],
        ]);
        DB::table('collection_product')->insert(['id' => 40, 'collection_id' => 10, 'product_id' => 20]);
        DB::table('product_varients')->insert([
            ['id' => 50, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1],
            ['id' => 51, 'product_id' => 21, 'variant_type' => 'predefined', 'is_active' => 1],
        ]);
        DB::table('product_settings')->insert([
            ['id' => 60, 'product_id' => 30, 'addon_options' => 'basic', 'exclusive_option' => null, 'exclusive_product_type' => null],
            ['id' => 61, 'product_id' => 31, 'addon_options' => 'exclusive', 'exclusive_option' => 'exc-op-1.50-gallery-wrap', 'exclusive_product_type' => 'exclusive-canvas'],
            ['id' => 62, 'product_id' => 32, 'addon_options' => 'exclusive', 'exclusive_option' => 'exc-op-mirror-effect', 'exclusive_product_type' => 'exclusive-canvas'],
            ['id' => 63, 'product_id' => 33, 'addon_options' => 'advance', 'exclusive_option' => null, 'exclusive_product_type' => null],
        ]);
        DB::table('addon_product')->insert([
            ['id' => 70, 'product_id' => 20, 'addon_id' => 30, 'status' => 1],
            ['id' => 71, 'product_id' => 20, 'addon_id' => 31, 'status' => 1],
            ['id' => 72, 'product_id' => 20, 'addon_id' => 32, 'status' => 1],
            ['id' => 73, 'product_id' => 20, 'addon_id' => 33, 'status' => 1],
            ['id' => 74, 'product_id' => 20, 'addon_id' => 34, 'status' => 1],
        ]);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'name' => 'Canvas setup',
            'shopify_product_type' => 'Wall art',
            'status' => 'active',
            'print_types' => [[
                'collection_id' => 10,
                'product_id' => 20,
                'variant_ids' => [50],
                'addon_ids' => [30, 31, 32, 33, 34],
            ]],
        ];
    }
}
