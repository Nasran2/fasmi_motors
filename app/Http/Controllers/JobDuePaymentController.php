<?php

namespace App\Http\Controllers;

use App\Models\JobCard;
use App\Services\CustomerReceiptService;
use App\Services\SettlementService;
use App\Support\PreviousCustomerBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobDuePaymentController extends Controller
{
    public function __invoke(Request $request, JobCard $job): RedirectResponse
    {
        $job->load('intake.bike.customer', 'invoice');
        $allowedIds = PreviousCustomerBalance::forJob($job)->pluck('invoice_id');
        $invoice = $job->invoice;
        if ($invoice && $invoice->status !== 'Cancelled' && $invoice->invoice_type !== 'Quotation' && (float) $invoice->due_amount > 0) {
            $allowedIds->push($invoice->id);
        }
        $data = $request->validate([
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', Rule::in($allowedIds->all())],
        ]);
        $rows = app(SettlementService::class)->rows($request->all());
        app(CustomerReceiptService::class)->receive($job->intake->bike->customer, $rows, $data['paid_at'], invoiceIds: $data['invoice_ids']);

        return back()->with('success', 'Payment saved against the selected bills. Cheques remain on hold until cleared.');
    }
}
