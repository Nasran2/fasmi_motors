<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cashier_sessions', 'job_cards'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('washing_person_id')->nullable()->constrained('technicians');
                $table->decimal('washing_commission_percentage', 5, 2)->default(10);
            });
        }
        Schema::create('cashier_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_session_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->decimal('cash_amount', 16, 2);
            $table->foreignId('washing_person_id')->nullable()->constrained('technicians');
            $table->decimal('washing_commission_percentage', 5, 2)->default(10);
            $table->json('details')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_session_events');
        foreach (['cashier_sessions', 'job_cards'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('washing_person_id');
                $table->dropColumn('washing_commission_percentage');
            });
        }
    }
};
