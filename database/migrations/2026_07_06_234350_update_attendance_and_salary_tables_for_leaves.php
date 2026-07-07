<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->boolean('is_auto_checkout')->default(false)->after('out_time');
            $table->text('auto_checkout_reason')->nullable()->after('is_auto_checkout');
            $table->boolean('is_leave_day')->default(false)->after('leave_type');
            $table->foreignId('holiday_id')->nullable()->after('is_leave_day')->constrained('holiday_dates')->nullOnDelete();
            $table->boolean('worked_on_leave_day')->default(false)->after('holiday_id');
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekly_leave_days')->default(0)->after('working_days');
            $table->unsignedSmallInteger('public_holiday_days')->default(0)->after('weekly_leave_days');
            $table->unsignedSmallInteger('poya_holiday_days')->default(0)->after('public_holiday_days');
            $table->unsignedSmallInteger('custom_holiday_days')->default(0)->after('poya_holiday_days');
            $table->unsignedSmallInteger('approved_leave_days')->default(0)->after('custom_holiday_days');
            $table->unsignedSmallInteger('auto_checkout_days')->default(0)->after('approved_leave_days');
            $table->unsignedSmallInteger('salary_month_days')->default(0)->after('auto_checkout_days');
            $table->json('leave_summary')->nullable()->after('salary_month_days');
        });
    }

    public function down(): void
    {
        Schema::table('technician_attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_id');
            $table->dropColumn(['is_auto_checkout', 'auto_checkout_reason', 'is_leave_day', 'worked_on_leave_day']);
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->dropColumn(['weekly_leave_days', 'public_holiday_days', 'poya_holiday_days', 'custom_holiday_days', 'approved_leave_days', 'auto_checkout_days', 'salary_month_days', 'leave_summary']);
        });
    }
};
