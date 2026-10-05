<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['collections', 'products'] as $tableName) {
            $foreignKey = $tableName.'_user_id_foreign';

            if (Schema::hasForeignKey($tableName, ['user_id'])) {
                Schema::table($tableName, function (Blueprint $table) use ($foreignKey): void {
                    $table->dropForeign($foreignKey);
                });
            }
        }
    }

    public function down(): void
    {
        // Source user IDs need not exist in Frame Up, so restoring the keys is unsafe.
    }
};
