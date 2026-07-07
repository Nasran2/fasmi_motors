<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_cycle_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('salary_cycle_mode', ['normal_calendar', 'custom_cutoff'])->default('normal_calendar');
            $table->boolean('custom_cutoff_enabled')->default(false);
            $table->string('cycle_start_rule')->nullable();
            $table->unsignedTinyInteger('cycle_end_day')->nullable();
            $table->boolean('carry_missing_days')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->string('salary_cycle_mode')->default('normal_calendar')->after('period_end');
            $table->unsignedSmallInteger('salary_period_days')->default(0)->after('salary_cycle_mode');
            $table->unsignedSmallInteger('carried_from_previous_month_days')->default(0)->after('salary_period_days');
            $table->unsignedSmallInteger('carried_to_next_month_days')->default(0)->after('carried_from_previous_month_days');
            $table->json('excluded_dates')->nullable()->after('carried_to_next_month_days');
        });
    }

    public function down(): void
    {
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->dropColumn([
                'salary_cycle_mode',
                'salary_period_days',
                'carried_from_previous_month_days',
                'carried_to_next_month_days',
                'excluded_dates',
            ]);
        });

        Schema::dropIfExists('salary_cycle_settings');
    }
};
