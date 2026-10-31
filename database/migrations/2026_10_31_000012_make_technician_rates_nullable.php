<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->decimal('hourly_salary', 14, 2)->default(0)->nullable()->change();
            $table->decimal('daily_salary', 14, 2)->default(0)->nullable()->change();
            $table->decimal('ot_rate', 10, 2)->default(0)->nullable()->change();
            $table->decimal('late_deduction_rate', 14, 2)->default(0)->nullable()->change();
            $table->decimal('no_pay_deduction_rate', 14, 2)->default(0)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->decimal('hourly_salary', 14, 2)->default(0)->nullable(false)->change();
            $table->decimal('daily_salary', 14, 2)->default(0)->nullable(false)->change();
            $table->decimal('ot_rate', 10, 2)->default(0)->nullable(false)->change();
            $table->decimal('late_deduction_rate', 14, 2)->default(0)->nullable(false)->change();
            $table->decimal('no_pay_deduction_rate', 14, 2)->default(0)->nullable(false)->change();
        });
    }
};

