<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! collect(Schema::getIndexes('products'))->contains('name', 'products_pre_configured_index')) {
            Schema::table('products', fn (Blueprint $table) => $table->index('pre_configured'));
        }

        if (! Schema::hasColumn('product_settings', 'wood_mount_type')) {
            Schema::table('product_settings', fn (Blueprint $table) => $table->string('wood_mount_type', 40)->default('none'));
        }

        Schema::table('product_settings', function (Blueprint $table): void {
            $table->string('surface_effect_intensity')->nullable()->default('0')->change();
            $table->boolean('back_plain_color')->nullable()->default(false)->change();
            $table->boolean('front_no_color')->nullable()->default(false)->change();

            foreach (['X_Position', 'Y_Position', 'Z_Position'] as $column) {
                $table->string($column)->nullable()->default('0')->change();
            }
        });
    }

    public function down(): void
    {
        // Fresh installs define these columns in the initial catalog migration.
    }
};
