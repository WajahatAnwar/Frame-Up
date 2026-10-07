<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'shopify_product_type', 'status', 'shopify_product_id', 'image_path', 'shopify_image_id', 'shopify_synced_image_path'])]
class Configuration extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function printTypes(): HasMany
    {
        return $this->hasMany(ConfigurationPrintType::class)->orderBy('position');
    }

    public function shopifyProducts(): HasMany
    {
        return $this->hasMany(ConfigurationShopifyProduct::class);
    }
}
