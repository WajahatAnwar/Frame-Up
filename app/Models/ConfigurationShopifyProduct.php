<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shopify_product_id'])]
class ConfigurationShopifyProduct extends Model
{
    protected $hidden = ['shopify_image_id', 'shopify_synced_image_path'];

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(Configuration::class);
    }
}
