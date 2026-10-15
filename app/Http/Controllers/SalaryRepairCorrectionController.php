<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\SalaryRepairCorrectionService;
use Illuminate\Http\Request;

class SalaryRepairCorrectionController extends Controller
{
    public function edit(Payment $payment, SalaryRepairCorrectionService $corrections)
    {
        return view('technicians.repair-deduction-edit', $corrections->context($payment));
    }

    public function update(Request $request, Payment $payment, SalaryRepairCorrectionService $corrections)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'expected_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $corrections->correct($payment, (float) $data['amount'], (float) $data['expected_amount'], $data['reason']);

        $jobId = $payment->bikeDeduction->job_card_id ?? $payment->invoice->job_card_id;

        return ($jobId && $request->user()->canAccess('technician.bike_deductions') ? redirect()->route('technicians.bike-repairs.show', $jobId) : redirect()->route('technicians.salaries.slip', $payment->salary_sheet_id))
            ->with('success', 'Salary deduction corrected. The repair bill and paysheet balances are updated; cash already paid is unchanged.');
    }
}
