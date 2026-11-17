<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Revert open session main bank converted shares back to funding_source = null
            // so they do not deduct from or affect the physical cashier cash drawer.
            DB::table('account_transactions')
                ->where('funding_source', 'main_cashier')
                ->whereNotNull('converted_from_method')
                ->update([
                    'funding_source' => null,
                    'updated_at' => now(),
                ]);

            // Update notes on spare parts and main transactions
            $spareRows = DB::table('account_transactions')
                ->where('category', 'bank_to_main_cash')
                ->where('note', 'like', '%cashier drawer%')
                ->get();

            foreach ($spareRows as $row) {
                $note = str_replace(
                    'to the cashier drawer for the Main share of',
                    'to Main Account for',
                    $row->note
                );
                DB::table('account_transactions')->where('id', $row->id)->update([
                    'note' => $note,
                    'updated_at' => now(),
                ]);
            }

            $mainRows = DB::table('account_transactions')
                ->whereNotNull('converted_from_method')
                ->where('note', 'like', '%Cash moved from Spare Parts opening to the cashier drawer%')
                ->get();

            foreach ($mainRows as $row) {
                $note = str_replace(
                    'Cash moved from Spare Parts opening to the cashier drawer',
                    'Taken from Spare Parts opening balance',
                    $row->note
                );
                DB::table('account_transactions')->where('id', $row->id)->update([
                    'note' => $note,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
    }
};
