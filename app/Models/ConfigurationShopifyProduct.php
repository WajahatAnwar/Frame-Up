<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shopify_product_id', 'shopify_image_id', 'shopify_synced_image_path'])]
class ConfigurationShopifyProduct extends Model
{
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(Configuration::class);
    }
}
