<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('product_id')->constrained('stock_batches')->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('store_id')->constrained('stock_batches')->nullOnDelete();
            $table->decimal('cost_price', 14, 2)->default(0)->after('stock_batch_id');
            $table->decimal('profit', 14, 2)->default(0)->after('total');
            $table->decimal('selling_price', 14, 2)->default(0)->after('cost_price'); // Adding explicit selling price for history although unit_price exists
        });

        Schema::table('job_card_parts', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('store_id')->constrained('stock_batches')->nullOnDelete();
            $table->decimal('cost_price', 14, 2)->default(0)->after('stock_batch_id');
            $table->decimal('selling_price', 14, 2)->default(0)->after('cost_price');
            $table->decimal('profit', 14, 2)->default(0)->after('total');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('product_id')->constrained('stock_batches')->nullOnDelete();
            $table->decimal('cost_price', 14, 2)->default(0)->after('balance_after');
            $table->decimal('selling_price', 14, 2)->default(0)->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropColumn(['stock_batch_id', 'cost_price', 'selling_price']);
        });

        Schema::table('job_card_parts', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropColumn(['stock_batch_id', 'cost_price', 'selling_price', 'profit']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropColumn(['stock_batch_id', 'cost_price', 'selling_price', 'profit']);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropColumn(['stock_batch_id']);
        });
    }
};
