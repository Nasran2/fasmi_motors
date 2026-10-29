<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Models\JobCard;
use App\Models\JobCardPart;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobPartService
{
    public function ensureEditable(JobCard $job): void
    {
        if (in_array($job->status, ['Completed', 'Billed', 'Delivered', 'Cancelled'], true)) {
            throw ValidationException::withMessages(['parts' => 'Reopen this job card before changing spare parts.']);
        }
    }

    public function addStock(JobCard $job, array $data): JobCardPart
    {
        $this->ensureEditable($job);
        $product = Product::where('active', true)->lockForUpdate()->findOrFail($data['product_id']);
        $store = Store::where('active', true)->lockForUpdate()->findOrFail($data['store_id']);
        $batch = ! empty($data['stock_batch_id'])
            ? StockBatch::lockForUpdate()->findOrFail($data['stock_batch_id'])
            : StockBatch::where('product_id', $product->id)->where('store_id', $store->id)->where('status', 'active')->where('available_qty', '>', 0)->orderBy('purchase_date')->orderBy('id')->lockForUpdate()->first();
        if ($batch && ($batch->product_id !== $product->id || $batch->store_id !== $store->id || $batch->status !== 'active')) {
            throw ValidationException::withMessages(['stock_batch_id' => 'Select a batch belonging to this product and store.']);
        }
        $cost = (float) ($batch?->cost_price ?? $product->cost_price);
        $total = $this->total($data);
        $available = (float) (StoreStock::where('product_id', $product->id)->where('store_id', $store->id)->value('quantity') ?? 0);
        $part = $job->parts()->create([
            'product_id' => $product->id, 'store_id' => $store->id, 'stock_batch_id' => $batch?->id,
            'display_name' => $product->name, 'note' => $this->note($data), 'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'], 'selling_price' => $data['unit_price'], 'discount' => $data['discount'] ?? 0,
            'cost_price' => $cost, 'total' => $total, 'profit' => round($total - $this->totalCost($cost, (float) $data['quantity']), 2),
            'out_of_stock' => $available < $data['quantity'],
        ]);
        app(WorkshopService::class)->adjustStock($store->id, $product->id, -(float) $data['quantity'], 'job_issue', $part, "Issued to {$job->number}", $batch?->id, $cost, (float) $data['unit_price']);

        return $part;
    }

    public function addOuter(JobCard $job, array $data): JobCardPart
    {
        $this->ensureEditable($job);
        $total = $this->total($data);
        $part = $job->parts()->create([
            'product_id' => null, 'store_id' => null, 'is_external' => true,
            'display_name' => trim($data['display_name']), 'note' => $this->note($data), 'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'], 'selling_price' => $data['unit_price'], 'cost_price' => $data['cost_price'],
            'discount' => 0, 'total' => $total, 'profit' => round($total - $this->totalCost((float) $data['cost_price'], (float) $data['quantity']), 2), 'out_of_stock' => false,
        ]);
        $this->syncOuterExpense($job, $part, (int) $data['account_id']);

        return $part;
    }

    public function update(JobCard $job, JobCardPart $part, array $data): void
    {
        $this->ensureEditable($job);
        $quantityChange = round((float) $data['quantity'] - (float) $part->quantity, 2);
        $cost = (float) ($data['cost_price'] ?? $part->cost_price);
        $total = $this->total($data + ['discount' => $part->discount]);
        $part->fill([
            'display_name' => isset($data['display_name']) ? trim($data['display_name']) : $part->name,
            'note' => array_key_exists('note', $data) ? $this->note($data) : $part->note,
            'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'selling_price' => $data['unit_price'],
            'cost_price' => $cost, 'discount' => $data['discount'] ?? $part->discount,
            'total' => $total, 'profit' => round($total - $this->totalCost($cost, (float) $data['quantity']), 2),
        ]);
        $part->save();
        if ($part->is_external) {
            if (! auth()->user()->canAccess('expense.add')) {
                abort(403);
            }
            $this->syncOuterExpense($job, $part, (int) ($data['account_id'] ?? $part->expense?->account_id));
        } elseif ($quantityChange != 0) {
            app(WorkshopService::class)->adjustStock($part->store_id, $part->product_id, -$quantityChange, 'job_issue', $part, "Qty adjusted for {$job->number}", $part->stock_batch_id, $cost, (float) $data['unit_price']);
            $available = (float) (StoreStock::where('product_id', $part->product_id)->where('store_id', $part->store_id)->value('quantity') ?? 0);
            $part->update(['out_of_stock' => $available < 0]);
        }
    }

    public function delete(JobCard $job, JobCardPart $part): void
    {
        $this->ensureEditable($job);
        if ($part->is_external) {
            if (! auth()->user()->canAccess('expense.add')) {
                abort(403);
            }
            $expense = $part->expense()->lockForUpdate()->firstOrFail();
            $this->reverseExpense($expense, "Outer product removed from {$job->number}");
            $expense->update(['status' => 'Cancelled', 'cancel_reason' => "Outer product removed from {$job->number}", 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        } else {
            app(WorkshopService::class)->adjustStock($part->store_id, $part->product_id, (float) $part->quantity, 'job_return', $part, "Part removed from {$job->number}", $part->stock_batch_id, (float) $part->cost_price, (float) $part->unit_price);
        }
        $part->delete();
    }

    private function total(array $data): float
    {
        $total = round((float) $data['quantity'] * (float) $data['unit_price'] - (float) ($data['discount'] ?? 0), 2);
        if ($total > 999999999999.99 || ! is_finite($total)) {
            throw ValidationException::withMessages(['unit_price' => 'The line amount is too large.']);
        }
        if ($total < 0) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the line amount.']);
        }

        return $total;
    }

    private function note(array $data): ?string
    {
        return trim((string) ($data['note'] ?? '')) ?: null;
    }

    private function totalCost(float $cost, float $quantity): float
    {
        $amount = round($quantity * $cost, 2);
        if ($amount > 999999999999.99 || ! is_finite($amount)) {
            throw ValidationException::withMessages(['cost_price' => 'The total cost is too large.']);
        }

        return $amount;
    }

    private function syncOuterExpense(JobCard $job, JobCardPart $part, int $accountId): void
    {
        $account = Account::where('active', true)->lockForUpdate()->find($accountId);
        if (! $account) {
            throw ValidationException::withMessages(['account_id' => 'Select an active payment account from this shop.']);
        }
        $amount = $this->totalCost((float) $part->cost_price, (float) $part->quantity);
        $job->loadMissing('intake.bike');
        $reference = $job->number.' / '.($job->bike?->number ?? $job->number);
        $description = 'Outer product: '.$part->bill_name.' · '.$reference;
        $method = $account->code === Account::systemCode('bank') || $account->type === 'bank' ? 'Bank Transfer' : 'Cash';
        $expense = $part->expense()->lockForUpdate()->first();
        $needsPosting = ! $expense || $expense->status !== 'Active' || (float) $expense->amount !== $amount || (int) $expense->account_id !== $accountId;
        if ($expense && $needsPosting) {
            $this->reverseExpense($expense, "Outer product updated on {$job->number}");
        }
        $values = ['job_card_id' => $job->id, 'category' => 'Outer Products', 'description' => $description, 'reference_number' => $reference, 'account_id' => $accountId, 'amount' => $amount, 'payment_method' => $method, 'note' => $part->note, 'status' => 'Active', 'cancel_reason' => null, 'cancelled_at' => null, 'cancelled_by' => null];
        if ($expense) {
            $expense->update($values);
        } else {
            FinancialCategory::firstOrCreate(['module' => 'expense', 'name' => 'Outer Products'], ['active' => true]);
            $expense = Expense::create($values + ['number' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'expense_date' => today(), 'created_by' => auth()->id()]);
            $part->update(['expense_id' => $expense->id]);
        }
        if ($needsPosting && $amount > 0) {
            $type = match ($account->code) {
                Account::systemCode('main') => 'main', Account::systemCode('spare_parts') => 'spare_parts', Account::systemCode('bank') => 'bank', default => 'cash',
            };
            app(AccountService::class)->recordTransaction($type, 'expense', $amount, $description, $expense, $method, $part->note, $accountId, now(), 'Outer Products');
        }
    }

    private function reverseExpense(Expense $expense, string $reason): void
    {
        AccountTransaction::where('reference_type', $expense->getMorphClass())->where('reference_id', $expense->id)->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()->each(fn ($entry) => app(AccountService::class)->reverseTransaction($entry, $reason));
    }
}
