<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $closedSessionIds = DB::table('cashier_session_events')
            ->where('action', 'closed')
            ->pluck('cashier_session_id')
            ->all();

        $sessions = DB::table('cashier_sessions')
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->when($closedSessionIds, fn ($q) => $q->whereNotIn('id', $closedSessionIds))
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($sessions) {
            foreach ($sessions as $session) {
                $shopType = $session->shop_type ?? 'workshop';
                $date = Carbon::parse($session->session_date)->toDateString();
                $main = DB::table('accounts')->where('code', Account::systemCode('main', $shopType))->first();
                $spare = DB::table('accounts')->where('code', Account::systemCode('spare_parts', $shopType))->first();

                if ($main) {
                    $mainRows = DB::table('account_transactions')
                        ->where('account_id', $main->id)
                        ->where('status', 'active')
                        ->where('transaction_type', '!=', 'reversal')
                        ->whereDate('transaction_date', $date)
                        ->whereNull('funding_source')
                        ->whereNotNull('converted_from_method')
                        ->where('payment_method', 'Cash')
                        ->get();

                    foreach ($mainRows as $row) {
                        $method = $row->converted_from_method;
                        $note = $row->note ?: '';
                        $note = str_replace(
                            [
                                "Converted from {$method} · Main share taken from Spare Parts opening balance",
                                "Adjusted {$method} Main cash conversion",
                            ],
                            [
                                "Main share for {$method} · Taken from Spare Parts opening balance",
                                "Adjusted {$method} Main share from Spare Parts opening balance",
                            ],
                            $note
                        );

                        DB::table('account_transactions')->where('id', $row->id)->update([
                            'payment_method' => $method,
                            'note' => $note ?: "Main share for {$method} · Taken from Spare Parts opening balance",
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($spare) {
                    $spareRows = DB::table('account_transactions')
                        ->where('account_id', $spare->id)
                        ->where('status', 'active')
                        ->where('transaction_type', 'transfer_out')
                        ->where('category', 'bank_to_main_cash')
                        ->whereDate('transaction_date', $date)
                        ->whereNotNull('converted_from_method')
                        ->get();

                    foreach ($spareRows as $row) {
                        $method = $row->converted_from_method;
                        $amount = number_format((float) $row->debit, 2);
                        $note = (string) ($row->note ?? '');
                        if (str_contains($note, 'Main cashier cash')) {
                            $note = "Transferred Rs. {$amount} to Main Account for {$method} share · Source: Spare Parts Account — opening balance";
                            DB::table('account_transactions')->where('id', $row->id)->update([
                                'note' => $note,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Preserves ledger transactions safely on rollback.
    }
};

