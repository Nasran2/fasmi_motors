<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update account_transactions where transaction_date time is 00:00:00 to use the time from created_at
        DB::table('account_transactions')
            ->whereRaw("TIME(transaction_date) = '00:00:00' AND created_at IS NOT NULL")
            ->update([
                'transaction_date' => DB::raw("TIMESTAMP(DATE(transaction_date), TIME(created_at))"),
            ]);

        // Update payments where paid_at time is 00:00:00 to use the time from created_at
        DB::table('payments')
            ->whereRaw("TIME(paid_at) = '00:00:00' AND created_at IS NOT NULL")
            ->update([
                'paid_at' => DB::raw("TIMESTAMP(DATE(paid_at), TIME(created_at))"),
            ]);
    }

    public function down(): void
    {
        // Preserving realistic timestamps; down migration does not reintroduce 00:00:00
    }
};

