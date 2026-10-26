<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained();
            $table->string('number')->unique();
            $table->decimal('amount', 14, 2);
            $table->string('method');
            $table->foreignId('account_id')->constrained();
            $table->foreignId('cheque_id')->nullable()->constrained();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->string('status')->default('Completed');
            $table->date('paid_at');
            $table->boolean('send_sms')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_advances');
    }
};
