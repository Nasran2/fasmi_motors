<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('technician_deductions', 'recovered_amount')) {
            Schema::table('technician_deductions', function (Blueprint $table) {
                $table->decimal('recovered_amount', 14, 2)->default(0);
            });
        }
        DB::table('technician_deductions')->where('source_type', 'salary_overpayment')->where('status', 'deducted')->update(['recovered_amount' => DB::raw('amount')]);
        Schema::create('technician_salary_balance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_deduction_id')->constrained('technician_deductions', indexName: 'tech_bal_rec_ded_fk')->restrictOnDelete();
            $table->foreignId('salary_sheet_id')->constrained('technician_salary_sheets', indexName: 'tech_bal_rec_sheet_fk')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['technician_deduction_id', 'salary_sheet_id'], 'salary_balance_recovery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_salary_balance_recoveries');
        Schema::table('technician_deductions', fn (Blueprint $table) => $table->dropColumn('recovered_amount'));
    }
};
