<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_capitals', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('shop_type')->default('workshop')->index();
            $table->date('capital_date')->index();
            $table->string('owner_name', 150);
            $table->foreignId('account_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30);
            $table->string('reference_number', 100)->nullable();
            $table->text('note')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_capitals');
    }
};
