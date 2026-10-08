<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('product_settings', 'white_to_gray_strength')) {
            Schema::table('product_settings', function (Blueprint $table): void {
                $table->decimal('white_to_gray_strength', 5, 2)->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('product_settings', 'white_to_gray_strength')) {
            Schema::table('product_settings', fn (Blueprint $table) => $table->dropColumn('white_to_gray_strength'));
        }
    }
};
