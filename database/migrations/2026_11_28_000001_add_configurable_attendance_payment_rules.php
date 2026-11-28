<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_occurrences_per_half_day')->default(3);
            $table->unsignedSmallInteger('early_occurrences_per_half_day')->default(3);
            $table->decimal('leave_work_salary_divisor', 6, 2)->default(25);
        });

        // Activate the requested policy for future calculations. Saved attendance and
        // paysheets retain their amounts until explicitly recalculated.
        DB::table('salary_settings')->update([
            'late_grace_minutes' => 60, 'early_leave_grace_minutes' => 60,
            'enable_late_deduction' => true, 'late_deduction_type' => 'Half day per occurrences', 'late_deduction_amount' => 0,
            'enable_early_leave_deduction' => true, 'early_leave_deduction_type' => 'Half day per occurrences', 'early_leave_deduction_amount' => 0,
            'enable_ot' => true, 'ot_grace_minutes_after_closing' => 0, 'ot_calculation_type' => 'Fixed amount per slab',
            'ot_round_method' => 'Started interval', 'ot_slab_minutes' => 15, 'ot_amount_per_slab' => 50,
            'enable_half_day' => false,
        ]);
        DB::table('salary_settings')->where('opening_time', '08:30 AM')->update(['opening_time' => '08:00 AM']);
    }

    public function down(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            $table->dropColumn(['late_occurrences_per_half_day', 'early_occurrences_per_half_day', 'leave_work_salary_divisor']);
        });
    }
};
