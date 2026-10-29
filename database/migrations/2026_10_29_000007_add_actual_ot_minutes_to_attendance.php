<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_attendance', function (Blueprint $table) {
            // Null keeps legacy rows on their original actual-minute basis until recalculated.
            $table->unsignedInteger('actual_ot_minutes')->nullable()->after('ot_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('technician_attendance', fn (Blueprint $table) => $table->dropColumn('actual_ot_minutes'));
    }
};
