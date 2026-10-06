<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('job_card_id')->nullable()->constrained('job_cards')->nullOnDelete();
        });
        Schema::table('job_card_parts', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->string('display_name', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('is_external')->default(false);
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_card_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
            $table->dropColumn(['display_name', 'note', 'is_external']);
        });
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('job_card_id'));
        // Existing outer-part history has no stock product or store; retain nullable foreign keys.
    }
};
