<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OwnerCapital extends Model
{
    use ShopScoped, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['capital_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function version(): string
    {
        return hash('sha256', json_encode($this->getAttributes()));
    }
}
