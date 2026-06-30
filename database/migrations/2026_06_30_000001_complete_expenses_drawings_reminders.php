<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->id();
            $table->string('module')->index();
            $table->string('name');
            $table->foreignId('default_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['module', 'name']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('number')->nullable()->unique()->after('id');
            $table->text('description')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('cheque_number')->nullable();
            $table->string('status')->default('Active');
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('drawings', function (Blueprint $table) {
            $table->string('number')->nullable()->unique()->after('id');
            $table->string('payment_method')->default('Cash');
            $table->string('reference_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->string('status')->default('Active');
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('service_reminders', function (Blueprint $table) {
            $table->string('reminder_type')->default('Date Based');
            $table->date('last_service_date')->nullable();
            $table->unsignedInteger('last_service_km')->nullable();
            $table->string('sms_status')->default('Not Sent');
            $table->string('priority')->default('Low');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cost', 10, 2)->default(0);
        });
        foreach (['Rent', 'Electricity Bill', 'Water Bill', 'Staff Food', 'Workshop Tools', 'Cleaning Materials', 'Transport', 'Bank Charges', 'Salary Expense', 'Repair Materials', 'Other Expense'] as $name) {
            DB::table('financial_categories')->insertOrIgnore(['module' => 'expense', 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Owner Drawing', 'Personal Expense', 'Family Expense', 'Partner Drawing', 'Emergency Drawing', 'Other Drawing'] as $name) {
            DB::table('financial_categories')->insertOrIgnore(['module' => 'drawing', 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn('cost');
        });

        Schema::table('service_reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'reminder_type',
                'last_service_date',
                'last_service_km',
                'sms_status',
                'priority',
                'note',
                'completed_at',
            ]);
        });

        Schema::table('drawings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropUnique(['number']);
            $table->dropColumn([
                'number',
                'payment_method',
                'reference_number',
                'cheque_number',
                'status',
                'cancel_reason',
                'cancelled_at',
            ]);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropUnique(['number']);
            $table->dropColumn([
                'number',
                'description',
                'reference_number',
                'payment_reference',
                'cheque_number',
                'status',
                'cancel_reason',
                'cancelled_at',
            ]);
        });

        Schema::dropIfExists('financial_categories');
    }
};
