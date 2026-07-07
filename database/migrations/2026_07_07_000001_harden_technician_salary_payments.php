<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->string('source_type')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('deduction_name');
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();
            $table->date('deduction_date');
            $table->string('status')->default('pending')->index();
            $table->foreignId('salary_payment_id')->nullable()->constrained('technician_salary_sheets')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('technician_advances', function (Blueprint $table) {
            $table->foreignId('salary_sheet_id')->nullable()->after('status')->constrained('technician_salary_sheets')->nullOnDelete();
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->decimal('attendance_salary_amount', 14, 2)->default(0)->after('daily_salary');
            $table->unsignedSmallInteger('total_working_days')->default(0)->after('salary_month_days');
            $table->decimal('total_present_days', 8, 2)->default(0)->after('total_working_days');
            $table->decimal('total_absent_days', 8, 2)->default(0)->after('total_present_days');
            $table->decimal('leave_days', 8, 2)->default(0)->after('total_absent_days');
            $table->unsignedSmallInteger('late_days')->default(0)->after('leave_days');
            $table->unsignedSmallInteger('early_leave_days')->default(0)->after('late_days');
            $table->unsignedSmallInteger('worked_on_leave_days')->default(0)->after('early_leave_days');
            $table->decimal('total_worked_hours', 10, 2)->default(0)->after('worked_on_leave_days');
            $table->decimal('total_ot_hours', 10, 2)->default(0)->after('total_worked_hours');
            $table->decimal('worked_on_leave_pay', 14, 2)->default(0)->after('manual_additions');
            $table->decimal('total_advance_deduction', 14, 2)->default(0)->after('advances');
            $table->decimal('total_job_deduction', 14, 2)->default(0)->after('own_bike_deduction');
            $table->decimal('total_other_deduction', 14, 2)->default(0)->after('other_deductions');
            $table->decimal('total_deductions', 14, 2)->default(0)->after('manual_deductions');
            $table->decimal('overpaid_amount', 14, 2)->default(0)->after('balance_amount');
            $table->date('payment_date')->nullable()->after('payment_method');
            $table->text('payment_note')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->dropColumn([
                'attendance_salary_amount', 'total_working_days', 'total_present_days', 'total_absent_days',
                'leave_days', 'late_days', 'early_leave_days', 'worked_on_leave_days', 'total_worked_hours',
                'total_ot_hours', 'worked_on_leave_pay', 'total_advance_deduction', 'total_job_deduction',
                'total_other_deduction', 'total_deductions', 'overpaid_amount', 'payment_date', 'payment_note',
            ]);
        });
        Schema::table('technician_advances', fn (Blueprint $table) => $table->dropConstrainedForeignId('salary_sheet_id'));
        Schema::dropIfExists('technician_deductions');
    }
};
