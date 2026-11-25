<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('charge_rate', 5, 2)->default(0)->after('change_amount');
            $table->decimal('charge_amount', 14, 2)->default(0)->after('charge_rate');
            $table->foreignId('charge_expense_id')->nullable()->after('charge_amount')->constrained('expenses')->nullOnDelete();
        });

        Schema::table('job_advances', function (Blueprint $table) {
            $table->decimal('charge_rate', 5, 2)->default(0)->after('amount');
            $table->decimal('charge_amount', 14, 2)->default(0)->after('charge_rate');
            $table->foreignId('charge_expense_id')->nullable()->after('charge_amount')->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_expense_id');
            $table->dropColumn(['charge_rate', 'charge_amount']);
        });

        Schema::table('job_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_expense_id');
            $table->dropColumn(['charge_rate', 'charge_amount']);
        });
    }
};
