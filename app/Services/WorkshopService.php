<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StoreStock;
use App\Support\InvoiceNumber;
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

    public function invoice(JobCard $job, float $paid, string $method, ?int $accountId = null, ?array $payments = null, ?int $partsTechnicianId = null, bool $includePrevious = true): Invoice
    {
        return DB::transaction(function () use ($job, $paid, $method, $accountId, $payments, $partsTechnicianId, $includePrevious) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            if ($job->invoice) {
                throw ValidationException::withMessages(['invoice' => 'This job card is already billed.']);
            }
            $total = $job->total;
            if ($paid < 0) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot be negative.']);
            }
            $invoice = $job->invoice()->create([
                'number' => InvoiceNumber::next('Job Card Bill'),
                'invoice_type' => 'Job Card Bill',
                'customer_id' => $job->customer_id,
                'bike_id' => $job->bike?->id,
                'subtotal' => $total + (float) $job->discount - (float) $job->tax, 'discount' => $job->discount, 'tax' => $job->tax,
                'parts_amount' => $job->parts_total, 'service_amount' => $job->service_total, 'labor_amount' => $job->labor_total,
                'washing_amount' => $job->washing_total, 'other_amount' => $job->other_total, 'payment_method' => $method,
                'total' => $total, 'paid_amount' => 0, 'due_amount' => $total,
                'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'), 'due_date' => $paid < $total ? today()->addDays(30) : null,
                'public_token' => Str::random(48), 'link_expires_at' => now()->addDays(90), 'created_by' => auth()->id(),
            ]);
            $settlement = app(SettlementService::class);
            $rows = $payments ?? $settlement->rows(['paid_amount' => $paid, 'payment_method' => $method, 'account_id' => $accountId ?? Account::systemAccount('main')->id]);
            $job->setRelation('invoice', $invoice);
            $available = $total + ($includePrevious ? \App\Support\PreviousCustomerBalance::total(\App\Support\PreviousCustomerBalance::forJob($job)) : 0);
            if ($settlement->total(array_filter($rows, fn ($row) => $row['method'] !== 'Cash')) > round($available, 2)) {
                throw ValidationException::withMessages(['paid_amount' => 'Non-cash payments cannot exceed the available bill balances.']);
            }
            app(JobPaymentAllocationService::class)->receive($job, $invoice, $rows, includePrevious: $includePrevious);
            $invoice->refresh();
            $invoice->payment_method = count($rows) > 1 ? 'Mixed' : $method;
            $settlement->saveStatus($invoice);
            $job->update(['status' => 'Billed']);
            app(ServiceReminderService::class)->createForJob($job);

            // Calculate Commission automatically if the technician is eligible
            if ($job->main_technician_id) {
                $technician = \App\Models\Technician::find($job->main_technician_id);
                if ($technician && $technician->commission_enabled) {
                    
                    // 1. Regular Commission (Labor / Total)
                    if ((float)$technician->commission_percentage > 0) {
                        $base = 0;
                        if (stripos($technician->commission_type, 'labor') !== false) {
                            $base = $job->labor_total;
                        } elseif (stripos($technician->commission_type, 'bill') !== false) {
                            $base = $job->total;
                        }
                        
                        if ($base > 0) {
                            \App\Models\TechnicianCommission::create([
                                'technician_id' => $technician->id,
                                'job_card_id' => $job->id,
                                'invoice_id' => $invoice->id,
                                'commission_type' => 'Job Card - ' . $technician->commission_type,
                                'percentage' => (float)$technician->commission_percentage,
                                'base_amount' => $base,
                                'amount' => round($base * ((float)$technician->commission_percentage / 100), 2),
                                'earned_date' => now()->toDateString(),
                                'status' => 'Pending',
                                'created_by' => auth()->id(),
                            ]);
                        }
                    }
                }
            }

            // 2. Parts Commission (Assigned to $partsTechnicianId if provided, otherwise main_technician_id)
            $partsTechId = $partsTechnicianId ?? $job->main_technician_id;
            if ($partsTechId) {
                $partsTechnician = \App\Models\Technician::find($partsTechId);
                if ($partsTechnician && $partsTechnician->commission_enabled && (float)$partsTechnician->parts_commission_percentage > 0) {
                    $baseParts = 0;
                    if (stripos($partsTechnician->commission_type, 'profit') !== false) {
                        $baseParts = \Illuminate\Support\Facades\DB::table('job_card_parts')->where('job_card_id', $job->id)->sum('profit');
                    } else {
                        $baseParts = (float) $job->parts_total;
                    }
                    
                    if ($baseParts > 0) {
                        \App\Models\TechnicianCommission::create([
                            'technician_id' => $partsTechnician->id,
                            'job_card_id' => $job->id,
                            'invoice_id' => $invoice->id,
                            'commission_type' => 'Job Card - Parts',
                            'percentage' => (float)$partsTechnician->parts_commission_percentage,
                            'base_amount' => $baseParts,
                            'amount' => round($baseParts * ((float)$partsTechnician->parts_commission_percentage / 100), 2),
                            'earned_date' => now()->toDateString(),
                            'status' => 'Pending',
                            'created_by' => auth()->id(),
                        ]);
                    }
                }
            }

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
                    if ($batch->product_id !== $productId || $batch->store_id !== $storeId || $batch->status !== 'active') {
                        throw ValidationException::withMessages(['stock_batch_id' => 'The selected batch does not belong to this product and store.']);
                    }
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
