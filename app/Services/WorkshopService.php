<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\StoreStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkshopService
{
    public function ledger(Account $account, string $type, float $amount, string $description, ?Model $reference = null, ?string $category = null): AccountTransaction
    {
        $outgoing = in_array($type, ['expense', 'drawing', 'transfer_out', 'refund'], true);

        return $account->transactions()->create([
            'transaction_date' => now(), 'type' => $type, 'debit' => $outgoing ? $amount : 0,
            'credit' => $outgoing ? 0 : $amount, 'amount' => $amount, 'category' => $category,
            'description' => $description, 'created_by' => auth()->id(),
            'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
        ]);
    }

    public function invoice(JobCard $job, float $paid, string $method): Invoice
    {
        return DB::transaction(function () use ($job, $paid, $method) {
            if ($job->invoice) {
                throw ValidationException::withMessages(['invoice' => 'This job card is already billed.']);
            }
            $total = $job->total;
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the invoice total.']);
            }
            $invoice = $job->invoice()->create([
                'number' => 'INV-'.now()->format('ymd').'-'.str_pad((string) (Invoice::max('id') + 1), 4, '0', STR_PAD_LEFT),
                'customer_id' => $job->intake->bike->customer_id,
                'subtotal' => $total + (float) $job->discount - (float) $job->tax, 'discount' => $job->discount, 'tax' => $job->tax,
                'total' => $total, 'paid_amount' => $paid, 'due_amount' => $total - $paid,
                'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'), 'due_date' => $paid < $total ? today()->addDays(30) : null,
                'public_token' => Str::random(48), 'created_by' => auth()->id(),
            ]);
            if ($paid > 0) {
                $invoice->payments()->create(['amount' => $paid, 'method' => $method, 'paid_at' => now(), 'created_by' => auth()->id()]);
            }
            $partsPaid = $total > 0 ? min((float) $job->parts_total, $paid * ((float) $job->parts_total / $total)) : 0;
            $servicePaid = max(0, $paid - $partsPaid);
            if ($partsPaid > 0) {
                $this->ledger(Account::where('code', 'SPARE')->firstOrFail(), 'income', $partsPaid, "Parts income for {$invoice->number}", $invoice, 'spare_parts');
            }
            if ($servicePaid > 0) {
                $this->ledger(Account::where('code', 'MAIN')->firstOrFail(), 'income', $servicePaid, "Workshop income for {$invoice->number}", $invoice, 'service');
            }
            if ($paid > 0 && in_array($method, ['Card', 'Bank Transfer', 'Cheque', 'QR'], true)) {
                $this->ledger(Account::where('code', 'BANK')->firstOrFail(), 'payment', $paid, "Bank receipt for {$invoice->number}", $invoice, 'payment_location');
            }
            $job->update(['status' => 'Billed']);

            return $invoice;
        });
    }

    public function adjustStock(int $storeId, int $productId, float $quantity, string $type, ?Model $reference = null, ?string $note = null): StoreStock
    {
        return DB::transaction(function () use ($storeId, $productId, $quantity, $type, $reference, $note) {
            $stock = StoreStock::query()->lockForUpdate()->firstOrCreate(['store_id' => $storeId, 'product_id' => $productId], ['quantity' => 0]);
            $new = (float) $stock->quantity + $quantity;
            if ($new < 0 && SettingValue::bool('negative_stock', false) === false) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient stock.']);
            }
            $stock->update(['quantity' => $new]);
            DB::table('stock_movements')->insert(['store_id' => $storeId, 'product_id' => $productId, 'type' => $type, 'quantity' => $quantity, 'balance_after' => $new,
                'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(), 'note' => $note, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            return $stock;
        });
    }
}

final class SettingValue
{
    public static function bool(string $key, bool $default): bool
    {
        return filter_var(Setting::valueFor($key, $default), FILTER_VALIDATE_BOOL);
    }
}
