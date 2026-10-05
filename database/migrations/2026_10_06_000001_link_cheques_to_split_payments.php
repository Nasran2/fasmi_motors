<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'purchases'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->decimal('hold_amount', 16, 2)->default(0));
        }
        foreach (['payments', 'supplier_payments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('cheque_id')->nullable()->constrained('cheques')->restrictOnDelete());
        }
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('shop_type')->default('workshop')->index();
            $table->foreignId('forwarded_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_supplier_id');
            $table->dropColumn('shop_type');
        });
        foreach (['payments', 'supplier_payments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('cheque_id'));
        }
        foreach (['invoices', 'purchases'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('hold_amount'));
        }
    }
};
