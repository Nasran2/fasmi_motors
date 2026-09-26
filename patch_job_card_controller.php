<?php
$file = 'app/Http/Controllers/JobCardController.php';
$content = file_get_contents($file);

$newMethod = <<<'METHOD'
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

METHOD;

$content = preg_replace('/(public function addPart\(.*?\}\s*)\n/s', "$1\n$newMethod", $content, 1);
file_put_contents($file, $content);
