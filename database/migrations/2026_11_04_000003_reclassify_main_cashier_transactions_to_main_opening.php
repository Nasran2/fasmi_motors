<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Reclassify account_transactions from main_cashier to main (opening balance)
        DB::table('account_transactions')
            ->where('funding_source', 'main_cashier')
            ->update([
                'funding_source' => 'main',
            ]);

        // 2. Update transaction notes referencing Main Account Cashier
        DB::table('account_transactions')
            ->where('note', 'like', '%Main Account Cashier%')
            ->update([
                'note' => DB::raw("REPLACE(REPLACE(note, 'Main Account Cashier — today’s cash', 'Main Account — opening balance'), 'Main Account Cashier — today\'s cash', 'Main Account — opening balance')"),
            ]);

        // 3. Update expenses JSON funding allocations
        DB::table('expenses')
            ->where('funding_allocations', 'like', '%main_cashier%')
            ->get()
            ->each(function ($expense) {
                $allocations = json_decode($expense->funding_allocations, true);
                if (is_array($allocations)) {
                    $updated = array_map(function ($row) {
                        if (($row['source'] ?? null) === 'main_cashier') {
                            $row['source'] = 'main';
                        }

                        return $row;
                    }, $allocations);
                    DB::table('expenses')->where('id', $expense->id)->update([
                        'funding_allocations' => json_encode($updated),
                    ]);
                }
            });

        // 4. Update technician_advances JSON funding allocations
        DB::table('technician_advances')
            ->where('funding_allocations', 'like', '%main_cashier%')
            ->get()
            ->each(function ($advance) {
                $allocations = json_decode($advance->funding_allocations, true);
                if (is_array($allocations)) {
                    $updated = array_map(function ($row) {
                        if (($row['source'] ?? null) === 'main_cashier') {
                            $row['source'] = 'main';
                        }

                        return $row;
                    }, $allocations);
                    DB::table('technician_advances')->where('id', $advance->id)->update([
                        'funding_allocations' => json_encode($updated),
                    ]);
                }
            });

        // 5. Update supplier payments
        DB::table('supplier_payments')
            ->where('funding_source', 'main_cashier')
            ->update(['funding_source' => 'main']);
    }

    public function down(): void
    {
        // Irreversible data reclassification to maintain ledger integrity
    }
};

