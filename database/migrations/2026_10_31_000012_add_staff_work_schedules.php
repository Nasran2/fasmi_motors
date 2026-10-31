<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('work_schedule_mode', 20)->default('system_default');
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('technicians', fn (Blueprint $table) => $table->dropColumn(['work_schedule_mode', 'work_start_time', 'work_end_time']));
    }
};
