<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->decimal('present_days', 8, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('technician_salary_sheets', function (Blueprint $table) {
            $table->unsignedSmallInteger('present_days')->default(0)->change();
        });
    }
};

