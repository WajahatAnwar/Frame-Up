<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter([
            'surface_type',
            'whitePaperPrice',
            'metallicPaperPrice',
            'paperSelection',
            'white_to_gray_strength',
        ], fn (string $column): bool => Schema::hasColumn('product_settings', $column)));

        if ($columns !== []) {
            Schema::table('product_settings', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        // Fresh installations never create these columns, so rollback leaves the source schema intact.
    }
};
