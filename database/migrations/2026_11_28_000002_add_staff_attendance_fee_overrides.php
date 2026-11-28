<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->time('late_cutoff_time')->nullable();
            $table->time('early_leave_cutoff_time')->nullable();
            $table->decimal('late_fee', 14, 2)->nullable();
            $table->decimal('early_leave_fee', 14, 2)->nullable();
        });

        DB::table('salary_settings')->update([
            'late_grace_minutes' => 15, 'early_leave_grace_minutes' => 15,
            'late_deduction_amount' => 50, 'early_leave_deduction_amount' => 50,
            'late_deduction_type' => 'Half day per occurrences', 'early_leave_deduction_type' => 'Half day per occurrences',
            'enable_late_deduction' => true, 'enable_early_leave_deduction' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn(['late_cutoff_time', 'early_leave_cutoff_time', 'late_fee', 'early_leave_fee']);
        });
    }
};
