<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $sparePartsAccount = DB::table('accounts')->where('type', 'spare_parts')->first();

            if ($sparePartsAccount) {
                // Route Spare Parts bank-to-main transfer deductions to spare_parts_cashier
                // so the bank transfer Main share is minused from the Spare Parts cashier cash,
                // not from the opening balance.
                DB::table('account_transactions')
                    ->where('account_id', $sparePartsAccount->id)
                    ->where('category', 'bank_to_main_cash')
                    ->update([
                        'funding_source' => 'spare_parts_cashier',
                        'updated_at' => now(),
                    ]);
            }

            // Ensure Main Account converted bank shares stay outside drawer cash
            DB::table('account_transactions')
                ->where('funding_source', 'main_cashier')
                ->whereNotNull('converted_from_method')
                ->update([
                    'funding_source' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $sparePartsAccount = DB::table('accounts')->where('type', 'spare_parts')->first();

            if ($sparePartsAccount) {
                DB::table('account_transactions')
                    ->where('account_id', $sparePartsAccount->id)
                    ->where('category', 'bank_to_main_cash')
                    ->update([
                        'funding_source' => 'spare_parts',
                        'updated_at' => now(),
                    ]);
            }
        });
    }
};
