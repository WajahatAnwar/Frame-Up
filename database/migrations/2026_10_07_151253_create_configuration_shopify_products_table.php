<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_shopify_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_product_id', 191);
            $table->string('shopify_image_id')->nullable();
            $table->string('shopify_synced_image_path')->nullable();
            $table->timestamps();
            $table->unique(['configuration_id', 'shopify_product_id'], 'config_shopify_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_shopify_products');
    }
};
