<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $sessions = DB::table('cashier_sessions')->where('status', 'open')->whereNull('closed_at')->lockForUpdate()->get();
            foreach ($sessions as $session) {
                $main = DB::table('accounts')->where('code', Account::systemCode('main', $session->shop_type ?? 'workshop'))->first();
                if (! $main) {
                    continue;
                }

                $opened = DB::table('cashier_session_events')->where('cashier_session_id', $session->id)
                    ->whereIn('action', ['opened', 'reopened'])->latest('id')->first();
                $closed = DB::table('cashier_session_events')->where('cashier_session_id', $session->id)
                    ->where('action', 'closed')->latest('id')->first();
                $countedIds = json_decode($closed?->details ?? '{}', true)['closing_snapshot']['closing_transaction_ids'] ?? [];
                $openingSnapshot = json_decode($opened?->details ?? '{}', true)['opening_context']['snapshot'] ?? [];
                $hasPriorConversions = (float) ($openingSnapshot['bank_converted_main']['main'] ?? 0) !== 0.0;

                // Route current-cycle entries only, including a payment posted in the same second as opening
                // when the opening snapshot did not already contain any converted Main cash.
                DB::table('account_transactions')
                    ->where('account_id', $main->id)
                    ->whereDate('transaction_date', Carbon::parse($session->session_date)->toDateString())
                    ->where('status', 'active')
                    ->whereIn('transaction_type', ['income', 'adjustment_increase', 'adjustment_decrease'])
                    ->whereNull('funding_source')
                    ->whereNotNull('converted_from_method')
                    ->where('created_at', $hasPriorConversions ? '>' : '>=', $opened?->created_at ?? $session->opened_at)
                    ->whereNotIn('id', $countedIds)
                    ->update(['funding_source' => 'main_cashier', 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Keep cash routing recorded by running and completed cashier sessions.
    }
};
