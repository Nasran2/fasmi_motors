<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property-read Collection<int, StoreStock> $stocks
 * @property float|int|null $stocks_sum_quantity
 */
class Product extends Model
{
    use \App\Traits\ShopScoped, SoftDeletes;

    protected $guarded = [];

    public function stocks(): HasMany
    {
        return $this->hasMany(StoreStock::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function getTotalStockAttribute(): float
    {
        return (float) $this->stocks->sum('quantity');
    }
}
