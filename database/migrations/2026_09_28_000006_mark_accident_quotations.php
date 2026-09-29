<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_accident_quotation')->default(false)->index();
        });

        // Accident quotations created before this column always included a bike.
        DB::table('invoices')
            ->where('invoice_type', 'Quotation')
            ->whereNotNull('bike_id')
            ->update(['is_accident_quotation' => true]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('is_accident_quotation');
        });
    }
};
