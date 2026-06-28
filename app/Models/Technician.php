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
        return ['joining_date' => 'date', 'active' => 'boolean'];
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
}
