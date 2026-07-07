<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianSalarySheet extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'payment_date' => 'date',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'calculation_snapshot' => 'array',
            'leave_summary' => 'array',
            'excluded_dates' => 'array',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function additions(): HasMany
    {
        return $this->hasMany(SalaryPaymentAddition::class, 'salary_sheet_id');
    }

    public function paymentDeductions(): HasMany
    {
        return $this->hasMany(SalaryPaymentDeduction::class, 'salary_sheet_id');
    }

    public function sourceDeductions(): HasMany
    {
        return $this->hasMany(TechnicianDeduction::class, 'salary_payment_id');
    }

    public function advancesUsed(): HasMany
    {
        return $this->hasMany(TechnicianAdvance::class, 'salary_sheet_id');
    }
}
