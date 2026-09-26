<?php
$file = 'app/Http/Controllers/JobCardController.php';
$content = file_get_contents($file);

$endPos = strpos($content, 'private function syncInvoice(');

$missingMethods = <<<'METHODS'
    public function updatePart(\Illuminate\Http\Request $r, \App\Models\JobCard $job, $partId, \App\Services\WorkshopService $service)
    {
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
    }

    public function deletePart(\App\Models\JobCard $job, $partId, \App\Services\WorkshopService $service)
    {
        $part = $job->parts()->findOrFail($partId);
        $service->adjustStock($part->store_id, $part->product_id, $part->quantity, 'job_issue', $part, "Part deleted from {$job->number}", $part->stock_batch_id, $part->cost_price, $part->unit_price);
        $part->delete();
        $job->update(['parts_total' => $job->parts()->sum('total')]);
        $this->syncInvoice($job);
        return back()->with('success', 'Part deleted successfully.');
    }

METHODS;

$newContent = substr($content, 0, $endPos) . $missingMethods . substr($content, $endPos);
file_put_contents($file, $newContent);
echo "Controller fixed!";
