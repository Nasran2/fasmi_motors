<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->boolean('show_previous_balance_on_bill')->default(true)->change();
        });

        // Enable the new default for jobs awaiting billing; retain issued bill preferences.
        DB::table('job_cards')->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('invoices')->whereColumn('invoices.job_card_id', 'job_cards.id'))
            ->update(['show_previous_balance_on_bill' => true]);
    }

    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->boolean('show_previous_balance_on_bill')->default(false)->change();
        });
    }
};
