<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->decimal('drawer_opening_balance', 16, 2)->nullable();
            $table->decimal('drawer_closing_balance', 16, 2)->nullable();
            $table->decimal('drawer_adjustment', 16, 2)->default(0);
            $table->json('account_opening_balances')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_sessions', fn (Blueprint $table) => $table->dropColumn([
            'drawer_opening_balance', 'drawer_closing_balance', 'drawer_adjustment', 'account_opening_balances',
        ]));
    }
};
