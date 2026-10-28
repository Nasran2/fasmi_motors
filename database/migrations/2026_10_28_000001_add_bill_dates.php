<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dateTime('bill_date')->nullable()->index());
        Schema::table('job_cards', fn (Blueprint $table) => $table->date('bill_date')->nullable());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('bill_date'));
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropColumn('bill_date'));
    }
};
