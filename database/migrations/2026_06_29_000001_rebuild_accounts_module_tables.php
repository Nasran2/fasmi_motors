<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing to rebuild
        Schema::dropIfExists('daily_closings');
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('cheques');

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no')->unique();
            $table->dateTime('transaction_date')->index();
            $table->string('account_type')->index(); // main, spare_parts, bank, cash
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            
            $table->string('transaction_type'); // income, expense, drawing, transfer_in, transfer_out, etc.
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->decimal('amount', 16, 2);
            $table->decimal('running_balance', 16, 2)->default(0);
            
            $table->string('payment_method')->nullable(); // cash, card, bank_transfer, cheque, qr
            $table->nullableMorphs('reference'); // invoice, job_card, purchase, etc.
            
            $table->string('description');
            $table->text('note')->nullable();
            
            $table->string('status')->default('active'); // active, reversed, cancelled
            
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            
            $table->timestamps();
        });

        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date')->unique();
            
            $table->decimal('main_opening_balance', 16, 2)->default(0);
            $table->decimal('main_income', 16, 2)->default(0);
            $table->decimal('main_expense', 16, 2)->default(0);
            $table->decimal('main_closing_balance', 16, 2)->default(0);
            
            $table->decimal('spare_parts_opening_balance', 16, 2)->default(0);
            $table->decimal('spare_parts_income', 16, 2)->default(0);
            $table->decimal('spare_parts_expense', 16, 2)->default(0);
            $table->decimal('spare_parts_closing_balance', 16, 2)->default(0);
            
            $table->decimal('bank_opening_balance', 16, 2)->default(0);
            $table->decimal('bank_income', 16, 2)->default(0);
            $table->decimal('bank_expense', 16, 2)->default(0);
            $table->decimal('bank_closing_balance', 16, 2)->default(0);
            
            $table->decimal('cash_opening_balance', 16, 2)->default(0);
            $table->decimal('cash_income', 16, 2)->default(0);
            $table->decimal('cash_expense', 16, 2)->default(0);
            $table->decimal('cash_closing_balance', 16, 2)->default(0);
            
            $table->decimal('difference', 16, 2)->default(0);
            $table->text('difference_reason')->nullable();
            
            $table->string('status')->default('closed');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('cheque_type'); // Received, Issued
            $table->nullableMorphs('party'); // Customer, Supplier
            $table->nullableMorphs('reference'); // Invoice, Purchase
            
            $table->string('cheque_number')->index();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->date('cheque_date');
            $table->decimal('amount', 16, 2);
            
            $table->string('status')->default('Pending'); // Pending, Deposited, Cleared, Returned, Cancelled
            $table->date('realized_date')->nullable();
            
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('daily_closings');
        Schema::dropIfExists('account_transactions');
    }
};
