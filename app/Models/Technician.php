<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technician extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joining_date' => 'date', 'active' => 'boolean'];
    }

    public function jobs()
    {
        return $this->hasMany(JobCard::class, 'main_technician_id');
    }

    public function attendance()
    {
        return $this->hasMany(TechnicianAttendance::class);
    }
}
