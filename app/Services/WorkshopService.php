<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StoreStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkshopService
{
    public function ledger(Account $account, string $type, float $amount, string $description, ?Model $reference = null, ?string $category = null): AccountTransaction
    {
        $accountType = match ($account->code) {
            'SPARE' => 'spare_parts',
            'BANK' => 'bank',
            default => 'main',
        };

        return app(AccountService::class)->recordTransaction(
            $accountType,
            $type,
            $amount,
            $description,
            $reference,
            null,
            null,
            $account->id,
            now(),
            $category,
        );
    }

    public function invoice(JobCard $job, float $paid, string $method, ?int $accountId = null): Invoice
    {
        return DB::transaction(function () use ($job, $paid, $method, $accountId) {
            if ($job->invoice) {
                throw ValidationException::withMessages(['invoice' => 'This job card is already billed.']);
            }
            $total = $job->total;
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the invoice total.']);
            }
            $invoice = $job->invoice()->create([
                'number' => 'INV-'.now()->format('ymd').'-'.Str::upper(Str::random(10)),
                'invoice_type' => 'Job Card Bill',
                'customer_id' => $job->intake->bike->customer_id,
                'bike_id' => $job->intake->bike_id,
                'subtotal' => $total + (float) $job->discount - (float) $job->tax, 'discount' => $job->discount, 'tax' => $job->tax,
                'parts_amount' => $job->parts_total, 'service_amount' => $job->service_total, 'labor_amount' => $job->labor_total,
                'washing_amount' => $job->washing_total, 'other_amount' => $job->other_total, 'payment_method' => $method,
                'total' => $total, 'paid_amount' => $paid, 'due_amount' => $total - $paid,
                'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'), 'due_date' => $paid < $total ? today()->addDays(30) : null,
                'public_token' => Str::random(48), 'link_expires_at' => now()->addDays(90), 'created_by' => auth()->id(),
            ]);
            $payment = null;
            if ($paid > 0) {
                $payment = $invoice->payments()->create(['number' => 'RCPT-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'account_id' => $accountId, 'amount' => $paid, 'balance_after' => $invoice->due_amount, 'method' => $method, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
            }
            $partsExpected = (float) $job->parts_total;
            $serviceExpected = max(0, $total - $partsExpected);

            $servicePaid = min($paid, $serviceExpected);
            $partsPaid = max(0, $paid - $servicePaid);

            $accountService = app(AccountService::class);

            if ($partsPaid > 0) {
                $accountService->recordTransaction('spare_parts', 'income', $partsPaid, "Parts income for {$invoice->number}", $payment, $method, null, $accountId);
            }
            if ($servicePaid > 0) {
                $accountService->recordTransaction('main', 'income', $servicePaid, "Workshop income for {$invoice->number}", $payment, $method, null, $accountId);
            }
            if ($paid > 0) {
                if (! in_array($method, ['Cash'])) {
                    $accountService->recordTransaction('bank', 'income', $paid, "Bank receipt for {$invoice->number}", $payment, $method, null, $accountId);
                }
            }
            $job->update(['status' => 'Billed']);
            app(ServiceReminderService::class)->createForJob($job);

            return $invoice;
        });
    }

    public function adjustStock(int $storeId, int $productId, float $quantity, string $type, ?Model $reference = null, ?string $note = null, ?int $stockBatchId = null, float $costPrice = 0, float $sellingPrice = 0): StoreStock
    {
        return DB::transaction(function () use ($storeId, $productId, $quantity, $type, $reference, $note, $stockBatchId, $costPrice, $sellingPrice) {
            $stock = StoreStock::query()->lockForUpdate()->firstOrCreate(['store_id' => $storeId, 'product_id' => $productId], ['quantity' => 0]);
            $new = (float) $stock->quantity + $quantity;
            if ($new < 0 && SettingValue::bool('negative_stock', false) === false) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient stock.']);
            }
            $stock->update(['quantity' => $new]);
            DB::table('stock_movements')->insert(['store_id' => $storeId, 'product_id' => $productId, 'stock_batch_id' => $stockBatchId, 'type' => $type, 'quantity' => $quantity, 'balance_after' => $new, 'cost_price' => $costPrice, 'selling_price' => $sellingPrice,
                'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(), 'note' => $note, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            if ($stockBatchId) {
                $batch = StockBatch::lockForUpdate()->find($stockBatchId);
                if ($batch) {
                    $newBatchQuantity = (float) $batch->available_qty + $quantity;
                    if ($newBatchQuantity < 0 && SettingValue::bool('negative_stock', false) === false) {
                        throw ValidationException::withMessages(['quantity' => 'Insufficient stock in the selected batch.']);
                    }
                    $batch->update(['available_qty' => $newBatchQuantity]);
                }
            }

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
