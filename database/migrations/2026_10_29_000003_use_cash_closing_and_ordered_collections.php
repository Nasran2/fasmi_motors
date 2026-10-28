<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\CashPositionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->json('cash_opening_balances')->nullable();
            $table->json('cash_balances')->nullable();
        });
        $previous = session('shop_mode', 'workshop');
        try {
            foreach (['workshop', 'helmet'] as $shop) {
                session(['shop_mode' => $shop]);
                if (! Account::withoutGlobalScopes()->where('shop_type', $shop)->exists()) {
                    continue;
                }
                Account::ensureSystemAccounts($shop);
                $start = CashierSession::withoutGlobalScopes()->where('shop_type', $shop)->where('status', 'open')->min('session_date') ?? today()->toDateString();
                $paymentIds = AccountTransaction::posted()->where('reference_type', (new Payment)->getMorphClass())
                    ->where('transaction_type', 'income')->where('transaction_date', '>=', $start)->select('reference_id');
                Invoice::withoutGlobalScopes()->where('shop_type', $shop)->where('status', '!=', 'Cancelled')
                    ->whereHas('payments', fn ($query) => $query->whereIn('id', $paymentIds))->each(function ($invoice) use ($start) {
                        DB::transaction(function () use ($invoice, $start) {
                            app(CashierService::class)->lock();
                            $invoice = Invoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
                            app(AccountService::class)->reallocateInvoiceReceipts($invoice, $start);
                        });
                    });
                CashierSession::withoutGlobalScopes()->where('shop_type', $shop)->where('status', 'open')->each(function ($session) {
                    $positions = app(CashPositionService::class)->accounts($session->session_date->toDateString());
                    $opening = collect($positions)->map(fn ($account) => $account['opening'])->all();
                    $session->update(['cash_opening_balances' => $opening, 'opening_balance' => round(array_sum($opening), 2)]);
                });
            }
        } finally {
            session(['shop_mode' => $previous]);
        }
    }

    public function down(): void
    {
        Schema::table('cashier_sessions', fn (Blueprint $table) => $table->dropColumn(['cash_opening_balances', 'cash_balances']));
        // Allocation corrections remain auditable ledger entries.
    }
};
