<?php
$file = 'app/Http/Controllers/JobCardController.php';
$content = file_get_contents($file);

$pos = strpos($content, 'public function addPart(');
if ($pos === false) exit("Not found");

$endPos = strpos($content, 'private function syncInvoice(');
if ($endPos === false) exit("syncInvoice not found");

$correctMethods = <<<'METHODS'
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

        return back()->with('success', 'Part added successfully.');
    }

    public function addMultipleParts(\Illuminate\Http\Request $r, \App\Models\JobCard $job, \App\Services\WorkshopService $service)
    {
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
    }

    METHODS;

$newContent = substr($content, 0, $pos) . $correctMethods . substr($content, $endPos);
file_put_contents($file, $newContent);
echo "Fixed!";
