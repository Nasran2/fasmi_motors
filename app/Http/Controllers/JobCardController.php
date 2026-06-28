<?php

namespace App\Http\Controllers;

use App\Models\BikeIntake;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobCardController extends Controller
{
    public function index(Request $r)
    {
        $q = JobCard::with('intake.bike.customer', 'technician', 'invoice')->latest();
        if ($r->status) {
            $q->where('status', $r->status);
        }

        return view('jobs.index', ['jobs' => $q->paginate(15), 'statuses' => self::statuses()]);
    }

    public function create(Request $r)
    {
        return view('jobs.create', ['intakes' => BikeIntake::doesntHave('jobCard')->with('bike.customer')->latest()->get(), 'selected' => $r->intake, 'technicians' => Technician::where('active', 1)->get()]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['bike_intake_id' => 'required|exists:bike_intakes,id|unique:job_cards', 'main_technician_id' => 'nullable|exists:technicians,id', 'priority' => 'required|in:Low,Normal,High,Urgent', 'estimated_amount' => 'nullable|numeric|min:0']);
        $job = JobCard::create($v + ['number' => 'JOB-'.now()->format('ymd').'-'.str_pad((string) (JobCard::max('id') + 1), 4, '0', STR_PAD_LEFT), 'status' => $r->main_technician_id ? 'Assigned' : 'Received', 'created_by' => auth()->id(), 'internal_notes' => $r->internal_notes]);
        DB::table('job_card_status_logs')->insert(['job_card_id' => $job->id, 'new_status' => $job->status, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

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
            'batches' => $product->stockBatches->map(fn ($batch) => [
                'id' => $batch->id,
                'store_id' => $batch->store_id,
                'batch_no' => $batch->batch_no,
                'cost' => (float) $batch->cost_price,
                'selling' => (float) $batch->selling_price,
                'qty' => (float) $batch->available_qty,
            ])->values(),
        ])->values();

        return view('jobs.show', [
            'job' => $job->load('intake.bike.customer', 'technician', 'parts.product', 'services', 'invoice.payments'),
            'technicians' => Technician::where('active', 1)->get(),
            'products' => $products,
            'productBatchOptions' => $productBatchOptions,
            'stores' => Store::where('active', 1)->get(),
            'statuses' => self::statuses(),
        ]);
    }

    public function update(Request $r, JobCard $job)
    {
        $v = $r->validate(['status' => 'required|in:'.implode(',', self::statuses()), 'main_technician_id' => 'nullable|required_if:status,Assigned|exists:technicians,id', 'change_reason' => 'nullable|max:500', 'parts_total' => 'nullable|numeric|min:0', 'service_total' => 'nullable|numeric|min:0', 'labor_total' => 'nullable|numeric|min:0', 'washing_total' => 'nullable|numeric|min:0', 'other_total' => 'nullable|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'tax' => 'nullable|numeric|min:0']);
        $old = $job->status;
        $job->update($v);
        if ($old !== $job->status) {
            DB::table('job_card_status_logs')->insert(['job_card_id' => $job->id, 'old_status' => $old, 'new_status' => $job->status, 'note' => $r->change_reason, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        }

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
        if (! $out) {
            $service->adjustStock($v['store_id'], $v['product_id'], -$v['quantity'], 'job_issue', $part, "Issued to {$job->number}", $batchId, $costPrice, $v['unit_price']);
        }$job->update(['parts_total' => $job->parts()->sum('total')]);

        return back()->with('success', $out ? 'Part added with an out-of-stock warning.' : 'Part issued from stock.');
    }

    public function invoice(Request $r, JobCard $job, WorkshopService $service)
    {
        $v = $r->validate(['paid_amount' => 'required|numeric|min:0', 'payment_method' => 'required|in:Cash,Card,Bank Transfer,Cheque,QR,Credit/Due']);
        $invoice = $service->invoice($job, (float) $v['paid_amount'], $v['payment_method']);

        return redirect()->route('invoices.public', $invoice->public_token)->with('success', 'Invoice generated.');
    }

    private static function statuses(): array
    {
        return ['Received', 'Assigned', 'In Progress', 'Waiting for Parts', 'On Hold', 'Repair Ready', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
    }
}
