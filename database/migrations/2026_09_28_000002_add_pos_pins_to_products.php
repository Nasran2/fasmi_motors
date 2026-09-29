<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pos_pinned')->default(false);
            $table->unsignedInteger('pos_pin_order')->nullable();
            $table->index(['shop_type', 'pos_pinned', 'pos_pin_order']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_type', 'pos_pinned', 'pos_pin_order']);
            $table->dropColumn(['pos_pinned', 'pos_pin_order']);
        });
    }
};
