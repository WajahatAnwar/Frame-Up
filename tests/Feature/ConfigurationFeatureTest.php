<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use App\Services\CatalogConfigurationOptions;
use App\Services\ConfigurationProductInput;
use App\Services\ShopifyConfigurationProductSync;
use App\Services\ShopifyGraphqlGateway;
use App\Services\ShopifyProductCatalog;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use RuntimeException;
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
            ->where('stats.printTypes', 1));

        $this->actingAs($merchant)->get('/configurations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurations/Index')
            ->where('configurations.total', 12)
            ->has('configurations.data', 10));

        $this->actingAs($merchant)->get('/configurations?search=Other')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('configurations.total', 0));
    }

    public function test_configuration_list_filters_print_types_and_sorts_within_the_merchant(): void
    {
        $merchant = User::factory()->create();
        $other = User::factory()->create();
        DB::table('collections')->insert([
            ['id' => 10, 'user_id' => 999, 'title' => 'Canvas'],
            ['id' => 11, 'user_id' => 999, 'title' => 'Metal'],
        ]);

        $canvas = Configuration::create(['user_id' => $merchant->id, 'name' => 'Canvas frame', 'shopify_product_type' => 'Frame', 'status' => 'active']);
        $metal = Configuration::create(['user_id' => $merchant->id, 'name' => 'Metal art', 'shopify_product_type' => 'Art', 'status' => 'draft']);
        $otherConfiguration = Configuration::create(['user_id' => $other->id, 'name' => 'Other shop', 'shopify_product_type' => 'Poster', 'status' => 'active']);
        foreach ([[$canvas, 10], [$metal, 11], [$otherConfiguration, 10]] as [$configuration, $collectionId]) {
            $configuration->printTypes()->create([
                'collection_id' => $collectionId,
                'position' => 0,
                'selected_variant_ids' => [],
                'selected_addon_ids' => [],
            ]);
        }

        $this->actingAs($merchant)->get('/configurations?status=active&print_type=10&search=Frame&sort=product_type')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurations/Index')
                ->where('configurations.total', 1)
                ->where('configurations.data.0.id', $canvas->id)
                ->where('configurations.data.0.print_type_names.0', 'Canvas')
                ->where('filters.print_type', '10')
                ->where('filters.sort', 'product_type')
                ->has('printTypeOptions', 2));

        $this->actingAs($merchant)->get('/configurations?sort=product_type')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.data.0.id', $metal->id)
                ->where('configurations.data.1.id', $canvas->id));

        $this->actingAs($merchant)->get('/configurations?print_type=10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.total', 1)
                ->where('configurations.data.0.id', $canvas->id));

        $this->actingAs($merchant)->get('/configurations?print_type=11')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.total', 1)
                ->where('configurations.data.0.id', $metal->id));

        $this->actingAs($merchant)->get('/configurations?status=draft')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.total', 2));

        $this->actingAs($merchant)->get('/configurations?search=Frame')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.total', 1)
                ->where('configurations.data.0.id', $canvas->id));

        $this->actingAs($merchant)->get('/configurations?sort=oldest')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configurations.data.0.id', $canvas->id)
                ->where('configurations.data.1.id', $metal->id));

        $this->actingAs($merchant)->get('/configurations?print_type=11&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('configurations.total', 1));
    }

    public function test_merchant_can_create_edit_and_delete_a_configuration_using_catalog_relationships(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $this->mock(ShopifyConfigurationProductSync::class)
            ->shouldReceive('sync')->twice()->andReturn(1);

        $this->actingAs($merchant)->get('/configurations/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurations/Form')
            ->where('productTypesUrl', '/shopify/product-types'));

        $this->actingAs($merchant)->post('/configurations', $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Configuration created successfully. Applied to 1 Shopify product.');

        $configuration = Configuration::firstOrFail();
        $this->assertSame($merchant->id, $configuration->user_id);
        $this->assertSame('active', $configuration->status);
        $this->assertSame('Canvas configuration', $configuration->name);
        $this->assertSame([50], $configuration->printTypes->first()->selected_variant_ids);
        $this->assertSame([30, 31, 32, 33, 34], $configuration->printTypes->first()->selected_addon_ids);

        $this->actingAs($merchant)->get("/configurations/{$configuration->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurations/Form')
            ->where('mode', 'show')
            ->where('flash.success', 'Configuration created successfully. Applied to 1 Shopify product.')
            ->where('configuration.id', $configuration->id)
            ->where('catalog.0.surfaces.0.addons.2.group', 'Wrap style')
            ->where('catalog.0.surfaces.0.addons.3.category', 'advance')
            ->where('catalog.0.surfaces.0.addons.4.category', 'other')
            ->has('catalog', 1));

        $this->actingAs($merchant)->get('/configurations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('configurations.data.0.print_type_names.0', 'Canvas'));

        $payload['name'] = 'Updated canvas configuration';
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Configuration updated successfully. Applied to 1 Shopify product.');
        $this->actingAs($merchant)->get("/configurations/{$configuration->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Configuration updated successfully. Applied to 1 Shopify product.'));
        $this->assertSame('Updated canvas configuration', $configuration->fresh()->name);
        $this->assertSame(1, $configuration->printTypes()->count());

        $this->actingAs($merchant)->delete("/configurations/{$configuration->id}")->assertRedirect();
        $this->assertDatabaseMissing('configurations', ['id' => $configuration->id]);
    }

    public function test_configuration_image_uploads_are_no_longer_accepted(): void
    {
        Storage::fake('public');
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $this->mock(ShopifyConfigurationProductSync::class)->shouldReceive('sync')->never();
        $payload = $this->validPayload();
        $payload['image'] = UploadedFile::fake()->image('image.jpg');

        $this->actingAs($merchant)->post('/configurations', $payload)->assertSessionHasErrors('image');
        $this->assertDatabaseCount('configurations', 0);

        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Wall art', 'shopify_product_type' => 'Wall art', 'status' => 'draft',
        ]);
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)->assertSessionHasErrors('image');
        $this->assertSame('draft', $configuration->fresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_configuration_details_do_not_expose_legacy_images(): void
    {
        $merchant = User::factory()->create();
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Wall art', 'shopify_product_type' => 'Wall art', 'status' => 'draft',
        ]);
        $configuration->forceFill([
            'image_path' => 'configuration-images/legacy.jpg',
            'shopify_image_id' => 'gid://shopify/MediaImage/10',
            'shopify_synced_image_path' => 'configuration-images/legacy.jpg',
        ])->save();

        foreach (["/configurations/{$configuration->id}", "/configurations/{$configuration->id}/edit"] as $url) {
            $this->actingAs($merchant)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->missing('configuration.image_path')
                ->missing('configuration.image_url')
                ->missing('configuration.shopify_image_id')
                ->missing('configuration.shopify_synced_image_path'));
        }
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

    public function test_exclusive_addon_groups_reject_multiple_options_on_create_update_and_preview(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        DB::table('products')->insert([
            ['id' => 35, 'title' => 'Alternative wrap thickness', 'addons_check' => 1],
            ['id' => 36, 'title' => 'Alternative wrap style', 'addons_check' => 1],
        ]);
        DB::table('product_settings')->insert([
            ['id' => 65, 'product_id' => 35, 'addon_options' => 'exclusive', 'exclusive_option' => 'exc-op-0.75-gallery-wrap', 'exclusive_product_type' => 'exclusive-canvas'],
            ['id' => 66, 'product_id' => 36, 'addon_options' => 'exclusive', 'exclusive_option' => 'exc-op-border-color', 'exclusive_product_type' => 'exclusive-canvas'],
        ]);
        DB::table('addon_product')->insert([
            ['id' => 75, 'product_id' => 20, 'addon_id' => 35, 'status' => 1],
            ['id' => 76, 'product_id' => 20, 'addon_id' => 36, 'status' => 1],
        ]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $this->mock(ShopifyConfigurationProductSync::class)->shouldNotReceive('sync');

        foreach ([35, 36] as $additionalAddon) {
            $payload = $this->validPayload();
            $payload['print_types'][0]['addon_ids'][] = $additionalAddon;
            $this->actingAs($merchant)->post('/configurations', [...$payload, 'shopify_product_type' => 'Other art'])
                ->assertSessionHasErrors('print_types.0.addon_ids');
            $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)
                ->assertSessionHasErrors('print_types.0.addon_ids');
            $this->actingAs($merchant)->postJson(route('configurations.price-preview'), $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('print_types.0.addon_ids');
        }

        $this->assertDatabaseCount('configurations', 1);
        $this->assertSame('Canvas setup', $configuration->fresh()->name);
        $this->assertSame(0, $configuration->printTypes()->count());
    }

    public function test_required_print_choices_are_enforced_on_create_and_update(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $this->mock(ShopifyConfigurationProductSync::class)->shouldNotReceive('sync');
        foreach ([
            ['collection_id', null, 'print_types.0.collection_id'],
            ['product_id', null, 'print_types.0.product_id'],
            ['variant_ids', [], 'print_types.0.variant_ids'],
            ['addon_ids', [31, 32], 'print_types.0.addon_ids'],
        ] as [$field, $value, $error]) {
            $payload = $this->validPayload();
            $payload['print_types'][0][$field] = $value;
            $this->actingAs($merchant)->post('/configurations', [...$payload, 'shopify_product_type' => 'Other art'])->assertSessionHasErrors($error);
            $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)->assertSessionHasErrors($error);
        }
        $payload = [...$this->validPayload(), 'print_types' => []];
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)->assertSessionHasErrors('print_types');
        $this->assertSame('Canvas setup', $configuration->fresh()->name);
        $this->assertSame(0, $configuration->printTypes()->count());
    }

    public function test_exclusive_options_default_to_first_and_basic_addons_are_optional(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $payload['print_types'][0]['addon_ids'] = [33];
        $this->mock(ShopifyConfigurationProductSync::class)->shouldReceive('sync')->twice()->andReturn(1);
        $this->actingAs($merchant)->post('/configurations', $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertSame([33, 31, 32], $configuration->printTypes()->first()->selected_addon_ids);
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame([33, 31, 32], $configuration->printTypes()->first()->selected_addon_ids);
    }

    public function test_duplicate_selection_ids_are_normalized_before_save(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $payload['print_types'][0]['variant_ids'] = [50, '50', 50];
        $payload['print_types'][0]['addon_ids'] = [30, '30', 30, 31, 32, 33, 34];
        $this->mock(ShopifyConfigurationProductSync::class)
            ->shouldReceive('sync')->once()->andReturn(0);

        $this->actingAs($merchant)->post('/configurations', $payload)->assertRedirect();

        $printType = Configuration::firstOrFail()->printTypes->first();
        $this->assertSame([50], $printType->selected_variant_ids);
        $this->assertSame([30, 31, 32, 33, 34], $printType->selected_addon_ids);
    }

    public function test_a_surface_cannot_be_used_by_multiple_print_types(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $payload['print_types'][] = $payload['print_types'][0];
        $this->mock(ShopifyConfigurationProductSync::class)->shouldNotReceive('sync');

        $this->actingAs($merchant)->post('/configurations', $payload)
            ->assertSessionHasErrors(['print_types.1.product_id']);

        $this->assertDatabaseCount('configurations', 0);
    }

    public function test_configuration_rejects_more_than_2000_variants_before_updating(): void
    {
        $merchant = User::factory()->create();
        DB::table('collections')->insert(['id' => 10, 'user_id' => 999, 'title' => 'Canvas']);
        DB::table('products')->insert(['id' => 20, 'title' => 'Canvas surface', 'addons_check' => 1]);
        $sizes = array_map(fn (int $id) => [
            'id' => $id, 'title' => "{$id}x1", 'width' => $id, 'height' => 1, 'price' => 10,
        ], range(1, 2001));
        $this->mock(CatalogConfigurationOptions::class)->shouldReceive('all')->andReturn([[
            'id' => 10, 'title' => 'Canvas', 'surfaces' => [[
                'id' => 20, 'title' => 'Canvas surface', 'variants' => $sizes, 'addons' => [],
            ]],
        ]]);
        $this->mock(ShopifyConfigurationProductSync::class)->shouldReceive('sync')->once()->andReturn(0);
        $payload = [
            'name' => 'Many sizes', 'shopify_product_type' => 'Wall art', 'status' => 'active',
            'print_types' => [[
                'collection_id' => 10, 'product_id' => 20,
                'variant_ids' => range(1, 2000), 'addon_ids' => [],
            ]],
        ];

        $payload['print_types'][0]['variant_ids'][] = 2001;
        $this->actingAs($merchant)->post('/configurations', $payload)->assertSessionHasErrors('print_types');
        $this->assertDatabaseCount('configurations', 0);

        array_pop($payload['print_types'][0]['variant_ids']);
        $this->actingAs($merchant)->post('/configurations', $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', 'Configuration created successfully. No Shopify products currently match this product type.');
        $configuration = Configuration::firstOrFail();
        $this->assertCount(2000, app(ConfigurationProductInput::class)->build($configuration->load('printTypes'))['variants']);

        $payload['print_types'][0]['variant_ids'][] = 2001;
        $this->actingAs($merchant)->put("/configurations/{$configuration->id}", $payload)
            ->assertSessionHasErrors(['print_types']);

        $this->assertCount(2000, $configuration->fresh()->printTypes->first()->selected_variant_ids);
    }

    public function test_a_shop_cannot_have_two_configurations_targeting_the_same_product_type(): void
    {
        $merchant = User::factory()->create();
        $other = User::factory()->create();
        Configuration::create([
            'user_id' => $merchant->id,
            'name' => 'Frame',
            'shopify_product_type' => 'Frame',
            'status' => 'draft',
        ]);
        $this->seedCatalog();
        $payload = [...$this->validPayload(), 'shopify_product_type' => 'Frame'];

        $this->actingAs($merchant)->post('/configurations', ['status' => 'draft', 'print_types' => []])
            ->assertSessionHasErrors('shopify_product_type');
        $this->actingAs($merchant)->post('/configurations', $payload)
            ->assertSessionHasErrors('shopify_product_type');
        $this->mock(ShopifyConfigurationProductSync::class)->shouldReceive('sync')->once()->andReturn(0);
        $this->actingAs($other)->post('/configurations', $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('configurations', 2);
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

    public function test_shopify_product_input_prices_each_size_and_mount_with_selected_basic_and_exclusive_addons(): void
    {
        $merchant = User::factory()->create();
        $merchant->price_multiplier = 1;
        $merchant->save();
        $this->seedCatalog();
        DB::table('products')->insert(['id' => 35, 'title' => 'Box White', 'addons_check' => 1]);
        DB::table('product_settings')->insert(['id' => 64, 'product_id' => 35, 'addon_options' => 'advance']);
        DB::table('addon_product')->insert(['id' => 75, 'product_id' => 20, 'addon_id' => 35, 'status' => 1]);
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        DB::table('product_varients')->insert([
            ['id' => 52, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 20],
            ['id' => 81, 'product_id' => 31, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 6, 'height' => 4, 'price' => 3],
            ['id' => 82, 'product_id' => 31, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 4],
            ['id' => 83, 'product_id' => 32, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 4, 'height' => 6, 'price' => 1],
            ['id' => 84, 'product_id' => 32, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 2],
            ['id' => 85, 'product_id' => 33, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 4, 'height' => 6, 'price' => 5],
            ['id' => 86, 'product_id' => 33, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 7],
            ['id' => 87, 'product_id' => 35, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 4, 'height' => 6, 'price' => 6],
            ['id' => 88, 'product_id' => 35, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 8],
        ]);
        DB::table('product_varients')->insert([
            'id' => 80, 'product_id' => 30, 'variant_type' => 'custom', 'is_active' => 1,
            'min_width' => 1, 'max_width' => 20, 'min_height' => 1, 'max_height' => 20, 'price_per_sq_inch' => 0.1,
        ]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50, 52], 'selected_addon_ids' => [30, 31, 32, 33, 34, 35],
        ]);

        $input = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));

        $this->assertSame(['Print Type', 'Sizes', 'Mounts and Frames'], array_column($input['productOptions'], 'name'));
        $this->assertSame('Canvas - Giclée Canvas', $input['productOptions'][0]['values'][0]['name']);
        $this->assertSame(['4x6', '8x8'], array_column($input['productOptions'][1]['values'], 'name'));
        $this->assertSame(['Floating frame', 'Box White'], array_column($input['productOptions'][2]['values'], 'name'));
        $this->assertSame(['21.40', '22.40', '39.40', '40.40'], array_column($input['variants'], 'price'));
        $this->assertArrayNotHasKey('status', $input);
        $this->assertArrayNotHasKey('title', $input);

        DB::table('product_settings')->where('product_id', 30)->update(['custom_price_type' => 'linear_inches', 'is_negative' => 1]);
        $discounted = app(ConfigurationProductInput::class)->build($configuration);
        $this->assertSame(['17.00', '18.00', '29.80', '30.80'], array_column($discounted['variants'], 'price'));
    }

    public function test_flat_addon_prices_apply_only_when_collection_size_ranges_match(): void
    {
        $merchant = User::factory()->create();
        $merchant->price_multiplier = 1;
        $merchant->save();
        $this->seedCatalog();
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        DB::table('product_varients')->insert([
            ['id' => 52, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 20],
            ['id' => 80, 'product_id' => 30, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 75],
            ['id' => 81, 'product_id' => 31, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 5],
        ]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $printType = $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50, 52], 'selected_addon_ids' => [30],
        ]);

        $unrestricted = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['85.00', '95.00'], array_column($unrestricted['variants'], 'price'));

        DB::table('addons_collections_sizes')->insert([
            ['product_id' => 30, 'collection_id' => 10, 'min_width' => 8, 'max_width' => 20, 'min_height' => 8, 'max_height' => 20],
            ['product_id' => 31, 'collection_id' => 10, 'min_width' => 8, 'max_width' => 20, 'min_height' => 8, 'max_height' => 20],
            ['product_id' => 32, 'collection_id' => 10, 'min_width' => 12, 'max_width' => 20, 'min_height' => 12, 'max_height' => 20],
        ]);
        $printType->update(['selected_addon_ids' => [30, 31, 32]]);

        $restricted = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['10.00', '100.00'], array_column($restricted['variants'], 'price'));

        DB::table('addons_collections_sizes')->where('product_id', 31)->update([
            'min_width' => 6, 'max_width' => 6, 'min_height' => 4, 'max_height' => 4,
        ]);
        $printType->update(['selected_addon_ids' => [31]]);
        $rotated = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['15.00', '20.00'], array_column($rotated['variants'], 'price'));

        DB::table('addons_collections_sizes')->where('product_id', 32)->update([
            'min_width' => 8, 'max_width' => 8, 'min_height' => 8, 'max_height' => 8,
        ]);
        $printType->update(['selected_addon_ids' => [32]]);
        $withoutUnpricedAddon = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['10.00', '20.00'], array_column($withoutUnpricedAddon['variants'], 'price'));
        $this->assertSame(['4x6', '8x8'], array_column($withoutUnpricedAddon['productOptions'][1]['values'], 'name'));
    }

    public function test_every_selected_surface_size_and_priced_mount_combination_is_built(): void
    {
        $merchant = User::factory()->create();
        $merchant->price_multiplier = 1;
        $merchant->save();
        $this->seedCatalog();
        DB::table('collection_product')->insert(['id' => 41, 'collection_id' => 10, 'product_id' => 21]);
        DB::table('product_settings')->insert(['id' => 64, 'product_id' => 34, 'addon_options' => 'advance']);
        DB::table('addon_product')->insert([
            ['id' => 75, 'product_id' => 21, 'addon_id' => 30, 'status' => 1],
            ['id' => 76, 'product_id' => 21, 'addon_id' => 33, 'status' => 1],
            ['id' => 77, 'product_id' => 21, 'addon_id' => 34, 'status' => 1],
        ]);
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        DB::table('product_varients')->where('id', 51)->update(['width' => 4, 'height' => 6, 'price' => 12]);
        DB::table('product_varients')->insert([
            ['id' => 52, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 20],
            ['id' => 53, 'product_id' => 21, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 22],
            ['id' => 80, 'product_id' => 30, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 2],
            ['id' => 81, 'product_id' => 33, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 3],
            ['id' => 82, 'product_id' => 34, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => null, 'height' => null, 'price' => 4],
        ]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Two surfaces', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        foreach ([[20, [50, 52]], [21, [51, 53]]] as $position => [$productId, $sizeIds]) {
            $configuration->printTypes()->create([
                'collection_id' => 10, 'product_id' => $productId, 'position' => $position,
                'selected_variant_ids' => $sizeIds, 'selected_addon_ids' => [30, 33, 34],
            ]);
        }

        $input = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));

        $this->assertCount(8, $input['variants']);
        $this->assertSame(['15.00', '16.00', '25.00', '26.00', '17.00', '18.00', '27.00', '28.00'], array_column($input['variants'], 'price'));
        $this->assertSame(['Canvas - Giclée Canvas', 'Canvas - Other surface'], array_column($input['productOptions'][0]['values'], 'name'));
        $this->assertSame(['4x6', '8x8'], array_column($input['productOptions'][1]['values'], 'name'));
        $this->assertSame(['Floating frame', 'Mount'], array_column($input['productOptions'][2]['values'], 'name'));
    }

    public function test_mount_options_only_create_variants_for_eligible_sizes(): void
    {
        $merchant = User::factory()->create();
        $merchant->price_multiplier = 1;
        $merchant->save();
        $this->seedCatalog();
        DB::table('product_settings')->insert(['id' => 64, 'product_id' => 34, 'addon_options' => 'advance']);
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        DB::table('product_varients')->insert([
            ['id' => 52, 'product_id' => 20, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 20],
            ['id' => 80, 'product_id' => 33, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 4, 'height' => 6, 'price' => 3],
            ['id' => 81, 'product_id' => 34, 'variant_type' => 'predefined', 'is_active' => 1, 'width' => 8, 'height' => 8, 'price' => 6],
        ]);
        DB::table('addons_collections_sizes')->insert([
            ['product_id' => 33, 'collection_id' => 10, 'min_width' => 4, 'max_width' => 6, 'min_height' => 4, 'max_height' => 6],
            ['product_id' => 34, 'collection_id' => 10, 'min_width' => 8, 'max_width' => 20, 'min_height' => 8, 'max_height' => 20],
        ]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $printType = $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50, 52], 'selected_addon_ids' => [33, 34],
        ]);

        $input = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['13.00', '26.00'], array_column($input['variants'], 'price'));
        $this->assertSame(['Floating frame', 'Mount'], array_column($input['productOptions'][2]['values'], 'name'));

        $printType->update(['selected_addon_ids' => [34]]);
        $withFallback = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['10.00', '26.00'], array_column($withFallback['variants'], 'price'));
        $this->assertSame(['None', 'Mount'], array_column($withFallback['productOptions'][2]['values'], 'name'));

        DB::table('product_varients')->where('id', 81)->delete();
        $withoutUnpricedMount = app(ConfigurationProductInput::class)->build($configuration->load('printTypes'));
        $this->assertSame(['10.00', '20.00'], array_column($withoutUnpricedMount['variants'], 'price'));
        $this->assertSame(['4x6', '8x8'], array_column($withoutUnpricedMount['productOptions'][1]['values'], 'name'));
        $this->assertSame(['None'], array_column($withoutUnpricedMount['productOptions'][2]['values'], 'name'));
    }

    public function test_saving_a_complete_configuration_upserts_and_links_the_shopify_product(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Canvas setup', 'shopify_product_type' => 'Wall art', 'status' => 'active',
        ]);
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50], 'selected_addon_ids' => [],
        ]);
        $this->mock(ShopifyProductCatalog::class)
            ->shouldReceive('productsOfType')->twice()
            ->andReturnUsing(fn () => (function () {
                yield ['id' => 'gid://shopify/Product/123', 'title' => 'Existing canvas', 'productType' => 'Wall art'];
            })());
        $productInput = [
            'title' => 'Canvas setup', 'productType' => 'Wall art', 'handle' => 'frame-up-configuration-'.$configuration->id,
            'status' => 'ACTIVE',
            'productOptions' => [
                ['name' => 'Print Type', 'values' => [['name' => 'Canvas - Giclée Canvas']]],
                ['name' => 'Sizes', 'values' => [['name' => '4x6']]],
                ['name' => 'Mounts and Frames', 'values' => [['name' => 'None']]],
            ],
            'variants' => [[
                'optionValues' => [
                    ['optionName' => 'Print Type', 'name' => 'Canvas - Giclée Canvas'],
                    ['optionName' => 'Sizes', 'name' => '4x6'],
                    ['optionName' => 'Mounts and Frames', 'name' => 'None'],
                ],
                'price' => '10.00', 'sku' => 'frameup-test', 'inventoryPolicy' => 'CONTINUE',
            ]],
        ];
        $updatedInput = $productInput;
        $updatedInput['productOptions'][1]['values'][] = ['name' => '5x7'];
        $updatedInput['variants'][0]['price'] = '12.00';
        $newVariant = $productInput['variants'][0];
        $newVariant['optionValues'][1]['name'] = '5x7';
        $newVariant['price'] = '15.00';
        $updatedInput['variants'][] = $newVariant;
        $this->mock(ConfigurationProductInput::class)->shouldReceive('build')->twice()->andReturn($productInput, $updatedInput);
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($shop, $query, $variables) => $shop->is($merchant) && str_contains($query, 'query ConfigurationProduct(') && $variables['id'] === 'gid://shopify/Product/123')
            ->andReturn(['product' => [
                'id' => 'gid://shopify/Product/123', 'options' => [],
                'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
                'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ]]);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($shop, $query, $variables) => str_contains($query, 'productSet')
                && $variables['identifier']['id'] === 'gid://shopify/Product/123'
                && array_column($variables['input']['productOptions'], 'name') === ['Print Type', 'Sizes', 'Mounts and Frames']
                && $variables['input']['variants'][0]['price'] === '10.00'
                && ! isset($variables['input']['title'], $variables['input']['handle'], $variables['input']['status'], $variables['input']['productType'])
                && ! array_key_exists('files', $variables['input']))
            ->andReturn(['productSet' => ['product' => [
                'id' => 'gid://shopify/Product/123',
                'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/10']]],
            ], 'userErrors' => []]]);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($shop, $query, $variables) => str_contains($query, 'query ConfigurationProduct(')
                && $variables['id'] === 'gid://shopify/Product/123')
            ->andReturn(['product' => [
                'id' => 'gid://shopify/Product/123',
                'options' => [
                    ['id' => 'gid://shopify/ProductOption/1', 'name' => 'Print Type', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/1', 'name' => 'Canvas - Giclée Canvas']]],
                    ['id' => 'gid://shopify/ProductOption/2', 'name' => 'Sizes', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/2', 'name' => '4x6']]],
                    ['id' => 'gid://shopify/ProductOption/3', 'name' => 'Mounts and Frames', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/3', 'name' => 'None']]],
                ],
                'media' => [
                    'nodes' => [['id' => 'gid://shopify/MediaImage/10'], ['id' => 'gid://shopify/MediaImage/11']],
                    'pageInfo' => ['hasNextPage' => false],
                ],
                'variants' => [
                    'nodes' => [
                        ['id' => 'gid://shopify/ProductVariant/5', 'selectedOptions' => [
                            ['name' => 'Print Type', 'value' => 'Canvas - Giclée Canvas'],
                            ['name' => 'Sizes', 'value' => '4x6'],
                            ['name' => 'Mounts and Frames', 'value' => 'None'],
                        ]],
                        ['id' => 'gid://shopify/ProductVariant/6', 'selectedOptions' => [
                            ['name' => 'Print Type', 'value' => 'Canvas - Giclée Canvas'],
                            ['name' => 'Sizes', 'value' => '8x8'],
                            ['name' => 'Mounts and Frames', 'value' => 'None'],
                        ]],
                    ],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ],
            ]]);
        $gateway->shouldReceive('query')->once()
            ->withArgs(fn ($shop, $query, $variables) => str_contains($query, 'productSet')
                && $variables['identifier']['id'] === 'gid://shopify/Product/123'
                && $variables['input']['productOptions'][0]['id'] === 'gid://shopify/ProductOption/1'
                && count($variables['input']['variants']) === 2
                && $variables['input']['variants'][0]['id'] === 'gid://shopify/ProductVariant/5'
                && $variables['input']['variants'][0]['price'] === '12.00'
                && ! array_key_exists('sku', $variables['input']['variants'][0])
                && ! array_key_exists('inventoryPolicy', $variables['input']['variants'][0])
                && ! array_key_exists('id', $variables['input']['variants'][1])
                && $variables['input']['variants'][1]['optionValues'][1]['name'] === '5x7'
                && $variables['input']['variants'][1]['sku'] === 'frameup-test'
                && ! array_key_exists('files', $variables['input']))
            ->andReturn(['productSet' => ['product' => [
                'id' => 'gid://shopify/Product/123',
                'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/10']]],
            ], 'userErrors' => []]]);

        $result = app(ShopifyConfigurationProductSync::class)->sync($configuration);

        $this->assertSame(1, $result);
        $this->assertNull($configuration->fresh()->shopify_product_id);
        $this->assertDatabaseHas('configuration_shopify_products', [
            'configuration_id' => $configuration->id,
            'shopify_product_id' => 'gid://shopify/Product/123',
        ]);
        $this->assertSame(1, app(ShopifyConfigurationProductSync::class)->sync($configuration->fresh()));
    }

    public function test_active_configuration_updates_every_product_of_the_selected_type_without_renaming_them(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Frame', 'shopify_product_type' => 'Frame', 'status' => 'active',
        ]);
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50], 'selected_addon_ids' => [],
        ]);
        $this->mock(ShopifyProductCatalog::class)
            ->shouldReceive('productsOfType')->once()
            ->andReturnUsing(fn () => (function () {
                yield ['id' => 'gid://shopify/Product/1', 'title' => 'Wood frame', 'productType' => 'Frame'];
                yield ['id' => 'gid://shopify/Product/2', 'title' => 'Metal frame', 'productType' => 'Frame'];
            })());

        $mutations = [];
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->times(4)->andReturnUsing(function ($shop, $query, $variables) use ($merchant, &$mutations): array {
            $this->assertTrue($shop->is($merchant));
            if (str_contains($query, 'query ConfigurationProduct(')) {
                $productId = $variables['id'];

                return ['product' => [
                    'id' => $productId,
                    'options' => $productId === 'gid://shopify/Product/1' ? [
                        ['id' => 'gid://shopify/ProductOption/1', 'name' => 'Print Type', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/1', 'name' => 'Canvas - Giclée Canvas']]],
                        ['id' => 'gid://shopify/ProductOption/2', 'name' => 'Sizes', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/2', 'name' => '4x6']]],
                        ['id' => 'gid://shopify/ProductOption/3', 'name' => 'Mounts and Frames', 'optionValues' => [['id' => 'gid://shopify/ProductOptionValue/3', 'name' => 'None']]],
                    ] : [],
                    'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
                    'variants' => [
                        'nodes' => $productId === 'gid://shopify/Product/1' ? [[
                            'id' => 'gid://shopify/ProductVariant/5',
                            'selectedOptions' => [
                                ['name' => 'Print Type', 'value' => 'Canvas - Giclée Canvas'],
                                ['name' => 'Sizes', 'value' => '4x6'],
                                ['name' => 'Mounts and Frames', 'value' => 'None'],
                            ],
                        ]] : [],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ]];
            }

            $this->assertStringContainsString('productSet', $query);
            $mutations[] = $variables;

            return ['productSet' => ['product' => [
                'id' => $variables['identifier']['id'], 'media' => ['nodes' => []],
            ], 'userErrors' => []]];
        });

        $this->assertSame(2, app(ShopifyConfigurationProductSync::class)->sync($configuration));
        $this->assertSame(['gid://shopify/Product/1', 'gid://shopify/Product/2'], array_column(array_column($mutations, 'identifier'), 'id'));
        $this->assertSame('gid://shopify/ProductVariant/5', $mutations[0]['input']['variants'][0]['id']);
        $this->assertArrayNotHasKey('id', $mutations[1]['input']['variants'][0]);
        $this->assertSame('20.00', $mutations[0]['input']['variants'][0]['price']);
        $this->assertSame('20.00', $mutations[1]['input']['variants'][0]['price']);
        foreach ($mutations as $mutation) {
            $this->assertSame([], array_intersect(['title', 'handle', 'status', 'productType'], array_keys($mutation['input'])));
        }
        $this->assertSame(2, $configuration->shopifyProducts()->count());
    }

    public function test_changing_the_target_type_leaves_previously_updated_products_as_they_are(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        DB::table('product_varients')->where('id', 50)->update(['width' => 4, 'height' => 6, 'price' => 10]);
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Frame', 'shopify_product_type' => 'Frame', 'status' => 'active',
        ]);
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50], 'selected_addon_ids' => [],
        ]);
        $this->mock(ShopifyProductCatalog::class)
            ->shouldReceive('productsOfType')->twice()
            ->andReturnUsing(fn ($shop, $type) => (function () use ($type) {
                yield [
                    'id' => $type === 'Frame' ? 'gid://shopify/Product/1' : 'gid://shopify/Product/2',
                    'title' => $type.' product',
                    'productType' => $type,
                ];
            })());
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->times(4)->andReturnUsing(function ($shop, $query, $variables): array {
            $productId = $variables['id'] ?? $variables['identifier']['id'];
            if (str_contains($query, 'query ConfigurationProduct(')) {
                return ['product' => [
                    'id' => $productId, 'options' => [],
                    'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
                    'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                ]];
            }

            return ['productSet' => ['product' => ['id' => $productId, 'media' => ['nodes' => []]], 'userErrors' => []]];
        });

        $this->assertSame(1, app(ShopifyConfigurationProductSync::class)->sync($configuration));
        $configuration->update(['name' => 'Canvas', 'shopify_product_type' => 'Canvas']);
        $this->assertSame(1, app(ShopifyConfigurationProductSync::class)->sync($configuration->fresh()));

        $this->assertSame(
            ['gid://shopify/Product/1', 'gid://shopify/Product/2'],
            $configuration->shopifyProducts()->orderBy('id')->pluck('shopify_product_id')->all(),
        );
    }

    public function test_graphql_gateway_unwraps_data_and_reports_expired_shop_access(): void
    {
        $shop = \Mockery::mock(User::class)->makePartial();
        $api = \Mockery::mock(BasicShopifyAPI::class);
        $shop->shouldReceive('api')->twice()->andReturn($api);
        $api->shouldReceive('graph')->once()->andReturn([
            'status' => 200, 'errors' => false,
            'body' => new ResponseAccess(['data' => ['productByIdentifier' => ['id' => 'gid://shopify/Product/123']]]),
        ]);
        $api->shouldReceive('graph')->once()->andReturn([
            'status' => 401, 'errors' => true,
            'body' => '[API] Invalid API key or access token',
        ]);

        $gateway = app(ShopifyGraphqlGateway::class);
        $this->assertSame(['productByIdentifier' => ['id' => 'gid://shopify/Product/123']], $gateway->query($shop, 'query Test { shop { id } }', []));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');
        $gateway->query($shop, 'query Test { shop { id } }', []);
    }

    public function test_legacy_configuration_images_are_not_sent_or_reordered_on_shopify(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $configuration = Configuration::create([
            'user_id' => $merchant->id,
            'name' => 'Canvas setup',
            'shopify_product_type' => 'Wall art',
            'status' => 'active',
        ]);
        $configuration->forceFill(['image_path' => 'configuration-images/new.jpg'])->save();
        $target = $configuration->shopifyProducts()->create(['shopify_product_id' => 'gid://shopify/Product/123']);
        $target->forceFill([
            'shopify_image_id' => 'gid://shopify/MediaImage/old',
            'shopify_synced_image_path' => 'configuration-images/old.jpg',
        ])->save();
        $this->mock(ShopifyProductCatalog::class)
            ->shouldReceive('productsOfType')->twice()
            ->andReturnUsing(fn () => (function () {
                yield ['id' => 'gid://shopify/Product/123', 'title' => 'Existing canvas', 'productType' => 'Wall art'];
            })());
        $configuration->printTypes()->create([
            'collection_id' => 10, 'product_id' => 20, 'position' => 0,
            'selected_variant_ids' => [50], 'selected_addon_ids' => [],
        ]);
        $this->mock(ConfigurationProductInput::class)->shouldReceive('build')->twice()->andReturn([
            'title' => 'Canvas setup', 'productType' => 'Wall art', 'handle' => 'frame-up-configuration-'.$configuration->id,
            'status' => 'ACTIVE',
            'productOptions' => [
                ['name' => 'Print Type', 'values' => [['name' => 'Canvas']]],
                ['name' => 'Sizes', 'values' => [['name' => '4x6']]],
                ['name' => 'Mounts and Frames', 'values' => [['name' => 'None']]],
            ],
            'variants' => [[
                'optionValues' => [
                    ['optionName' => 'Print Type', 'name' => 'Canvas'],
                    ['optionName' => 'Sizes', 'name' => '4x6'],
                    ['optionName' => 'Mounts and Frames', 'name' => 'None'],
                ],
                'price' => '10.00', 'sku' => 'frameup-test', 'inventoryPolicy' => 'CONTINUE',
            ]],
        ]);
        $product = [
            'id' => 'gid://shopify/Product/123',
            'options' => [],
            'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'media' => [
                'nodes' => [['id' => 'gid://shopify/MediaImage/old'], ['id' => 'gid://shopify/MediaImage/other']],
                'pageInfo' => ['hasNextPage' => false],
            ],
        ];
        $gateway = $this->mock(ShopifyGraphqlGateway::class);
        $gateway->shouldReceive('query')->twice()
            ->withArgs(fn ($shop, $query) => $shop->is($merchant)
                && str_contains($query, 'query ConfigurationProduct(')
                && ! str_contains($query, 'media('))
            ->andReturn(['product' => $product]);
        $gateway->shouldReceive('query')->twice()
            ->withArgs(fn ($shop, $query, $variables) => $shop->is($merchant)
                && str_contains($query, 'productSet')
                && ! str_contains($query, 'media(')
                && array_keys($variables['input']) === ['productOptions', 'variants'])
            ->andReturn(['productSet' => ['product' => ['id' => 'gid://shopify/Product/123'], 'userErrors' => []]]);

        $sync = app(ShopifyConfigurationProductSync::class);
        $this->assertSame(1, $sync->sync($configuration));
        $this->assertSame(1, $sync->sync($configuration->fresh()));
        $this->assertSame('gid://shopify/MediaImage/old', $target->fresh()->shopify_image_id);
        $this->assertSame('configuration-images/old.jpg', $target->fresh()->shopify_synced_image_path);
    }

    public function test_shopify_failure_keeps_the_saved_configuration_editable(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $this->mock(ShopifyConfigurationProductSync::class)
            ->shouldReceive('sync')->once()->andThrow(new RuntimeException('A selected add-on has no price.'));

        $this->actingAs($merchant)->post('/configurations', $this->validPayload())
            ->assertRedirect('/configurations/1/edit')
            ->assertSessionHasErrors('shopify')
            ->assertSessionMissing('success');

        $this->assertDatabaseHas('configurations', ['id' => 1, 'name' => 'Canvas configuration', 'shopify_product_id' => null]);
    }

    public function test_incomplete_draft_does_not_create_an_unusable_shopify_product(): void
    {
        $merchant = User::factory()->create();
        $configuration = Configuration::create([
            'user_id' => $merchant->id, 'name' => 'Work in progress', 'shopify_product_type' => 'Wall art', 'status' => 'draft',
        ]);
        $this->mock(ShopifyGraphqlGateway::class)->shouldNotReceive('query');
        $this->mock(ConfigurationProductInput::class)->shouldNotReceive('build');

        $this->assertSame(0, app(ShopifyConfigurationProductSync::class)->sync($configuration));
        $this->assertNull($configuration->fresh()->shopify_product_id);
    }

    public function test_configuration_name_is_required_and_saves_ignore_legacy_draft_status(): void
    {
        $merchant = User::factory()->create();
        $this->seedCatalog();
        $payload = $this->validPayload();
        $payload['name'] = '';
        $this->actingAs($merchant)->post('/configurations', $payload)->assertSessionHasErrors('name');
        $this->assertDatabaseCount('configurations', 0);

        $payload['name'] = 'Custom canvas';
        $payload['status'] = 'draft';
        $this->mock(ShopifyConfigurationProductSync::class)->shouldReceive('sync')->once()->andReturn(1);
        $this->actingAs($merchant)->post('/configurations', $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('configurations', ['name' => 'Custom canvas', 'status' => 'active']);
        $this->actingAs($merchant)->get('/configurations?search=Custom canvas')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('configurations.total', 1)->where('configurations.data.0.name', 'Custom canvas'));
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
            'name' => 'Canvas configuration',
            'shopify_product_type' => 'Wall art',
            'print_types' => [[
                'collection_id' => 10,
                'product_id' => 20,
                'variant_ids' => [50],
                'addon_ids' => [30, 31, 32, 33, 34],
            ]],
        ];
    }
}
