<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCardPart extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_external' => 'boolean'];
    }

    public function getNameAttribute(): string
    {
        return $this->display_name ?: ($this->product?->name ?? 'Outer product');
    }

    public function getBillNameAttribute(): string
    {
        return $this->name.($this->note ? ' ('.$this->note.')' : '');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockBatch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
