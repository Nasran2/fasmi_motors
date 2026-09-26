<?php
$file = 'app/Http/Controllers/JobCardController.php';
$content = file_get_contents($file);

$endPos = strpos($content, 'private function syncInvoice(');

$missingMethods = <<<'METHODS'
    public function invoice(Request $r, JobCard $job, WorkshopService $service)
    {
        $v = $r->validate(['paid_amount' => 'required|numeric|min:0', 'payment_method' => 'required|in:Cash,Card,Bank Transfer,Cheque,QR,Credit/Due']);
        $invoice = $service->invoice($job, (float) $v['paid_amount'], $v['payment_method']);

        // Send Billing SMS to customer
        $invoice->loadMissing('customer');
        if ($invoice->customer && $invoice->customer->phone) {
            $smsService = app(\App\Services\TextitSmsService::class);
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

METHODS;

$newContent = substr($content, 0, $endPos) . $missingMethods . substr($content, $endPos);
file_put_contents($file, $newContent);
echo "Restored methods!";
