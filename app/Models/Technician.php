<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $hourly_salary
 */
class Technician extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'epf_etf_basic_salary' => 30000,
        'hourly_salary' => 0,
        'daily_salary' => 0,
        'ot_rate' => 0,
        'late_deduction_rate' => 0,
        'no_pay_deduction_rate' => 0,
    ];

    public function setHourlySalaryAttribute($value): void
    {
        $this->attributes['hourly_salary'] = ($value !== null && $value !== '') ? (float) $value : 0;
    }

    public function setDailySalaryAttribute($value): void
    {
        $this->attributes['daily_salary'] = ($value !== null && $value !== '') ? (float) $value : 0;
    }

    public function setOtRateAttribute($value): void
    {
        $this->attributes['ot_rate'] = ($value !== null && $value !== '') ? (float) $value : 0;
    }

    public function setLateDeductionRateAttribute($value): void
    {
        $this->attributes['late_deduction_rate'] = ($value !== null && $value !== '') ? (float) $value : 0;
    }

    public function setNoPayDeductionRateAttribute($value): void
    {
        $this->attributes['no_pay_deduction_rate'] = ($value !== null && $value !== '') ? (float) $value : 0;
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'joining_date' => 'date', 'active' => 'boolean', 'commission_enabled' => 'boolean', 'epf_eligible' => 'boolean', 'etf_eligible' => 'boolean', 'epf_etf_basic_salary' => 'decimal:2', 'salary_start_day' => 'integer'];
    }

    /** @return HasMany<JobCard, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(JobCard::class, 'main_technician_id');
    }

    /** @return HasMany<TechnicianAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(TechnicianAttendance::class);
    }

    public function salarySheets(): HasMany
    {
        return $this->hasMany(TechnicianSalarySheet::class);
    }

    /** @return HasMany<TechnicianAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(TechnicianAdvance::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(TechnicianCommission::class);
    }

    /** @return HasMany<TechnicianBikeDeduction, $this> */
    public function bikeDeductions(): HasMany
    {
        return $this->hasMany(TechnicianBikeDeduction::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(TechnicianDeduction::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(TechnicianContribution::class);
    }
}
