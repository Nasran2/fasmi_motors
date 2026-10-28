<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', fn (Blueprint $table) => $table->decimal('hourly_salary', 14, 2)->default(0));
    }

    public function down(): void
    {
        Schema::table('technicians', fn (Blueprint $table) => $table->dropColumn('hourly_salary'));
    }
};
