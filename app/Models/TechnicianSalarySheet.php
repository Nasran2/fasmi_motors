<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, mixed>|null $calculation_snapshot
 */
class TechnicianSalarySheet extends Model
{
    protected $guarded = [];

    public function getOldBalanceDeductionAttribute(): float
    {
        return round((float) collect(data_get($this->calculation_snapshot, 'old_balances', []))->sum('amount'), 2);
    }

    public function getRepairDeductionAmountAttribute(): float
    {
        return round((float) collect(data_get($this->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment')->sum('amount'), 2);
    }

    /** @return HasMany<Payment, $this> */
    public function repairPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'salary_sheet_id')->where('method', 'Salary Deduction')->where('status', 'Completed');
    }

    public function getBaseEarningsAttribute(): float
    {
        return (float) ($this->calculation_snapshot || (float) $this->attendance_salary_amount !== 0.0
            ? $this->attendance_salary_amount : $this->basic_salary);
    }

    public function getBaseEarningsLabelAttribute(): string
    {
        return data_get($this->calculation_snapshot, 'earnings.salary_basis') === 'full_basic' ? 'Basic salary' : 'Base earnings';
    }

    public function getUsesLegacyDailySalaryAttribute(): bool
    {
        return data_get($this->calculation_snapshot, 'earnings.salary_type', data_get($this->calculation_snapshot, 'technician.salary_type')) === 'Daily'
            && data_get($this->calculation_snapshot, 'earnings.salary_basis') !== 'full_basic';
    }

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
