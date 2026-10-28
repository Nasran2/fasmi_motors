<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_date' => 'date', 'opening_balance' => 'decimal:2',
            'washing_commission_percentage' => 'decimal:2', 'owner_withdrawal' => 'decimal:2', 'closing_summary' => 'array',
            'actual_balances' => 'array', 'cash_balances' => 'array', 'cash_opening_balances' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function washingPerson(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'washing_person_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CashierSessionEvent::class);
    }

    public static function active(): ?self
    {
        return static::where('status', 'open')->oldest('session_date')->first();
    }

    public static function isOpenToday(): bool
    {
        return static::where('status', 'open')->whereDate('session_date', today())->exists();
    }
}
