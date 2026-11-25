<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use ShopScoped, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'funding_allocations' => 'array'];
    }

    public function jobPart(): HasOne
    {
        return $this->hasOne(JobCardPart::class);
    }

    public function paymentFee(): HasOne
    {
        return $this->hasOne(Payment::class, 'charge_expense_id');
    }

    public function advanceFee(): HasOne
    {
        return $this->hasOne(JobAdvance::class, 'charge_expense_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
