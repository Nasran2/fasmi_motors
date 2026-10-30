<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('funding_source', 30)->nullable()->index();
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->string('funding_source', 30)->nullable();
        });
        Schema::table('technician_advances', function (Blueprint $table) {
            $table->json('funding_allocations')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->json('funding_allocations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropIndex(['funding_source']);
            $table->dropColumn('funding_source');
        });
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->dropColumn('funding_source'));
        Schema::table('technician_advances', fn (Blueprint $table) => $table->dropColumn('funding_allocations'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('funding_allocations'));
    }
};
