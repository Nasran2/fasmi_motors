<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('receipt_group_id')->nullable()->index();
            $table->foreignId('source_job_card_id')->nullable()->constrained('job_cards');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_job_card_id');
            $table->dropColumn('receipt_group_id');
        });
    }
};
