<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('attendance_person_id', 80)->nullable()->unique()->after('id');
            $table->string('department')->nullable()->after('name');
        });

        Schema::create('attendance_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->date('month')->nullable();
            $table->foreignId('imported_by')->constrained('users');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('status')->default('Preview');
            $table->json('preview_data')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->string('source_person_id', 80)->nullable()->after('technician_id');
            $table->string('department')->nullable()->after('source_person_id');
            $table->string('shift_name')->nullable()->after('date');
            $table->string('timetable')->nullable()->after('shift_name');
            $table->unsignedInteger('break_minutes')->default(0)->after('working_minutes');
            $table->unsignedInteger('absent_minutes')->default(0)->after('break_minutes');
            $table->boolean('is_ot_eligible')->default(false)->after('ot_amount');
            $table->boolean('is_manual_entry')->default(false)->after('is_manual_edit');
            $table->foreignId('import_batch_id')->nullable()->after('is_manual_entry')->constrained('attendance_import_batches')->nullOnDelete();
            $table->string('leave_type')->nullable()->after('import_batch_id');
            $table->text('remarks')->nullable()->after('leave_type');
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->decimal('daily_salary', 14, 2)->default(0)->after('basic_salary');
            $table->decimal('worked_days', 8, 2)->default(0)->after('working_days');
            $table->decimal('half_days', 8, 2)->default(0)->after('absent_days');
            $table->decimal('allowances', 14, 2)->default(0)->after('commission');
            $table->decimal('manual_additions', 14, 2)->default(0)->after('allowances');
            $table->decimal('manual_deductions', 14, 2)->default(0)->after('other_deductions');
            $table->json('calculation_snapshot')->nullable()->after('net_payable');
            $table->timestamp('paid_at')->nullable()->after('finalized_at');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable()->after('edit_reason');
        });

        Schema::create('salary_payment_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_sheet_id')->constrained('technician_salary_sheets')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained();
            $table->string('deduction_name');
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();
            $table->date('deduction_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('salary_payment_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_sheet_id')->constrained('technician_salary_sheets')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained();
            $table->string('addition_name');
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();
            $table->date('addition_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payment_additions');
        Schema::dropIfExists('salary_payment_deductions');
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['daily_salary', 'worked_days', 'half_days', 'allowances', 'manual_additions', 'manual_deductions', 'calculation_snapshot', 'paid_at', 'reopen_reason']);
        });
        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['source_person_id', 'department', 'shift_name', 'timetable', 'break_minutes', 'absent_minutes', 'is_ot_eligible', 'is_manual_entry', 'leave_type', 'remarks']);
        });
        Schema::dropIfExists('attendance_import_batches');
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn(['attendance_person_id', 'department']);
        });
    }
};
