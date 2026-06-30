<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create salary_settings table
        Schema::create('salary_settings', function (Blueprint $table) {
            $table->id();
            $table->string('salary_cycle')->default('Monthly');
            $table->unsignedInteger('salary_month_start_day')->default(1);
            $table->boolean('allow_daily_advance')->default(true);
            $table->string('opening_time')->default('08:30 AM');
            $table->string('closing_time')->default('05:00 PM');
            $table->decimal('working_hours_per_day', 4, 2)->default(8.0);
            $table->unsignedInteger('lunch_break_minutes')->default(0);
            $table->json('work_days')->nullable();
            
            $table->boolean('enable_late_deduction')->default(true);
            $table->unsignedInteger('late_grace_minutes')->default(15);
            $table->string('late_deduction_type')->default('Fixed amount');
            $table->decimal('late_deduction_amount', 14, 2)->default(0);
            $table->boolean('require_reason_late_edit')->default(true);
            
            $table->boolean('enable_early_leave_deduction')->default(false);
            $table->unsignedInteger('early_leave_grace_minutes')->default(15);
            $table->string('early_leave_deduction_type')->default('Fixed amount');
            $table->decimal('early_leave_deduction_amount', 14, 2)->default(0);
            $table->boolean('require_reason_early_leave')->default(true);
            
            $table->boolean('enable_ot')->default(true);
            $table->unsignedInteger('ot_grace_minutes_after_closing')->default(15);
            $table->string('ot_calculation_type')->default('Fixed amount per hour');
            $table->string('ot_round_method')->default('Ceiling slab');
            $table->decimal('ot_amount_per_slab', 14, 2)->default(0);
            $table->unsignedInteger('ot_slab_minutes')->default(30);
            $table->boolean('require_ot_approval')->default(false);
            $table->boolean('allow_manual_ot_override')->default(true);
            
            $table->boolean('enable_half_day')->default(true);
            $table->string('morning_half_day_cutoff')->default('12:30 PM');
            $table->string('evening_half_day_cutoff')->default('01:30 PM');
            $table->string('half_day_deduction_type')->default('Half of daily salary');
            $table->decimal('half_day_deduction_amount', 14, 2)->default(0);
            
            $table->boolean('paysheet_finalize_lock')->default(true);
            $table->boolean('require_reason_reopen_paysheet')->default(true);
            
            $table->timestamps();
        });

        // 2. Create ot_slab_rules table
        Schema::create('ot_slab_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('from_minutes');
            $table->unsignedInteger('to_minutes');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('calculation_rule')->default('Fixed for this range');
            $table->string('status')->default('Active');
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 3. Update technician_attendance table
        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->unsignedInteger('working_minutes')->default(0)->after('working_hours');
            $table->unsignedInteger('early_leave_minutes')->default(0)->after('late_minutes');
            $table->unsignedInteger('ot_minutes')->default(0)->after('ot_hours');
            $table->decimal('ot_amount', 14, 2)->default(0)->after('ot_minutes');
            $table->decimal('deduction_amount', 14, 2)->default(0)->after('ot_amount');
            
            $table->decimal('late_deduction_amount', 14, 2)->default(0)->after('deduction_amount');
            $table->decimal('early_leave_deduction_amount', 14, 2)->default(0)->after('late_deduction_amount');
            $table->decimal('half_day_deduction_amount', 14, 2)->default(0)->after('early_leave_deduction_amount');
            
            $table->boolean('is_manual_edit')->default(false)->after('status');
            $table->foreignId('updated_by')->nullable()->after('edited_by')->constrained('users')->nullOnDelete();
        });

        // 4. Update technician_salary_sheets table
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->decimal('early_leave_deduction', 14, 2)->default(0)->after('late_deduction');
            $table->decimal('half_day_deduction', 14, 2)->default(0)->after('early_leave_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->dropColumn(['early_leave_deduction', 'half_day_deduction']);
        });

        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'working_minutes', 'early_leave_minutes', 'ot_minutes', 'ot_amount', 
                'deduction_amount', 'late_deduction_amount', 'early_leave_deduction_amount', 
                'half_day_deduction_amount', 'is_manual_edit', 'updated_by'
            ]);
        });

        Schema::dropIfExists('ot_slab_rules');
        Schema::dropIfExists('salary_settings');
    }
};
