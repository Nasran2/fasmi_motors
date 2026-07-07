<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianAttendance extends Model
{
    protected $table = 'technician_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date', 
            'is_ot_eligible' => 'boolean', 
            'is_manual_entry' => 'boolean', 
            'is_manual_edit' => 'boolean',
            'is_auto_checkout' => 'boolean',
            'is_leave_day' => 'boolean',
            'worked_on_leave_day' => 'boolean',
        ];
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }
}
