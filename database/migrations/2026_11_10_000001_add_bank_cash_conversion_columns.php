<?php

use App\Models\Account;
use App\Models\JobAdvance;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Preserve closed historical receipts; new bank receipts convert Main share via Spare Parts opening.
            $table->boolean('spare_parts_to_bank')->default(false);
        });
        Schema::table('job_advances', function (Blueprint $table) {
            $table->boolean('spare_parts_to_bank')->default(false);
        });
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('converted_from_method', 40)->nullable()->index();
        });

        $this->convertOpenUnclosedSessions();
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropIndex(['converted_from_method']);
            $table->dropColumn('converted_from_method');
        });
        Schema::table('job_advances', function (Blueprint $table) {
            $table->dropColumn('spare_parts_to_bank');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('spare_parts_to_bank');
        });
    }

    private function convertOpenUnclosedSessions(): void
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

        $paymentMorph = (new Payment)->getMorphClass();
        $advanceMorph = (new JobAdvance)->getMorphClass();

        DB::transaction(function () use ($sessions, $paymentMorph, $advanceMorph) {
            foreach ($sessions as $session) {
                $shopType = $session->shop_type ?? 'workshop';
                $date = Carbon::parse($session->session_date)->toDateString();
                $main = DB::table('accounts')->where('code', Account::systemCode('main', $shopType))->first();
                $spare = DB::table('accounts')->where('code', Account::systemCode('spare_parts', $shopType))->first();
                $bank = DB::table('accounts')->where('code', Account::systemCode('bank', $shopType))->first();

                if (! $main || ! $spare || ! $bank) {
                    continue;
                }

                $bankEntries = DB::table('account_transactions')
                    ->where('account_id', $bank->id)
                    ->where('status', 'active')
                    ->where('transaction_type', 'income')
                    ->whereDate('transaction_date', $date)
                    ->whereIn('reference_type', [$paymentMorph, $advanceMorph])
                    ->where('payment_method', '!=', 'Cash')
                    ->orderBy('id')
                    ->get();

                $touchedAccounts = [];

                foreach ($bankEntries as $bankEntry) {
                    $isPayment = $bankEntry->reference_type === $paymentMorph;
                    $sourceTable = $isPayment ? 'payments' : 'job_advances';
                    $source = DB::table($sourceTable)->where('id', $bankEntry->reference_id)->first();
                    if (! $source || $source->status !== 'Completed' || $source->method === 'Cash' || $source->spare_parts_to_bank) {
                        continue;
                    }

                    $method = $source->method;
                    $mainRows = DB::table('account_transactions')
                        ->where('account_id', $main->id)
                        ->where('status', 'active')
                        ->where('transaction_type', 'income')
                        ->where('reference_type', $bankEntry->reference_type)
                        ->where('reference_id', $bankEntry->reference_id)
                        ->where('payment_method', '!=', 'Cash')
                        ->get();

                    $mainAmount = round((float) $mainRows->sum('credit'), 2);
                    foreach ($mainRows as $mainRow) {
                        $prefix = $mainRow->note ? $mainRow->note.' · ' : '';
                        DB::table('account_transactions')->where('id', $mainRow->id)->update([
                            'payment_method' => 'Cash',
                            'converted_from_method' => $method,
                            'note' => $prefix."Converted from {$method} · Main share taken from Spare Parts opening balance",
                            'updated_at' => now(),
                        ]);
                        $touchedAccounts[$main->id] = $main->opening_balance;
                    }

                    $spareDeleted = DB::table('account_transactions')
                        ->where('account_id', $spare->id)
                        ->where('status', 'active')
                        ->where('transaction_type', 'income')
                        ->where('reference_type', $bankEntry->reference_type)
                        ->where('reference_id', $bankEntry->reference_id)
                        ->where('payment_method', '!=', 'Cash')
                        ->delete();
                    if ($spareDeleted > 0) {
                        $touchedAccounts[$spare->id] = $spare->opening_balance;
                    }

                    if ($mainAmount > 0) {
                        $refLabel = $isPayment
                            ? (DB::table('invoices')->where('id', $source->invoice_id)->value('number') ?? $source->number)
                            : $source->number;

                        DB::table('account_transactions')->insert([
                            'transaction_no' => 'TRX-'.strtoupper(Str::random(8)),
                            'transaction_date' => $bankEntry->transaction_date,
                            'account_type' => 'spare_parts',
                            'account_id' => $spare->id,
                            'transaction_type' => 'transfer_out',
                            'debit' => $mainAmount,
                            'credit' => 0,
                            'amount' => $mainAmount,
                            'running_balance' => 0,
                            'payment_method' => $method,
                            'converted_from_method' => $method,
                            'category' => 'bank_to_main_cash',
                            'funding_source' => 'spare_parts',
                            'reference_type' => $bankEntry->reference_type,
                            'reference_id' => $bankEntry->reference_id,
                            'description' => "Main cash from {$method} for {$refLabel}",
                            'note' => 'Converted Rs. '.number_format($mainAmount, 2)." from {$method} to Main cashier cash · Source: Spare Parts Account — opening balance",
                            'status' => 'active',
                            'created_by' => $bankEntry->created_by,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $touchedAccounts[$spare->id] = $spare->opening_balance;
                    }

                    DB::table($sourceTable)->where('id', $source->id)->update([
                        'spare_parts_to_bank' => true,
                        'updated_at' => now(),
                    ]);
                }

                foreach ($touchedAccounts as $accountId => $opening) {
                    $balance = (int) round((float) $opening * 100);
                    $rows = DB::table('account_transactions')
                        ->where('account_id', $accountId)
                        ->whereIn('status', ['active', 'reversed'])
                        ->orderBy('transaction_date')
                        ->orderBy('id')
                        ->get(['id', 'credit', 'debit']);

                    foreach ($rows as $row) {
                        $balance += (int) round((float) $row->credit * 100) - (int) round((float) $row->debit * 100);
                        DB::table('account_transactions')->where('id', $row->id)->update([
                            'running_balance' => $balance / 100,
                        ]);
                    }
                }
            }
        });
    }
};

