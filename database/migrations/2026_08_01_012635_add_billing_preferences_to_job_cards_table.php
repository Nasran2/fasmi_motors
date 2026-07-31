<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->boolean('show_washing_on_bill')->default(true);
            $table->boolean('show_labor_on_bill')->default(true);
            $table->string('other_income_name')->nullable()->default('Other');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn(['show_washing_on_bill', 'show_labor_on_bill', 'other_income_name']);
        });
    }
};
