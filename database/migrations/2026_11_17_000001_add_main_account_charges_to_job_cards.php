<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->json('main_account_charges')->nullable();
            $table->decimal('main_account_charge_total', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropColumn(['main_account_charges', 'main_account_charge_total']));
    }
};
