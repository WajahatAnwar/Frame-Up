<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('header_title')->nullable();
            $table->string('slug')->nullable();
            $table->string('collection_url')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('shopify_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('shopify_product_id')->nullable();
            $table->integer('order')->default(0);
            $table->text('body_html')->nullable();
            $table->string('handle')->nullable();
            $table->string('product_type')->nullable();
            $table->string('title')->nullable();
            $table->string('vendor')->nullable();
            $table->string('status')->nullable();
            $table->tinyInteger('addons_check')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_app_created')->default(false);
            $table->boolean('pre_configured')->default(false);
            $table->string('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('product_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('material_type')->default('Acrylic');
            $table->string('wood_finish', 20)->default('natural');
            $table->string('wood_mount_type', 40)->default('none');
            $table->string('cover_image')->nullable();
            $table->boolean('has_acrylic')->default(false);
            foreach (['border_size', 'inner_inset', 'depth', 'glass_opacity', 'glass_depth'] as $field) {
                $table->decimal($field, 5, 2)->nullable();
            }
            foreach (['frame_color', 'image_file', 'image_effect', 'addon_options', 'border_color',
                'back_color', 'canvas_type', 'thickness', 'depth_side_color', 'basic_option_name',
                'selectMount', 'selectHangerType', 'selectMaterialFinishType', 'uploadedGLBFile',
                'Scale', 'hangerHeight', 'FloatHeight',
                'FloatWidth', 'FloatDepth', 'PostDepth', 'PostDistance', 'FrameColor',
                'FrameBorderWidth', 'FrameDepth', 'SidePadding', 'frame_texture',
                'custom_size_shopify_variant_id', 'exclusive_product_type', 'exclusive_option',
                'ex_op_canvas_frame_type', 'abs_uuid'] as $field) {
                $table->string($field)->nullable();
            }
            $table->string('addonAdvanceOption')->nullable();
            foreach (['X_Position', 'Y_Position', 'Z_Position'] as $field) {
                $table->string($field)->nullable()->default('0');
            }
            $table->decimal('size_dependency_width', 8, 2)->nullable();
            $table->decimal('size_dependency_height', 8, 2)->nullable();
            $table->string('surface_effect_intensity')->nullable()->default('0');
            $table->boolean('paperBorderOptionCust')->default(false);
            $table->string('frame_type')->default('box');
            $table->boolean('disable_unavailable_sizes')->default(true);
            $table->string('custom_price_type')->default('per_square_inch');
            $table->boolean('is_negative')->default(false);
            $table->string('metal_color', 7)->nullable();
            $table->decimal('metalness', 5, 3)->nullable();
            $table->decimal('metal_roughness', 5, 3)->nullable();
            $table->decimal('metal_bump_strength', 5, 3)->nullable();
            $table->decimal('metal_thickness', 6, 3)->nullable();
            $table->decimal('metal_env_map_intensity', 5, 2)->nullable();
            $table->string('back_metal_color', 7)->nullable();
            foreach (['back_metalness', 'back_metal_roughness', 'back_metal_bump_strength'] as $field) {
                $table->decimal($field, 5, 3)->nullable();
            }
            $table->decimal('back_metal_env_map_intensity', 5, 2)->nullable();
            $table->boolean('back_plain_color')->nullable()->default(false);
            $table->boolean('front_no_color')->nullable()->default(false);
            $table->decimal('surface_roughness', 5, 3)->nullable();
            $table->decimal('reflection_intensity', 5, 2)->nullable();
            $table->string('surface_texture', 32)->nullable();
            $table->decimal('texture_strength', 5, 2)->nullable();
            $table->string('substrate_color', 7)->default('#d9d5cf');
            $table->timestamps();
        });

        Schema::create('product_varients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('shopify_product_varient_id')->nullable();
            $table->string('shopify_inventory_item_id')->nullable();
            $table->double('compare_at_price')->nullable();
            $table->float('price')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->enum('variant_type', ['predefined', 'custom'])->default('predefined');
            $table->string('selectSize')->nullable();
            foreach (['min_width', 'max_width', 'min_height', 'max_height', 'price_per_sq_inch'] as $field) {
                $table->decimal($field, 8, 2)->nullable();
            }
            $table->bigInteger('inventory_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            foreach (['product_id', 'shopify_product_media_id', 'position', 'src'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamps();
        });

        Schema::create('collection_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['collection_id', 'product_id']);
            $table->index(['collection_id', 'is_default']);
        });

        Schema::create('addon_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('status')->default(true);
            $table->integer('position')->default(0);
            $table->integer('priority')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'addon_id']);
        });

        Schema::create('addons_collections_sizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->integer('addon_id')->nullable();
            $table->integer('status')->nullable();
            foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $field) {
                $table->decimal($field, 10, 2)->nullable();
            }
            $table->timestamps();
            $table->unique(['product_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        foreach (['addons_collections_sizes', 'addon_product', 'collection_product', 'product_media',
            'product_varients', 'product_settings', 'products', 'collections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
