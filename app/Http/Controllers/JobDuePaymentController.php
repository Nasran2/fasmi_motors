<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\JobCard;
use App\Services\JobPaymentAllocationService;
use App\Services\SettlementService;
use App\Support\PreviousCustomerBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'include_previous_balance' => ['sometimes', 'required', 'boolean'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', Rule::in($allowedIds->all())],
        ]);
        $rows = app(SettlementService::class)->rows($request->all());
        if (! ($data['include_previous_balance'] ?? true) && collect($data['invoice_ids'])->contains(fn ($id) => (int) $id !== $invoice?->id)) {
            throw ValidationException::withMessages(['invoice_ids' => 'Enable old balances to pay an earlier bill.']);
        }
        $primary = $invoice ?? Invoice::findOrFail($data['invoice_ids'][0]);
        app(JobPaymentAllocationService::class)->receive($job, $primary, $rows, $data['paid_at'], invoiceIds: $data['invoice_ids'], oldestFirst: true);

        return back()->with('success', 'Payment saved against the selected bills. Cheques remain on hold until cleared.');
    }
}
