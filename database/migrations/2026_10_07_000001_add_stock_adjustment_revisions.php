<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('adjustment_direction', 20)->nullable();
            $table->decimal('adjustment_quantity', 12, 2)->nullable();
            $table->decimal('adjustment_base_quantity', 12, 2)->nullable();
            $table->decimal('adjustment_effect_quantity', 12, 2)->nullable();
            $table->timestamp('voided_at')->nullable()->index();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['adjustment_direction', 'adjustment_quantity', 'adjustment_base_quantity', 'adjustment_effect_quantity', 'voided_at', 'void_reason']);
        });
    }
};
