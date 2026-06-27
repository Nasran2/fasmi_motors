<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->index();
            $table->string('nic')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->text('address')->nullable();
            $table->string('nic')->nullable();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->decimal('ot_rate', 10, 2)->default(0);
            $table->string('epf_number')->nullable();
            $table->string('etf_number')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('bikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('number')->unique();
            $table->string('model');
            $table->string('brand')->nullable();
            $table->string('color')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->unsignedInteger('last_mileage')->nullable();
            $table->boolean('is_technician_bike')->default(false);
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('bike_intakes', function (Blueprint $table) {
            $table->id();
            $table->string('intake_number')->unique();
            $table->foreignId('bike_id')->constrained();
            $table->text('complaint');
            $table->unsignedInteger('previous_mileage')->nullable();
            $table->unsignedInteger('current_mileage')->nullable();
            $table->string('fuel_level')->nullable();
            $table->text('condition_notes')->nullable();
            $table->text('accessories')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->decimal('advance_payment', 14, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('is_service')->default(false);
            $table->unsignedInteger('next_service_km')->nullable();
            $table->date('next_service_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('job_cards', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('bike_intake_id')->constrained();
            $table->foreignId('main_technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->string('status')->default('Received')->index();
            $table->string('priority')->default('Normal');
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->decimal('parts_total', 14, 2)->default(0);
            $table->decimal('service_total', 14, 2)->default(0);
            $table->decimal('labor_total', 14, 2)->default(0);
            $table->decimal('washing_total', 14, 2)->default(0);
            $table->decimal('other_total', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->text('internal_notes')->nullable();
            $table->text('customer_notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('job_card_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('job_card_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->boolean('is_main')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('brand')->nullable();
            $table->string('model_compatibility')->nullable();
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('wholesale_price', 14, 2)->nullable();
            $table->decimal('minimum_stock', 12, 2)->default(0);
            $table->string('unit')->default('pcs');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('store_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->unique(['store_id', 'product_id']);
            $table->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('type');
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->nullableMorphs('reference');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('job_card_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->boolean('out_of_stock')->default(false);
            $table->timestamps();
        });
        Schema::create('job_card_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('service');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('job_card_id')->unique()->constrained();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('due_amount', 14, 2)->default(0);
            $table->string('status')->default('Unpaid');
            $table->string('public_token', 64)->unique();
            $table->timestamp('link_expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->string('method');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->dateTime('transaction_date')->index();
            $table->string('type');
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->decimal('amount', 16, 2);
            $table->nullableMorphs('reference');
            $table->string('category')->nullable();
            $table->text('description');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('edit_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date');
            $table->string('category');
            $table->foreignId('account_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method');
            $table->string('vendor')->nullable();
            $table->text('note')->nullable();
            $table->string('attachment')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('drawings', function (Blueprint $table) {
            $table->id();
            $table->date('drawing_date');
            $table->string('person_name');
            $table->string('drawing_type');
            $table->foreignId('account_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts');
            $table->foreignId('to_account_id')->constrained('accounts');
            $table->decimal('amount', 16, 2);
            $table->dateTime('transfer_date');
            $table->string('reference_number')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->date('closing_date');
            $table->decimal('opening_balance', 16, 2);
            $table->decimal('income', 16, 2)->default(0);
            $table->decimal('expense', 16, 2)->default(0);
            $table->decimal('closing_balance', 16, 2);
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamps();
            $table->unique(['account_id', 'closing_date']);
        });

        Schema::create('technician_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained();
            $table->date('date');
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();
            $table->decimal('working_hours', 5, 2)->default(0);
            $table->decimal('ot_hours', 5, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('status');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['technician_id', 'date']);
        });
        Schema::create('technician_salary_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('basic_salary', 14, 2);
            $table->decimal('ot_amount', 14, 2)->default(0);
            $table->decimal('commission', 14, 2)->default(0);
            $table->decimal('advances', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('epf_employee', 14, 2)->default(0);
            $table->decimal('epf_company', 14, 2)->default(0);
            $table->decimal('etf_company', 14, 2)->default(0);
            $table->decimal('net_payable', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('status')->default('Draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['technician_id', 'period_start', 'period_end'], 'tech_salary_period_unique');
        });
        Schema::create('service_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bike_id')->constrained();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('due_km')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event')->unique();
            $table->string('name');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->text('message');
            $table->string('status')->default('Queued');
            $table->nullableMorphs('reference');
            $table->text('api_response')->nullable();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('text');
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('role_id'));
        foreach (['audit_logs', 'settings', 'sms_logs', 'sms_templates', 'service_reminders', 'technician_salary_sheets', 'technician_attendance', 'daily_closings', 'account_transfers', 'drawings', 'expenses', 'account_transactions', 'payments', 'invoices', 'accounts', 'job_card_services', 'job_card_parts', 'stock_movements', 'store_stocks', 'products', 'suppliers', 'product_categories', 'stores', 'job_card_technicians', 'job_card_status_logs', 'job_cards', 'bike_intakes', 'bikes', 'technicians', 'customers', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
