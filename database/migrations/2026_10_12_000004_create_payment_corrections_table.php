<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('payment_id')->unique()->constrained();
            $table->foreignId('replacement_payment_id')->constrained('payments');
            $table->json('before');
            $table->json('after');
            $table->text('reason');
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_corrections');
    }
};
