<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });
    }
};
