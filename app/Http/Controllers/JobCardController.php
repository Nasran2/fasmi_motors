<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\JobCard;
use App\Models\JobCardPart;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Services\Sms\TextitSmsService;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $job = JobCard::create($v + ['number' => 'JOB-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'status' => $r->main_technician_id ? 'Assigned' : 'Received', 'created_by' => auth()->id(), 'internal_notes' => $r->internal_notes]);
        DB::table('job_card_status_logs')->insert(['job_card_id' => $job->id, 'new_status' => $job->status, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

        // Send SMS to Customer & Technician immediately
        $job->load('intake.bike.customer', 'technician');
        $customer = $job->intake?->bike?->customer;
        $smsService = app(TextitSmsService::class);

        if ($customer && $customer->phone) {
            $smsService->send($customer->phone, "Fasmir Motors: Job Card {$job->number} has been created for your bike {$job->intake->bike->number}. Status: {$job->status}.", [
                'module' => 'Job Card',
                'message_type' => 'Customer Alert',
                'recipient_name' => $customer->name,
                'recipient_type' => 'customer',
                'reference_no' => $job->number,
                'related_type' => $job->getMorphClass(),
                'related_id' => $job->id,
                'sent_by' => auth()->id(),
            ]);
        }

        if ($job->main_technician_id && $job->technician && $job->technician->phone) {
            $smsService->send($job->technician->phone, "Dear {$job->technician->name}, Job Card {$job->number} has been assigned to you for bike {$job->intake->bike->number}. Complaint: ".($job->intake->complaint ?? 'N/A').". Priority: {$job->priority}.", [
                'module' => 'Job Card',
                'message_type' => 'Technician Alert',
                'recipient_name' => $job->technician->name,
                'recipient_type' => 'technician',
                'reference_no' => $job->number,
                'related_type' => $job->getMorphClass(),
                'related_id' => $job->id,
                'sent_by' => auth()->id(),
            ]);
        }

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

        return view('jobs.show', [
            'job' => $job->load('intake.bike.customer', 'technician', 'parts.product.stocks', 'services', 'invoice.payments'),
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
        $isCurrentLocked = in_array($job->status, ['Billed', 'Completed', 'Delivered']);

        if ($isCurrentLocked) {
            $v = $r->validate([
                'status' => 'required|in:Billed,Completed,Delivered',
                'change_reason' => 'required|string|max:500',
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
                'tax' => 'nullable|numeric|min:0',
                'show_washing_on_bill' => 'nullable|boolean',
                'show_labor_on_bill' => 'nullable|boolean',
                'service_name' => 'nullable|string|max:50',
                'other_income_name' => 'nullable|string|max:50',
            ]);
            
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
    }

    public function addPart(Request $r, JobCard $job, WorkshopService $service)
    {
        $v = $r->validate(['product_id' => 'required|exists:products,id', 'store_id' => 'required|exists:stores,id', 'quantity' => 'required|numeric|min:0.01', 'unit_price' => 'required|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'stock_batch_id' => 'nullable|exists:stock_batches,id']);
        $stock = StoreStock::where(['product_id' => $v['product_id'], 'store_id' => $v['store_id']])->value('quantity') ?? 0;
        $out = $stock < $v['quantity'];
        $total = $v['quantity'] * $v['unit_price'] - ($v['discount'] ?? 0);

        $batchId = $v['stock_batch_id'] ?? null;
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

        $part = $job->parts()->create($v + ['total' => $total, 'out_of_stock' => $out, 'stock_batch_id' => $batchId, 'cost_price' => $costPrice, 'selling_price' => $v['unit_price'], 'profit' => $profit]);
        $service->adjustStock($v['store_id'], $v['product_id'], -$v['quantity'], 'job_issue', $part, "Issued to {$job->number}", $batchId, $costPrice, $v['unit_price']);
        $job->update(['parts_total' => $job->parts()->sum('total')]);
        $this->syncInvoice($job);

        $newStock = $stock - $v['quantity'];
        return back()->with('success', $out ? "Part added with an out-of-stock warning (Current stock: {$newStock})." : "Part issued from stock (Current stock: {$newStock}).");
    }

    public function updatePartPrice(Request $r, JobCard $job, JobCardPart $part)
    {
        $v = $r->validate([
            'unit_price' => 'required|numeric|min:0',
        ]);

        $price = (float) $v['unit_price'];
        $qty = (float) $part->quantity;
        $discount = (float) $part->discount;

        $total = $qty * $price - $discount;
        $profit = $total - ($qty * (float) $part->cost_price);

        $part->update([
            'unit_price' => $price,
            'selling_price' => $price,
            'total' => $total,
            'profit' => $profit,
        ]);

        $job->update([
            'parts_total' => $job->parts()->sum('total'),
        ]);
        $this->syncInvoice($job);

        return back()->with('success', 'Part selling price updated successfully.');
    }

    public function invoice(Request $r, JobCard $job, WorkshopService $service)
    {
        $v = $r->validate(['paid_amount' => 'required|numeric|min:0', 'payment_method' => 'required|in:Cash,Card,Bank Transfer,Cheque,QR,Credit/Due']);
        $invoice = $service->invoice($job, (float) $v['paid_amount'], $v['payment_method']);

        // Send Billing SMS to customer
        $invoice->loadMissing('customer');
        if ($invoice->customer && $invoice->customer->phone) {
            $smsService = app(TextitSmsService::class);
            $smsService->send($invoice->customer->phone, "Fasmir Motors bill {$invoice->number}: Rs. ".number_format((float) $invoice->total, 2).'. '.route('invoices.public', $invoice->public_token), [
                'module' => 'Billing',
                'message_type' => 'Bill SMS',
                'recipient_name' => $invoice->customer->name,
                'recipient_type' => 'customer',
                'reference_no' => $invoice->number,
                'related_type' => $invoice->getMorphClass(),
                'related_id' => $invoice->id,
            ]);
            $invoice->update(['sms_sent_at' => now()]);
        }

        return redirect()->route('invoices.public', $invoice->public_token)->with('success', 'Invoice generated.');
    }

    public function unlock(Request $r, JobCard $job)
    {
        $v = $r->validate([
            'status' => 'required|in:In Progress,Diagnosis,Waiting for Parts,On Hold,Repair Ready,Ready for Delivery',
            'reason' => 'required|string|max:500',
        ]);

        $old = $job->status;
        $job->update(['status' => $v['status']]);

        DB::table('job_card_status_logs')->insert([
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
            $invoice = $job->invoice;
            $total = $job->total;
            $subtotal = (float) $job->parts_total + (float) $job->service_total + (float) $job->labor_total + (float) $job->washing_total + (float) $job->other_total;
            $paid = (float) $invoice->paid_amount;
            $due = max(0, $total - $paid);

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
        }
    }

    private static function statuses(): array
    {
        return ['Received', 'Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
    }
}
