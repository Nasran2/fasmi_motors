<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierSessionEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'cash_amount' => 'decimal:2', 'washing_commission_percentage' => 'decimal:2'];
    }

    public function washingPerson(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'washing_person_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
