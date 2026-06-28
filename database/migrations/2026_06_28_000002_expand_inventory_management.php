<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->text('description')->nullable();
        });

        Schema::create('product_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->default('Quantity');
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reorder_level', 12, 2)->nullable()->after('minimum_stock');
            $table->string('image_path')->nullable()->after('supplier_id');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('from_store_id')->constrained('stores');
            $table->foreignId('to_store_id')->constrained('stores');
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->date('transfer_date');
            $table->string('status')->default('Completed');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('stock_damage_reports', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('type');
            $table->decimal('quantity', 12, 2);
            $table->decimal('loss_value', 14, 2)->default(0);
            $table->date('reported_at');
            $table->text('reason');
            $table->string('photo_path')->nullable();
            $table->string('status')->default('Completed');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_damage_reports');
        Schema::dropIfExists('stock_transfers');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['reorder_level', 'image_path']);
        });
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_brands');
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
