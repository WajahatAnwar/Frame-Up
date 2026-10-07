<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configurations', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('shopify_product_id');
            $table->string('shopify_image_id')->nullable()->after('image_path');
            $table->string('shopify_synced_image_path')->nullable()->after('shopify_image_id');
        });
    }

    public function down(): void
    {
        Schema::table('configurations', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'shopify_image_id', 'shopify_synced_image_path']);
        });
    }
};
