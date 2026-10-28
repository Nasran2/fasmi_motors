<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('shop_type')->default('workshop');
            $table->date('session_date');
            $table->string('status')->default('open');
            $table->decimal('opening_balance', 16, 2);
            $table->decimal('owner_withdrawal', 16, 2)->default(0);
            $table->json('closing_summary')->nullable();
            $table->json('actual_balances')->nullable();
            $table->text('notes')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_type', 'session_date']);
        });
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->dropUnique(['closing_date']);
            $table->string('shop_type')->default('workshop');
            $table->unique(['shop_type', 'closing_date']);
            foreach (['opening_balance', 'income', 'expense', 'closing_balance', 'actual_balance', 'difference'] as $field) {
                $table->decimal('washing_'.$field, 16, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('daily_closings')->select('closing_date')->groupBy('closing_date')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve closing dates shared by both shops before restoring the old unique date constraint.');
        }
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->dropUnique(['shop_type', 'closing_date']);
            $table->dropColumn('shop_type');
            foreach (['opening_balance', 'income', 'expense', 'closing_balance', 'actual_balance', 'difference'] as $field) {
                $table->dropColumn('washing_'.$field);
            }
            $table->unique('closing_date');
        });
        Schema::dropIfExists('cashier_sessions');
    }
};
