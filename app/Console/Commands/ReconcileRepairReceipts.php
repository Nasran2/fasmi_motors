<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileRepairReceipts extends Command
{
    protected $signature = 'accounts:reconcile-receipts {--shop=workshop} {--apply : Append allocation corrections}';

    protected $description = 'Compare cash repair receipts with invoice components; optionally append auditable corrections';

    public function handle(AccountService $service): int
    {
        $shop = $this->option('shop');
        if (! in_array($shop, ['workshop', 'helmet'], true)) {
            $this->error('Unknown shop.');

            return self::FAILURE;
        }
        $previous = session('shop_mode', 'workshop');
        session(['shop_mode' => $shop]);
        try {
            $rows = [];
            $invoices = Invoice::withoutGlobalScopes()->where('shop_type', $shop)->where('status', '!=', 'Cancelled')->get();
            foreach ($invoices as $invoice) {
                $ids = $invoice->payments()->where('status', 'Completed')->whereNotIn('method', ['Salary Deduction', 'Manual Clearance'])->pluck('id');
                $received = (float) $invoice->payments()->whereIn('id', $ids)->sum('amount');
                $allocation = ['spare_parts' => 0, 'washing' => 0, 'main' => 0];
                foreach ($service->orderedReceipts($invoice) as $payment) {
                    foreach ($service->paymentReceiptAllocation($invoice, $payment) as $type => $cents) {
                        $allocation[$type] += $cents;
                    }
                }
                $changed = false;
                foreach ($allocation as $type => $cents) {
                    $expected = $cents / 100;
                    $entries = AccountTransaction::posted()->where('reference_type', (new Payment)->getMorphClass())->whereIn('reference_id', $ids)->where('account_type', $type);
                    $actual = round((float) (clone $entries)->sum('credit') - (float) (clone $entries)->sum('debit'), 2);
                    if (abs($expected - $actual) >= .005) {
                        $rows[] = [$invoice->number, $type, number_format($received, 2), number_format($actual, 2), number_format($expected, 2), number_format($expected - $actual, 2)];
                        $changed = true;
                    }
                }
                if (! $changed) {
                    continue;
                }
                if ($this->option('apply')) {
                    DB::transaction(function () use ($invoice, $service) {
                        $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
                        $service->reallocateInvoiceReceipts($invoice);
                    });
                }
            }
            $this->table(['Invoice', 'Account', 'Received', 'Posted', 'Expected', 'Correction'], $rows);
            $this->info(count($rows).' account allocation differences'.($this->option('apply') ? ' corrected.' : ' found. Dry run; no records changed.'));
            foreach (Account::withoutGlobalScopes()->where('shop_type', $shop)->get() as $account) {
                $this->line($account->code.': '.number_format($account->balance, 2));
            }

            return self::SUCCESS;
        } finally {
            session(['shop_mode' => $previous]);
        }
    }
}
