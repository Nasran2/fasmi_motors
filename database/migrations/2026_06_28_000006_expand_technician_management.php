<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('alternative_phone')->nullable()->after('phone');
            $table->string('salary_type')->default('Monthly')->after('basic_salary');
            $table->decimal('daily_salary', 14, 2)->default(0)->after('salary_type');
            $table->decimal('late_deduction_rate', 14, 2)->default(0)->after('ot_rate');
            $table->decimal('no_pay_deduction_rate', 14, 2)->default(0)->after('late_deduction_rate');
            $table->boolean('commission_enabled')->default(true)->after('commission_percentage');
            $table->string('commission_type')->default('Service / labor')->after('commission_enabled');
            $table->boolean('epf_eligible')->default(true)->after('etf_number');
            $table->boolean('etf_eligible')->default(true)->after('epf_eligible');
            $table->decimal('epf_employee_percentage', 5, 2)->default(8)->after('etf_eligible');
            $table->decimal('epf_company_percentage', 5, 2)->default(12)->after('epf_employee_percentage');
            $table->decimal('etf_company_percentage', 5, 2)->default(3)->after('epf_company_percentage');
        });

        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->text('edit_reason')->nullable()->after('note');
            $table->foreignId('edited_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->string('number')->nullable()->unique()->after('id');
            $table->unsignedSmallInteger('working_days')->default(0)->after('period_end');
            $table->unsignedSmallInteger('present_days')->default(0)->after('working_days');
            $table->unsignedSmallInteger('absent_days')->default(0)->after('present_days');
            $table->decimal('ot_hours', 8, 2)->default(0)->after('absent_days');
            $table->decimal('gross_salary', 14, 2)->default(0)->after('commission');
            $table->decimal('late_deduction', 14, 2)->default(0)->after('advances');
            $table->decimal('no_pay_deduction', 14, 2)->default(0)->after('late_deduction');
            $table->decimal('own_bike_deduction', 14, 2)->default(0)->after('no_pay_deduction');
            $table->decimal('other_deductions', 14, 2)->default(0)->after('own_bike_deduction');
            $table->decimal('balance_amount', 14, 2)->default(0)->after('paid_amount');
            $table->foreignId('account_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->string('payment_method')->nullable()->after('account_id');
            $table->timestamp('finalized_at')->nullable()->after('payment_method');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->text('edit_reason')->nullable()->after('finalized_by');
        });

        Schema::create('technician_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('technician_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method');
            $table->date('deduct_month');
            $table->string('status')->default('Pending Deduction');
            $table->text('note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('technician_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commission_type');
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->date('earned_date');
            $table->string('status')->default('Pending');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('technician_bike_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('technician_id')->constrained();
            $table->foreignId('bike_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_amount', 14, 2);
            $table->decimal('deducted_amount', 14, 2)->default(0);
            $table->string('deduction_type');
            $table->decimal('installment_amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->date('start_month');
            $table->date('next_deduction_month')->nullable();
            $table->string('status')->default('Active');
            $table->text('note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('technician_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained();
            $table->foreignId('salary_sheet_id')->nullable()->constrained('technician_salary_sheets')->nullOnDelete();
            $table->date('month');
            $table->decimal('basic_salary', 14, 2);
            $table->decimal('employee_epf_percentage', 5, 2);
            $table->decimal('employee_epf_amount', 14, 2);
            $table->decimal('company_epf_percentage', 5, 2);
            $table->decimal('company_epf_amount', 14, 2);
            $table->decimal('company_etf_percentage', 5, 2);
            $table->decimal('company_etf_amount', 14, 2);
            $table->string('status')->default('Unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['technician_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_contributions');
        Schema::dropIfExists('technician_bike_deductions');
        Schema::dropIfExists('technician_commissions');
        Schema::dropIfExists('technician_advances');
    }
};
