<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('collections', 'header_title')) {
            Schema::table('collections', fn (Blueprint $table) => $table->string('header_title')->nullable());
        }

        if (! Schema::hasColumn('products', 'pre_configured')) {
            Schema::table('products', fn (Blueprint $table) => $table->boolean('pre_configured')->default(false));
        }

        if (! Schema::hasColumn('product_settings', 'addonAdvanceOption')) {
            Schema::table('product_settings', fn (Blueprint $table) => $table->string('addonAdvanceOption')->nullable());
        }

        foreach (['position', 'priority'] as $column) {
            if (! Schema::hasColumn('addon_product', $column)) {
                Schema::table('addon_product', function (Blueprint $table) use ($column): void {
                    $column === 'position'
                        ? $table->integer($column)->default(0)
                        : $table->integer($column)->nullable();
                });
            }
        }

        foreach (['addon_id', 'status'] as $column) {
            if (! Schema::hasColumn('addons_collections_sizes', $column)) {
                Schema::table('addons_collections_sizes', fn (Blueprint $table) => $table->integer($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // These columns are part of the initial schema on fresh installations.
    }
};
