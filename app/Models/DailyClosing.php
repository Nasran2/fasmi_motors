<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected $casts = [
        'closing_date' => 'date',
        'main_opening_balance' => 'decimal:2',
        'main_income' => 'decimal:2',
        'main_expense' => 'decimal:2',
        'main_closing_balance' => 'decimal:2',
        'spare_parts_opening_balance' => 'decimal:2',
        'spare_parts_income' => 'decimal:2',
        'spare_parts_expense' => 'decimal:2',
        'spare_parts_closing_balance' => 'decimal:2',
        'bank_opening_balance' => 'decimal:2',
        'bank_income' => 'decimal:2',
        'bank_expense' => 'decimal:2',
        'bank_closing_balance' => 'decimal:2',
        'cash_opening_balance' => 'decimal:2',
        'cash_income' => 'decimal:2',
        'cash_expense' => 'decimal:2',
        'cash_closing_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'main_actual_balance' => 'decimal:2', 'main_difference' => 'decimal:2',
        'spare_parts_actual_balance' => 'decimal:2', 'spare_parts_difference' => 'decimal:2',
        'bank_actual_balance' => 'decimal:2', 'bank_difference' => 'decimal:2',
        'cash_actual_balance' => 'decimal:2', 'cash_difference' => 'decimal:2',
        'reopened_at' => 'datetime',
    ];

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
