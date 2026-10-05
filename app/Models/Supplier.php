<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'opening_balance_date' => 'date'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->opening_due - (float) $this->opening_advance
            + (float) $this->purchases()->where('status', 'Completed')->sum(DB::raw('total_amount - returned_amount - paid_amount - hold_amount'));
    }
}
