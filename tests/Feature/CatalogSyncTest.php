<?php

namespace Tests\Feature;

use App\Jobs\PullCatalogFrom3dFrames;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_extra_setting_columns_can_be_removed_without_losing_settings(): void
    {
        Schema::table('product_settings', function (Blueprint $table): void {
            $table->string('surface_type')->nullable();
            $table->string('whitePaperPrice')->nullable();
            $table->string('metallicPaperPrice')->nullable();
            $table->boolean('paperSelection')->nullable();
        });

        DB::table('products')->insert(['id' => 21, 'title' => 'Canvas print']);
        DB::table('product_settings')->insert(['id' => 31, 'product_id' => 21, 'canvas_type' => 'gallery']);

        $migration = require database_path('migrations/2026_10_05_000004_remove_non_source_product_setting_columns.php');
        $migration->up();

        foreach (['surface_type', 'whitePaperPrice', 'metallicPaperPrice', 'paperSelection', 'white_to_gray_strength'] as $column) {
            $this->assertFalse(Schema::hasColumn('product_settings', $column));
        }

        $this->assertSame('gallery', DB::table('product_settings')->where('id', 31)->value('canvas_type'));
        $restore = require database_path('migrations/2026_10_08_145728_restore_white_to_gray_strength_to_product_settings.php');
        $restore->up();
        DB::table('product_settings')->where('id', 31)->update(['white_to_gray_strength' => 0.75]);
        $restore->up();
        $this->assertSame('gallery', DB::table('product_settings')->where('id', 31)->value('canvas_type'));
        $this->assertEquals(0.75, DB::table('product_settings')->where('id', 31)->value('white_to_gray_strength'));
    }

    public function test_authenticated_shop_can_queue_a_catalog_pull(): void
    {
        Queue::fake();
        $shop = User::factory()->create(['name' => 'frame-shop.myshopify.com']);

        $this->withoutMiddleware(VerifyShopify::class)
            ->actingAs($shop)
            ->postJson('/catalog/sync')
            ->assertAccepted();

        Queue::assertPushed(PullCatalogFrom3dFrames::class, fn (PullCatalogFrom3dFrames $job): bool => $job->uniqueId() === 'global-catalog');
        $route = app('router')->getRoutes()->getByName('catalog.sync');
        $this->assertContains('verify.shopify', $route->middleware());
    }

    public function test_pull_copies_all_catalog_relationships_and_can_run_again(): void
    {
        config()->set('services.frame_up_source.url', 'https://source.example');
        config()->set('services.frame_up_source.key', 'shared-secret');

        $catalog = [
            'collections' => [['id' => 11, 'title' => 'Canvas', 'header_title' => 'Canvas prints', 'user_id' => 999]],
            'products' => [
                ['id' => 21, 'user_id' => 999, 'title' => 'Canvas print', 'addons_check' => 1, 'pre_configured' => true],
                ['id' => 22, 'user_id' => 999, 'title' => 'Basic mount', 'addons_check' => 1, 'pre_configured' => false],
                ['id' => 23, 'user_id' => 999, 'title' => 'Exclusive border', 'addons_check' => 1, 'pre_configured' => false],
            ],
            'product_settings' => [
                ['id' => 31, 'product_id' => 21, 'canvas_type' => 'gallery', 'thickness' => '1.5', 'wood_mount_type' => 'none', 'white_to_gray_strength' => 0.75],
                ['id' => 32, 'product_id' => 22, 'addon_options' => 'basic', 'basic_option_name' => 'mount', 'addonAdvanceOption' => 'wrap', 'wood_mount_type' => 'none', 'white_to_gray_strength' => 0],
                ['id' => 33, 'product_id' => 23, 'addon_options' => 'exclusive', 'exclusive_option' => 'border', 'wood_mount_type' => 'none', 'white_to_gray_strength' => 0],
            ],
            'product_varients' => [['id' => 41, 'product_id' => 23, 'price' => 12.5]],
            'product_media' => [['id' => 51, 'product_id' => '21', 'src' => 'https://cdn.example/canvas.jpg']],
            'collection_product' => [['id' => 61, 'collection_id' => 11, 'product_id' => 21, 'position' => 1, 'is_default' => true]],
            'addon_product' => [
                ['id' => 71, 'product_id' => 21, 'addon_id' => 22, 'status' => true, 'position' => 2, 'priority' => 1],
                ['id' => 72, 'product_id' => 21, 'addon_id' => 23, 'status' => true, 'position' => 0, 'priority' => null],
            ],
            'addons_collections_sizes' => [['id' => 81, 'collection_id' => 11, 'product_id' => 23, 'addon_id' => 22, 'status' => 1, 'min_width' => 8, 'max_width' => 30]],
        ];

        Http::fake(['source.example/*' => Http::response(['success' => true, 'data' => $catalog])]);

        (new PullCatalogFrom3dFrames)->handle();
        (new PullCatalogFrom3dFrames)->handle();

        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame(2, DB::table('addon_product')->count());
        $this->assertSame(999, DB::table('products')->where('id', 21)->value('user_id'));
        $this->assertSame('Canvas prints', DB::table('collections')->where('id', 11)->value('header_title'));
        $this->assertSame(1, DB::table('products')->where('id', 21)->value('pre_configured'));
        $this->assertSame('gallery', DB::table('product_settings')->where('product_id', 21)->value('canvas_type'));
        $this->assertSame('none', DB::table('product_settings')->where('product_id', 21)->value('wood_mount_type'));
        $this->assertSame('wrap', DB::table('product_settings')->where('product_id', 22)->value('addonAdvanceOption'));
        $this->assertSame('1.5', DB::table('product_settings')->where('product_id', 21)->value('thickness'));
        $this->assertEquals(0.75, DB::table('product_settings')->where('product_id', 21)->value('white_to_gray_strength'));
        $this->assertSame('exclusive', DB::table('product_settings')->where('product_id', 23)->value('addon_options'));
        $this->assertSame(1, DB::table('collection_product')->first()->is_default);
        $this->assertSame(23, DB::table('addon_product')->where('id', 72)->value('addon_id'));
        $this->assertSame(2, DB::table('addon_product')->where('id', 71)->value('position'));
        $this->assertSame(1, DB::table('addon_product')->where('id', 71)->value('priority'));
        $this->assertSame(22, DB::table('addons_collections_sizes')->where('id', 81)->value('addon_id'));
        $this->assertSame(1, DB::table('addons_collections_sizes')->where('id', 81)->value('status'));
        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-ABS-Timestamp')[0] ?? '';
            $signature = $request->header('X-ABS-Signature')[0] ?? '';
            $uri = '/api/frame-up/catalog';

            return $request->url() === 'https://source.example'.$uri
                && $signature === hash_hmac('sha256', $timestamp.'.'.$uri, 'shared-secret');
        });
    }

    public function test_invalid_catalog_does_not_write_partial_rows(): void
    {
        config()->set('services.frame_up_source.url', 'https://source.example');
        config()->set('services.frame_up_source.key', 'shared-secret');
        Http::fake(['source.example/*' => Http::response(['data' => ['collections' => [['id' => 11, 'title' => 'Incomplete']]]])]);

        try {
            (new PullCatalogFrom3dFrames)->handle();
            $this->fail('Incomplete catalog should be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('missing products', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_unmapped_columns_are_named_and_rejected_before_catalog_writes(): void
    {
        config()->set('services.frame_up_source.url', 'https://source.example');
        config()->set('services.frame_up_source.key', 'shared-secret');
        $catalog = array_fill_keys([
            'collections', 'products', 'product_settings', 'product_varients', 'product_media',
            'collection_product', 'addon_product', 'addons_collections_sizes',
        ], []);
        $catalog['products'] = [['id' => 21, 'title' => 'Canvas']];
        $catalog['product_settings'] = [['id' => 31, 'product_id' => 21, 'future_setting' => 'example']];
        Http::fake(['source.example/*' => Http::response(['data' => $catalog])]);

        try {
            (new PullCatalogFrom3dFrames)->handle();
            $this->fail('Unmapped columns should be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('product_settings row at index 0 contains unmapped columns: future_setting', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_global_catalog_keeps_source_owners_without_copies_per_frame_up_user(): void
    {
        User::factory()->create(['name' => 'frame-shop.myshopify.com']);
        User::factory()->create();
        config()->set('services.frame_up_source.url', 'https://source.example');
        config()->set('services.frame_up_source.key', 'shared-secret');
        $catalog = array_fill_keys([
            'collections', 'products', 'product_settings', 'product_varients', 'product_media',
            'collection_product', 'addon_product', 'addons_collections_sizes',
        ], []);
        $catalog['collections'] = [
            ['id' => 11, 'title' => 'First source collection', 'user_id' => 999],
            ['id' => 12, 'title' => 'Second source collection', 'user_id' => 1000],
        ];
        $catalog['products'] = [
            ['id' => 21, 'title' => 'First source product', 'user_id' => 999],
            ['id' => 22, 'title' => 'Second source product', 'user_id' => 1000],
        ];
        $catalog['collection_product'] = [['id' => 61, 'collection_id' => 11, 'product_id' => 21]];
        Http::fake(['source.example/*' => Http::response(['data' => $catalog])]);

        (new PullCatalogFrom3dFrames)->handle();
        (new PullCatalogFrom3dFrames)->handle();

        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(2, DB::table('products')->count());
        $this->assertSame(1, DB::table('collection_product')->count());
        $this->assertSame(1000, DB::table('products')->where('id', 22)->value('user_id'));
    }
}
