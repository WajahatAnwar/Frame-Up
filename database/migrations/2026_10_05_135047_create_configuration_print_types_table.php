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
        Schema::create('configuration_print_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->unsignedInteger('position')->default(0);
            $table->json('selected_variant_ids');
            $table->json('selected_addon_ids');
            $table->timestamps();
            $table->index(['configuration_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuration_print_types');
    }
};
