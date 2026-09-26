<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_commissions', function (Blueprint $table) {
            $table->foreignId('salary_sheet_id')->nullable()->constrained('technician_salary_sheets')->nullOnDelete();
        });
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id', 'status'], 'ledger_reference_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('account_transactions', fn (Blueprint $table) => $table->dropIndex('ledger_reference_status_index'));
        Schema::table('technician_commissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('salary_sheet_id'));
    }
};
