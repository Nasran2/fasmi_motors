<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySetting extends Model
{
    protected $table = 'salary_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'allow_daily_advance' => 'boolean',
            'enable_late_deduction' => 'boolean',
            'require_reason_late_edit' => 'boolean',
            'enable_early_leave_deduction' => 'boolean',
            'require_reason_early_leave' => 'boolean',
            'enable_ot' => 'boolean',
            'require_ot_approval' => 'boolean',
            'allow_manual_ot_override' => 'boolean',
            'enable_half_day' => 'boolean',
            'paysheet_finalize_lock' => 'boolean',
            'require_reason_reopen_paysheet' => 'boolean',
            'slip_show_attendance' => 'boolean',
        ];
    }
}
