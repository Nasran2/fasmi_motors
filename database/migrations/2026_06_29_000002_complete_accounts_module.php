<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('category')->nullable()->after('amount');
        });
        Schema::table('account_transfers', function (Blueprint $table) {
            $table->string('transfer_no')->nullable()->unique()->after('id');
            $table->string('payment_method')->nullable()->after('transfer_date');
            $table->string('status')->default('Completed');
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('party_name')->nullable()->after('cheque_type');
            $table->string('related_reference')->nullable()->after('party_name');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('daily_closings', function (Blueprint $table) {
            foreach (['main', 'spare_parts', 'bank', 'cash'] as $type) {
                $table->decimal("{$type}_actual_balance", 16, 2)->default(0);
                $table->decimal("{$type}_difference", 16, 2)->default(0);
            }
            $table->text('reopen_reason')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_closings', function (Blueprint $table) {
            $columns = ['reopen_reason', 'reopened_by', 'reopened_at'];
            foreach (['main', 'spare_parts', 'bank', 'cash'] as $type) {
                $columns[] = "{$type}_actual_balance";
                $columns[] = "{$type}_difference";
            }
            $table->dropColumn($columns);
        });
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['party_name', 'related_reference', 'bank_account_id', 'status_reason', 'updated_by']);
        });
        Schema::table('account_transfers', function (Blueprint $table) {
            $table->dropColumn(['transfer_no', 'payment_method', 'status', 'cancel_reason', 'cancelled_by', 'cancelled_at']);
        });
        Schema::table('account_transactions', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
