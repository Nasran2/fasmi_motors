<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\JobCardPart;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobCardController extends Controller
{
    public function index(Request $r)
    {
        $q = JobCard::with('intake.bike.customer', 'technician', 'invoice')->latest();
        if ($r->status) {
            if ($r->status === 'Completed') {
                $q->whereIn('status', ['Completed', 'Billed', 'Delivered']);
            } else {
                $q->where('status', $r->status);
            }
        }

        return view('jobs.index', [
            'jobs' => $q->paginate(15),
            'statuses' => self::statuses(),
            'accounts' => Account::where('active', 1)->get(),
        ]);
    }

    public function create(Request $r)
    {
        return view('jobs.create', ['intakes' => BikeIntake::doesntHave('jobCard')->with('bike.customer')->latest()->get(), 'selected' => $r->intake, 'technicians' => Technician::where('active', 1)->get()]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['bike_intake_id' => 'required|exists:bike_intakes,id|unique:job_cards', 'main_technician_id' => 'nullable|exists:technicians,id', 'priority' => 'required|in:Low,Normal,High,Urgent', 'estimated_amount' => 'nullable|numeric|min:0']);
        $v['estimated_amount'] = $v['estimated_amount'] ?? 0;
        $job = DB::transaction(function () use ($v, $r) {
            $job = JobCard::create($v + ['number' => 'JOB-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'status' => $r->main_technician_id ? 'Assigned' : 'Received', 'created_by' => auth()->id(), 'internal_notes' => $r->internal_notes]);
            DB::table('job_card_status_logs')->insert(['job_card_id' => $job->id, 'new_status' => $job->status, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            return $job;
        });

        return redirect()->route('jobs.show', $job)->with('success', 'Job card created.');
    }

    public function show(JobCard $job)
    {
        $products = Product::with([
            'stocks',
            'stockBatches' => fn ($query) => $query->where('status', 'active')->where('available_qty', '>', 0),
        ])->where('active', 1)->get();

        $productBatchOptions = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'price' => (float) $product->selling_price,
            'stocks' => $product->stocks->mapWithKeys(fn ($stock) => [$stock->store_id => (float) $stock->quantity]),
            'minimum_stock' => (float) $product->minimum_stock,
            'batches' => $product->stockBatches->map(fn ($batch) => [
                'id' => $batch->id,
                'store_id' => $batch->store_id,
                'batch_no' => $batch->batch_no,
                'cost' => (float) $batch->cost_price,
                'selling' => (float) $batch->selling_price,
                'qty' => (float) $batch->available_qty,
            ])->values(),
        ])->values();

        $statusLogs = DB::table('job_card_status_logs')
            ->leftJoin('users', 'job_card_status_logs.changed_by', '=', 'users.id')
            ->where('job_card_id', $job->id)
            ->select('job_card_status_logs.*', 'users.name as changer_name')
            ->orderBy('job_card_status_logs.created_at', 'desc')
            ->get();

        $job->load('intake.bike.customer', 'technician', 'parts.product.stocks', 'services', 'invoice.payments');
        $oldDue = Invoice::where('customer_id', $job->intake->bike->customer_id)
            ->where('status', '!=', 'Cancelled')
            ->where('due_amount', '>', 0)
            ->when($job->invoice, fn ($query) => $query->whereKeyNot($job->invoice->id))
            ->sum('due_amount');

        return view('jobs.show', [
            'job' => $job,
            'oldDue' => $oldDue,
            'technicians' => Technician::where('active', 1)->get(),
            'products' => $products,
            'productBatchOptions' => $productBatchOptions,
            'stores' => Store::where('active', 1)->get(),
            'statuses' => self::statuses(),
            'accounts' => Account::where('active', 1)->get(),
            'statusLogs' => $statusLogs,
        ]);
    }

    public function update(Request $r, JobCard $job)
    {
        return DB::transaction(function () use ($r, $job) {
        $job = JobCard::lockForUpdate()->findOrFail($job->id);
        $isCurrentLocked = in_array($job->status, ['Billed', 'Completed', 'Delivered']);

        if ($isCurrentLocked) {
            $v = $r->validate([
                'status' => 'required|in:'.implode(',', self::statuses()),
                'change_reason' => 'nullable|string|max:500',
            ]);
        } else {
            $v = $r->validate([
                'status' => 'required|in:'.implode(',', self::statuses()),
                'main_technician_id' => 'nullable|required_if:status,Assigned|exists:technicians,id',
                'change_reason' => 'nullable|max:500',
                'parts_total' => 'nullable|numeric|min:0',
                'service_total' => 'nullable|numeric|min:0',
                'labor_total' => 'nullable|numeric|min:0',
                'washing_total' => 'nullable|numeric|min:0',
                'other_total' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_type' => 'nullable|in:fixed,percentage',
                'discount_value' => 'required_with:discount_type|nullable|numeric|min:0|max:999999999999.99',
                'tax' => 'nullable|numeric|min:0',
                'show_washing_on_bill' => 'nullable|boolean',
                'show_labor_on_bill' => 'nullable|boolean',
                'service_name' => 'nullable|string|max:50',
                'other_income_name' => 'nullable|string|max:50',
            ]);
            
            if (($v['discount_type'] ?? null) === 'percentage' && (float) $v['discount_value'] > 100) {
                throw ValidationException::withMessages(['discount_value' => 'Percentage discount must be between 0 and 100.']);
            }
            if (isset($v['discount_type'])) {
                unset($v['discount']);
            } elseif (isset($v['discount'])) {
                $v['discount_type'] = 'fixed';
                $v['discount_value'] = $v['discount'];
            } else {
                unset($v['discount_type'], $v['discount_value'], $v['discount']);
            }

            // Checkboxes are only sent if checked, handle defaults
            $v['show_washing_on_bill'] = $r->has('show_washing_on_bill');
            $v['show_labor_on_bill'] = $r->has('show_labor_on_bill');
        }

        $old = $job->status;
        $changeReason = $v['change_reason'] ?? null;
        unset($v['change_reason']);
        $job->update($v);

        if ($old !== $job->status) {
            DB::table('job_card_status_logs')->insert([
                'job_card_id' => $job->id,
                'old_status' => $old,
                'new_status' => $job->status,
                'note' => $changeReason,
                'changed_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->syncInvoice($job);

        return back()->with('success', 'Job card updated.');
        });
    }

    public function addPart(Request $r, JobCard $job, WorkshopService $service)
{
    return DB::transaction(function () use ($r, $job, $service) {
    $job = JobCard::lockForUpdate()->findOrFail($job->id);
    $v = $r->validate(['product_id' => 'required|exists:products,id', 'store_id' => 'required|exists:stores,id', 'quantity' => 'required|numeric|min:0.01', 'unit_price' => 'required|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'stock_batch_id' => 'nullable|exists:stock_batches,id']);
    $stock = StoreStock::where(['product_id' => $v['product_id'], 'store_id' => $v['store_id']])->value('quantity') ?? 0;
    $out = $stock < $v['quantity'];
    $total = $v['quantity'] * $v['unit_price'] - ($v['discount'] ?? 0);

    $batchId = $v['stock_batch_id'] ?? null;
    $batch = null;
    $costPrice = 0;
    if (! $batchId) {
        $batch = StockBatch::where('product_id', $v['product_id'])->where('store_id', $v['store_id'])->where('status', 'active')->where('available_qty', '>', 0)->orderBy('purchase_date', 'asc')->first();
        if ($batch) {
            $batchId = $batch->id;
        }
    } else {
        $batch = StockBatch::find($batchId);
    }

    if ($batch) {
        $costPrice = (float) $batch->cost_price;
    } else {
        $product = Product::find($v['product_id']);
        $costPrice = (float) $product->cost_price;
    }

    $profit = $total - ((float) $v['quantity'] * $costPrice);

    $v['stock_batch_id'] = $batchId;
    $part = $job->parts()->create($v + ['total' => $total, 'out_of_stock' => $out, 'cost_price' => $costPrice, 'selling_price' => $v['unit_price'], 'profit' => $profit]);
    $service->adjustStock($v['store_id'], $v['product_id'], -$v['quantity'], 'job_issue', $part, "Issued to {$job->number}", $batchId, $costPrice, $v['unit_price']);
    $job->update(['parts_total' => $job->parts()->sum('total')]);
    $this->syncInvoice($job);

    return redirect(route('jobs.show', $job).'#spare-parts')->with('success', 'Part added successfully.');
    });
}

public function addMultipleParts(\Illuminate\Http\Request $r, \App\Models\JobCard $job, \App\Services\WorkshopService $service)
{
    return DB::transaction(function () use ($r, $job, $service) {
    $job = JobCard::lockForUpdate()->findOrFail($job->id);
    $r->validate([
        'parts' => 'required|array|min:1',
        'parts.*.product_id' => 'required|exists:products,id',
        'parts.*.store_id' => 'required|exists:stores,id',
        'parts.*.quantity' => 'required|numeric|min:0.01',
        'parts.*.unit_price' => 'required|numeric|min:0',
        'parts.*.discount' => 'nullable|numeric|min:0',
        'parts.*.stock_batch_id' => 'nullable|exists:stock_batches,id'
    ]);

    foreach ($r->parts as $v) {
        $stock = \App\Models\StoreStock::where(['product_id' => $v['product_id'], 'store_id' => $v['store_id']])->value('quantity') ?? 0;
        $out = $stock < $v['quantity'];
        $total = $v['quantity'] * $v['unit_price'] - ($v['discount'] ?? 0);

        $batchId = $v['stock_batch_id'] ?? null;
        $costPrice = 0;
        $batch = null;

        if (! $batchId) {
            $batch = \App\Models\StockBatch::where('product_id', $v['product_id'])->where('store_id', $v['store_id'])->where('status', 'active')->where('available_qty', '>', 0)->orderBy('purchase_date', 'asc')->first();
            if ($batch) {
                $batchId = $batch->id;
            }
        } else {
            $batch = \App\Models\StockBatch::find($batchId);
        }

        if ($batch) {
            $costPrice = (float) $batch->cost_price;
        } else {
            $product = \App\Models\Product::find($v['product_id']);
            $costPrice = (float) $product->cost_price;
        }

        $profit = $total - ((float) $v['quantity'] * $costPrice);

        $part = $job->parts()->create([
            'product_id' => $v['product_id'],
            'store_id' => $v['store_id'],
            'quantity' => $v['quantity'],
            'unit_price' => $v['unit_price'],
            'discount' => $v['discount'] ?? 0,
            'total' => $total,
            'out_of_stock' => $out,
            'stock_batch_id' => $batchId,
            'cost_price' => $costPrice,
            'selling_price' => $v['unit_price'],
            'profit' => $profit
        ]);

        $service->adjustStock($v['store_id'], $v['product_id'], -$v['quantity'], 'job_issue', $part, "Issued to {$job->number}", $batchId, $costPrice, $v['unit_price']);
    }

    $job->update(['parts_total' => $job->parts()->sum('total')]);
    $this->syncInvoice($job);

    return back()->with('success', 'Parts added successfully.');
    });
}
    public function updatePart(\Illuminate\Http\Request $r, \App\Models\JobCard $job, $partId, \App\Services\WorkshopService $service)
    {
        return DB::transaction(function () use ($r, $job, $partId, $service) {
        $job = JobCard::lockForUpdate()->findOrFail($job->id);
        $v = $r->validate([
            'unit_price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.01'
        ]);

        $part = $job->parts()->findOrFail($partId);
        
        $oldQty = $part->quantity;
        $newQty = $v['quantity'];
        $qtyDiff = $newQty - $oldQty;

        // If quantity increased, check stock
        if ($qtyDiff > 0) {
            $stock = \App\Models\StoreStock::where(['product_id' => $part->product_id, 'store_id' => $part->store_id])->value('quantity') ?? 0;
            $out = $stock < $qtyDiff;
        } else {
            $out = false; // Because we are returning stock
        }

        $total = $newQty * $v['unit_price'] - $part->discount;
        $profit = $total - ($newQty * $part->cost_price);

        $part->update([
            'quantity' => $newQty,
            'unit_price' => $v['unit_price'],
            'total' => $total,
            'profit' => $profit,
            'out_of_stock' => $out || $part->out_of_stock
        ]);

        if ($qtyDiff != 0) {
            $service->adjustStock($part->store_id, $part->product_id, -$qtyDiff, 'job_issue', $part, "Qty adjusted for {$job->number}", $part->stock_batch_id, $part->cost_price, $v['unit_price']);
        }

        $job->update(['parts_total' => $job->parts()->sum('total')]);
        $this->syncInvoice($job);

        return back()->with('success', 'Part updated successfully.');
        });
    }

    public function deletePart(\App\Models\JobCard $job, $partId, \App\Services\WorkshopService $service)
    {
        return DB::transaction(function () use ($job, $partId, $service) {
        $job = JobCard::lockForUpdate()->findOrFail($job->id);
        $part = $job->parts()->findOrFail($partId);
        $service->adjustStock($part->store_id, $part->product_id, $part->quantity, 'job_issue', $part, "Part deleted from {$job->number}", $part->stock_batch_id, $part->cost_price, $part->unit_price);
        $part->delete();
        $job->update(['parts_total' => $job->parts()->sum('total')]);
        $this->syncInvoice($job);
        return back()->with('success', 'Part deleted successfully.');
        });
    }
    public function invoice(Request $r, JobCard $job, WorkshopService $service)
    {
        $settlement = app(\App\Services\SettlementService::class);
        $input = $r->all();
        $input['account_id'] ??= \App\Models\Account::systemAccount('main')->id;
        $rows = $settlement->rows($input);
        $invoice = $service->invoice($job, $settlement->total($rows), count($rows) > 1 ? 'Mixed' : ($rows[0]['method'] ?? 'Credit/Due'), null, $rows);

        return redirect()->route('jobs.show', $job)->with([
            'success' => 'Invoice generated.',
            'print_invoice' => $invoice->public_token,
            'invoice_id' => $invoice->id
        ]);
    }

    public function unlock(Request $r, JobCard $job)
    {
        $v = $r->validate([
            'status' => 'required|in:In Progress,Diagnosis,Waiting for Parts,On Hold,Repair Ready,Ready for Delivery',
            'reason' => 'required|string|max:500',
        ]);

        $old = $job->status;
        $job->update(['status' => $v['status']]);

        \Illuminate\Support\Facades\DB::table('job_card_status_logs')->insert([
            'job_card_id' => $job->id,
            'old_status' => $old,
            'new_status' => $v['status'],
            'note' => '[Unlocked] '.$v['reason'],
            'changed_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncInvoice($job);

        return back()->with('success', 'Job card unlocked and status set to '.$v['status']);
    }
private function syncInvoice(JobCard $job)
    {
        $job->loadMissing('invoice');
        if ($job->invoice) {
            $invoice = \App\Models\Invoice::lockForUpdate()->findOrFail($job->invoice->id);
            if ($invoice->status === 'Cancelled') {
                return;
            }
            $total = $job->total;
            $subtotal = (float) $job->parts_total + (float) $job->service_total + (float) $job->labor_total + (float) $job->washing_total + (float) $job->other_total;
            $paid = (float) $invoice->paid_amount;
            if ($total < $paid + (float) $invoice->hold_amount) {
                throw ValidationException::withMessages(['total' => 'Cancel or refund payments before reducing the invoice below its settled amount.']);
            }
            $due = max(0, $total - $paid - (float) $invoice->hold_amount);

            $invoice->update([
                'subtotal' => $subtotal,
                'discount' => $job->discount,
                'tax' => $job->tax,
                'parts_amount' => $job->parts_total,
                'service_amount' => $job->service_total,
                'labor_amount' => $job->labor_total,
                'washing_amount' => $job->washing_total,
                'other_amount' => $job->other_total,
                'total' => $total,
                'due_amount' => $due,
                'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'),
            ]);
            app(\App\Services\SettlementService::class)->saveStatus($invoice);
            app(\App\Services\AccountService::class)->reallocateInvoiceReceipts($invoice);
        }
    }

    private static function statuses(): array
    {
        return ['Received', 'Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
    }
}
