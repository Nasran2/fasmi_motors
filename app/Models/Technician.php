<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technician extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joining_date' => 'date', 'active' => 'boolean', 'commission_enabled' => 'boolean', 'epf_eligible' => 'boolean', 'etf_eligible' => 'boolean'];
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

    public function advances(): HasMany
    {
        return $this->hasMany(TechnicianAdvance::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(TechnicianCommission::class);
    }

    public function bikeDeductions(): HasMany
    {
        return $this->hasMany(TechnicianBikeDeduction::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(TechnicianContribution::class);
    }
}
