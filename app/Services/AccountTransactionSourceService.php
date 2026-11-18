<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\CommissionPayment;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\ExtraIncome;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\OwnerCapital;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Models\SupplierPayment;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Support\RoutePermission;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;

class AccountTransactionSourceService
{
    private array $references = [];

    public function url(AccountTransaction $transaction, User $user): ?string
    {
        $reference = $this->reference($transaction);
        $destination = $this->destination($reference);
        if ($destination && Route::has($destination[0]) && RoutePermission::allows($user, $destination[0], parameters: $destination[1])) {
            return route($destination[0], $destination[1]);
        }

        $invoice = $reference instanceof Payment ? $reference->invoice : ($reference instanceof Invoice ? $reference : null);
        if ($invoice?->jobCard && RoutePermission::allows($user, 'jobs.show')) {
            return route('jobs.show', $invoice->job_card_id);
        }

        return null;
    }

    public function details(AccountTransaction $transaction, User $user): array
    {
        $source = $this->reference($transaction);
        $invoice = $source instanceof Invoice ? $source : (($source instanceof Payment || $source instanceof SaleReturn) ? $source->invoice : null);
        $job = $source instanceof JobCard ? $source : ($source instanceof JobAdvance ? $source->jobCard : $invoice?->jobCard);
        $invoice ??= $job?->invoice;
        $bike = $job?->bike ?? $invoice?->bike;

        return [
            'invoice' => $invoice?->number,
            'invoice_url' => $invoice && RoutePermission::allows($user, 'sales.invoices.show') ? route('sales.invoices.show', $invoice) : null,
            'job' => $job?->number,
            'job_url' => $job && RoutePermission::allows($user, 'jobs.show') ? route('jobs.show', $job) : null,
            'bike' => $bike?->number,
            'bike_name' => trim(implode(' ', array_filter([$bike?->brand, $bike?->model]))),
            'number' => $source?->number,
            'url' => $this->url($transaction, $user) ?? route('cashier.transaction-source', $transaction),
        ];
    }

    private function reference(AccountTransaction $transaction)
    {
        $key = $transaction->reference_type.'|'.$transaction->reference_id;
        if (array_key_exists($key, $this->references)) {
            return $this->references[$key];
        }
        $type = Relation::getMorphedModel($transaction->reference_type ?? '') ?? $transaction->reference_type;
        // Legacy/free-text references must never be instantiated as PHP classes.
        if (! in_array($type, [Payment::class, Invoice::class, JobCard::class, JobAdvance::class, Expense::class, Drawing::class, OwnerCapital::class, ExtraIncome::class,
            Purchase::class, SupplierPayment::class, PurchaseReturn::class, SaleReturn::class, TechnicianSalarySheet::class,
            CashierSession::class, DailyClosing::class, CommissionPayment::class], true)) {
            return null;
        }
        $query = $type::query();
        if (in_array($type, [Expense::class, Drawing::class, OwnerCapital::class, ExtraIncome::class], true)) {
            $query->withTrashed();
        }

        return $this->references[$key] = $query->find($transaction->reference_id);
    }

    private function destination($source): ?array
    {
        if ($source instanceof OwnerCapital) {
            return ['owner-capital.show', ['capital' => $source->id]];
        }
        if ($source instanceof ExtraIncome) {
            return ['extra-money.show', ['extraIncome' => $source->id]];
        }
        if ($source instanceof Payment) {
            return $source->invoice ? ['sales.invoices.show', ['invoice' => $source->invoice_id]] : null;
        }
        if ($source instanceof Invoice) {
            return ['sales.invoices.show', ['invoice' => $source->id]];
        }
        if ($source instanceof JobAdvance) {
            return $source->jobCard ? ['jobs.show', ['job' => $source->job_card_id]] : null;
        }
        if ($source instanceof JobCard) {
            return ['jobs.show', ['job' => $source->id]];
        }
        if ($source instanceof Expense || $source instanceof Drawing) {
            if ($source->account_id && ! $source->account) {
                return null;
            }

            return $source instanceof Expense ? ['expenses.show', ['expense' => $source->id]] : ['drawings.show', ['drawing' => $source->id]];
        }
        if ($source instanceof Purchase) {
            return ['purchases.show', ['purchase' => $source->id]];
        }
        if ($source instanceof SupplierPayment || $source instanceof PurchaseReturn) {
            return $source->purchase ? ['purchases.show', ['purchase' => $source->purchase_id]] : null;
        }
        if ($source instanceof SaleReturn) {
            return $source->invoice ? ['sales.invoices.show', ['invoice' => $source->invoice_id]] : null;
        }
        if ($source instanceof TechnicianSalarySheet) {
            return ['technicians.salaries.show', ['sheet' => $source->id]];
        }
        if ($source instanceof CommissionPayment) {
            return ['technicians.commissions.show', ['technician' => $source->technician_id, 'period' => 'all']];
        }
        if ($source instanceof CashierSession) {
            return $source->shop_type === session('shop_mode', 'workshop') ? ['cashier.index', ['date' => $source->session_date->toDateString()]] : null;
        }
        if ($source instanceof DailyClosing) {
            return $source->shop_type === session('shop_mode', 'workshop') ? ['cashier.index', ['date' => $source->closing_date->toDateString()]] : null;
        }

        return null;
    }
}
