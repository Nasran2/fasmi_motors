<?php

namespace App\Http\Controllers;

use App\Models\JobCard;
use App\Models\Payment;
use App\Services\PaymentCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JobPaymentController extends Controller
{
    public function __invoke(Request $request, JobCard $job, Payment $payment, PaymentCorrectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:500',
            'include_previous_balance' => 'sometimes|required|boolean',
            'method' => 'required|in:Cash,Card,Bank Transfer,Cheque,QR',
            'amount' => 'required|numeric|decimal:0,2|min:0.01|max:999999999999.99',
            'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today',
            'account_id' => 'required_without:receiving_account|nullable|integer',
            'receiving_account' => 'nullable|string',
            'cheque_date' => 'nullable|required_if:method,Cheque|date_format:Y-m-d',
            'cheque_number' => 'nullable|required_if:method,Cheque|string|max:80',
            'bank_name' => 'nullable|required_if:method,Cheque|string|max:120',
        ]);
        $service->correct($job, $payment, $data);

        return redirect()->route('jobs.show', $job)->with('success', 'Payment corrected. The reason and before/after details are recorded in Status History & Change Logs.');
    }
}
