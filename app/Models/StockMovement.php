<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['voided_at' => 'datetime'];
    }

    public function isAdjustment(): bool
    {
        return in_array($this->type, ['adjustment_in', 'adjustment_out', 'adjustment_price'], true);
    }

    public function adjustmentEffect(): float
    {
        return (float) ($this->adjustment_effect_quantity ?? $this->quantity);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }
}
