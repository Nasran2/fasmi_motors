<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryCycleSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'custom_cutoff_enabled' => 'boolean',
            'carry_missing_days' => 'boolean',
            'cycle_end_day' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'salary_cycle_mode' => 'normal_calendar',
            'custom_cutoff_enabled' => false,
            'cycle_start_rule' => 'previous_month_balance_day',
            'cycle_end_day' => 30,
            'carry_missing_days' => true,
        ]);
    }

    public function isCustomCutoffActive(): bool
    {
        return $this->salary_cycle_mode === 'custom_cutoff' && $this->custom_cutoff_enabled;
    }
}
