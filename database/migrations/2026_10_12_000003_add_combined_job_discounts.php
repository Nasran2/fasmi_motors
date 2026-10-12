<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('fixed_discount', 16, 2)->default(0);
        });

        DB::table('job_cards')->update([
            'discount_percentage' => DB::raw("case when discount_type = 'percentage' then discount_value else 0 end"),
            'fixed_discount' => DB::raw("case when discount_type = 'percentage' then 0 else discount end"),
        ]);
    }

    public function down(): void
    {
        // Retain the combined amount as a fixed discount for older versions.
        DB::table('job_cards')->update(['discount_type' => 'fixed', 'discount_value' => DB::raw('discount')]);
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropColumn(['discount_percentage', 'fixed_discount']));
    }
};
