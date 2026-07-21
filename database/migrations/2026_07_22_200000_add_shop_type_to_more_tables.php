<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_brands', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('product_brands', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });
    }
};
