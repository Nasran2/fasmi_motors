<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['job_card_id']);
            $table->dropUnique(['job_card_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('job_card_id')->nullable()->change();
            $table->foreign('job_card_id')->references('id')->on('job_cards')->nullOnDelete();
            $table->unique('job_card_id');
            $table->string('invoice_type')->default('Job Card Bill')->after('number');
            $table->foreignId('store_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('bike_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->decimal('parts_amount', 14, 2)->default(0);
            $table->decimal('service_amount', 14, 2)->default(0);
            $table->decimal('labor_amount', 14, 2)->default(0);
            $table->decimal('washing_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('link_enabled')->default(true);
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('number')->nullable()->unique()->after('id');
            $table->foreignId('account_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->decimal('balance_after', 14, 2)->default(0);
            $table->string('status')->default('Completed');
            $table->text('note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::create('public_bill_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_bill_accesses');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['number', 'balance_after', 'status', 'note', 'cancel_reason', 'cancelled_at']);
        });
        Schema::dropIfExists('invoice_items');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('bike_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['invoice_type', 'parts_amount', 'service_amount', 'labor_amount', 'washing_amount', 'other_amount', 'payment_method', 'link_enabled', 'open_count', 'last_opened_at', 'sms_sent_at', 'cancel_reason', 'cancelled_at']);
        });
    }
};
