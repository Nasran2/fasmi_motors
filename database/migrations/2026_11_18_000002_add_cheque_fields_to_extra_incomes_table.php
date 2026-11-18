<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extra_incomes', function (Blueprint $table) {
            $table->foreignId('cheque_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('Completed')->after('payment_method')->index();
        });
    }

    public function down(): void
    {
        Schema::table('extra_incomes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cheque_id');
            $table->dropColumn('status');
        });
    }
};
