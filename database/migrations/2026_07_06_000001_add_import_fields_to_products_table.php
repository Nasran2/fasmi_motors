<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('opening_amount', 14, 2)->default(0)->after('selling_price');
            $table->string('rack_number', 100)->nullable()->after('opening_amount');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['opening_amount', 'rack_number']);
        });
    }
};
