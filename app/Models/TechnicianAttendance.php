<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianAttendance extends Model
{
    protected $table = 'technician_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }
}
