<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('company_name')->nullable();
            $table->string('alternative_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('supplier_type')->default('Spare Parts Supplier');
            $table->boolean('active')->default(true);
            $table->decimal('opening_due', 16, 2)->default(0);
            $table->decimal('opening_advance', 16, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->text('opening_note')->nullable();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('invoice_number')->index();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->date('purchase_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->decimal('subtotal', 16, 2);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('other_cost', 16, 2)->default(0);
            $table->decimal('total_amount', 16, 2);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->decimal('due_amount', 16, 2)->default(0);
            $table->decimal('returned_amount', 16, 2)->default(0);
            $table->string('payment_status')->default('Due');
            $table->string('payment_method')->nullable();
            $table->string('status')->default('Completed');
            $table->text('note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'invoice_number']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('cost_price', 16, 2);
            $table->decimal('selling_price', 16, 2);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2);
            $table->decimal('returned_quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['purchase_id', 'product_id']);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('purchase_item_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('return_value', 16, 2);
            $table->string('refund_type');
            $table->string('status')->default('Pending');
            $table->date('return_date');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('account_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->date('payment_date')->index();
            $table->decimal('amount', 16, 2);
            $table->decimal('balance_after', 16, 2)->default(0);
            $table->string('payment_method');
            $table->string('reference')->nullable();
            $table->string('status')->default('Completed');
            $table->text('note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'alternative_phone', 'email', 'contact_person', 'supplier_type', 'active', 'opening_due', 'opening_advance', 'opening_balance_date', 'opening_note']);
        });
    }
};
