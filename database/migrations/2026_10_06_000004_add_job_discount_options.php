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
            $table->string('discount_type')->default('fixed');
            $table->decimal('discount_value', 16, 2)->default(0);
        });
        DB::table('job_cards')->update(['discount_value' => DB::raw('discount')]);
    }

    public function down(): void
    {
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value']));
    }
};
