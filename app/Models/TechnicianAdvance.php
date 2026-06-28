<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianAdvance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'deduct_month' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
