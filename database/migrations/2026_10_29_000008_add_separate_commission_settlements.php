<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('technician_commissions', 'shop_type')) {
            Schema::table('technician_commissions', fn (Blueprint $table) => $table->string('shop_type')->default('workshop')->index());
        }
        foreach (['paid_amount', 'deducted_amount'] as $column) {
            if (! Schema::hasColumn('technician_commissions', $column)) {
                Schema::table('technician_commissions', fn (Blueprint $table) => $table->decimal($column, 14, 2)->default(0));
            }
        }
        DB::table('technician_commissions')->join('invoices', 'invoices.id', '=', 'technician_commissions.invoice_id')
            ->update(['technician_commissions.shop_type' => DB::raw('invoices.shop_type')]);
        if (! Schema::hasTable('commission_payments')) {
            Schema::create('commission_payments', function (Blueprint $table) {
                $table->id();
                $table->string('number')->unique();
                $table->foreignId('technician_id')->constrained();
                $table->foreignId('account_id')->constrained();
                $table->decimal('amount', 14, 2);
                $table->string('payment_method');
                $table->date('payment_date');
                $table->text('note')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('commission_payment_items')) {
            Schema::create('commission_payment_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commission_payment_id')->constrained();
                $table->foreignId('technician_commission_id')->constrained();
                $table->decimal('amount', 14, 2);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('commission_deductions')) {
            Schema::create('commission_deductions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('technician_commission_id')->constrained();
                $table->decimal('amount', 14, 2);
                $table->string('description', 500);
                $table->date('deduction_date');
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_deductions');
        Schema::dropIfExists('commission_payment_items');
        Schema::dropIfExists('commission_payments');
        Schema::table('technician_commissions', fn (Blueprint $table) => $table->dropColumn(['paid_amount', 'deducted_amount', 'shop_type']));
    }
};
