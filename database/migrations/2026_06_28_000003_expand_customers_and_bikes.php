<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('alternative_phone')->nullable()->after('phone');
            $table->string('customer_type')->default('Normal Customer')->after('email');
            $table->boolean('active')->default(true)->after('customer_type');
        });

        Schema::table('bikes', function (Blueprint $table) {
            $table->unsignedInteger('previous_mileage')->nullable()->after('last_mileage');
            $table->string('fuel_type')->nullable()->after('previous_mileage');
            $table->text('notes')->nullable()->after('fuel_type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('job_card_id')->constrained()->nullOnDelete();
            $table->date('due_date')->nullable()->after('due_amount');
        });

        Schema::create('bike_ownership_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bike_id')->constrained();
            $table->foreignId('old_customer_id')->constrained('customers');
            $table->foreignId('new_customer_id')->constrained('customers');
            $table->date('changed_at');
            $table->string('due_handling');
            $table->decimal('due_amount', 14, 2)->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bike_ownership_changes');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('due_date');
        });
        Schema::table('bikes', function (Blueprint $table) {
            $table->dropColumn(['previous_mileage', 'fuel_type', 'notes']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['alternative_phone', 'customer_type', 'active']);
        });
    }
};
