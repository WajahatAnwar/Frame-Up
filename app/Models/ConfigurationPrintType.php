<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collection_id', 'product_id', 'position', 'selected_variant_ids', 'selected_addon_ids'])]
class ConfigurationPrintType extends Model
{
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(Configuration::class);
    }

    protected function casts(): array
    {
        return [
            'selected_variant_ids' => 'array',
            'selected_addon_ids' => 'array',
        ];
    }
}
