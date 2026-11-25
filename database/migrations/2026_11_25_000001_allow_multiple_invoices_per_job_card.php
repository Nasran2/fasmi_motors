<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['job_card_id']);
            $table->dropUnique('invoices_job_card_id_unique');
            $table->foreign('job_card_id')->references('id')->on('job_cards')->nullOnDelete();
            $table->index('job_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['job_card_id']);
            $table->dropIndex(['job_card_id']);
            $table->foreign('job_card_id')->references('id')->on('job_cards')->nullOnDelete();
            $table->unique('job_card_id');
        });
    }
};
