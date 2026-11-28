<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySetting extends Model
{
    protected $table = 'salary_settings';

    protected $guarded = [];

    protected $attributes = [
        'opening_time' => '08:00 AM',
        'enable_late_deduction' => true,
        'late_deduction_type' => 'Half day per occurrences',
        'late_grace_minutes' => 60,
        'late_occurrences_per_half_day' => 3,
        'enable_early_leave_deduction' => true,
        'early_leave_deduction_type' => 'Half day per occurrences',
        'early_leave_grace_minutes' => 60,
        'early_occurrences_per_half_day' => 3,
        'enable_ot' => true,
        'ot_grace_minutes_after_closing' => 0,
        'ot_calculation_type' => 'Fixed amount per slab',
        'ot_round_method' => 'Started interval',
        'ot_slab_minutes' => 15,
        'ot_amount_per_slab' => 50,
        'enable_half_day' => false,
        'leave_work_salary_divisor' => 25,
    ];

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
