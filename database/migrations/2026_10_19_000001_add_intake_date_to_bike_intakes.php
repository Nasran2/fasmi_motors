<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bike_intakes', function (Blueprint $table) {
            $table->date('intake_date')->nullable()->index();
        });

        DB::table('bike_intakes')->update(['intake_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('bike_intakes', function (Blueprint $table) {
            $table->dropColumn('intake_date');
        });
    }
};
