<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('bike_intake_id')->nullable()->change();
            $table->boolean('is_quick_job')->default(false);
            $table->foreignId('quick_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('quick_bike_id')->nullable()->constrained('bikes')->nullOnDelete();
            $table->text('quick_complaint')->nullable();
            $table->json('quick_bike_details')->nullable();
            $table->boolean('is_staff_bike')->default(false);
            $table->foreignId('staff_bike_owner_id')->nullable()->constrained('technicians')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quick_customer_id');
            $table->dropConstrainedForeignId('quick_bike_id');
            $table->dropConstrainedForeignId('staff_bike_owner_id');
            $table->dropColumn(['is_quick_job', 'quick_complaint', 'quick_bike_details', 'is_staff_bike']);
            // Keep intake nullable so rollback never deletes jobs created without an intake.
        });
    }
};
