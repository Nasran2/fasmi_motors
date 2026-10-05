<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->unsignedBigInteger('account_id')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('supplier_payments')->whereNull('account_id')->exists()) {
            throw new RuntimeException('Resolve party cheque records before restoring required supplier accounts.');
        }
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->unsignedBigInteger('account_id')->nullable(false)->change());
    }
};
