<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', fn (Blueprint $table) => $table->decimal('returned_quantity', 16, 2)->default(0));
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('return_amount', 16, 2)->default(0);
            $table->timestamp('parts_deleted_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 16, 2)->default(0);
        });
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->decimal('refund_amount', 16, 2)->default(0);
            $table->json('items');
            $table->json('refunds')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('refunded_amount'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['return_amount', 'parts_deleted_at']));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('returned_quantity'));
    }
};
