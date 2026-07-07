<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayDate extends Model
{
    protected $fillable = [
        'holiday_date',
        'holiday_name',
        'holiday_type',
        'is_leave_enabled',
        'is_active',
        'source',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_leave_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
