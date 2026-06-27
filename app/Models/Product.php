<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property-read Collection<int, StoreStock> $stocks
 * @property float|int|null $stocks_sum_quantity
 */
class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function stocks(): HasMany
    {
        return $this->hasMany(StoreStock::class);
    }

    public function getTotalStockAttribute(): float
    {
        return (float) $this->stocks->sum('quantity');
    }
}
