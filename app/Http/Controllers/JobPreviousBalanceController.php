<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\JobCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobPreviousBalanceController extends Controller
{
    public function __invoke(Request $request, JobCard $job): RedirectResponse
    {
        DB::transaction(function () use ($request, $job) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $job->load('intake.bike', 'invoice');
            if ($job->status === 'Cancelled' || $job->invoice?->status === 'Cancelled') {
                throw ValidationException::withMessages(['previous_balance_amount' => 'Previous balances cannot be added from a cancelled job or bill.']);
            }
            $latestDate = min(today()->toDateString(), $job->invoice?->created_at->toDateString() ?? today()->toDateString());
            $data = $request->validate([
                'previous_balance_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
                'previous_balance_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$latestDate],
            ]);
            $date = Carbon::parse($data['previous_balance_date'])->startOfDay();
            $amount = (float) $data['previous_balance_amount'];
            Invoice::create([
                'number' => 'OP-'.$date->format('ymd').'-'.Str::upper(Str::random(8)),
                'invoice_type' => 'Opening Due',
                'shop_type' => $job->invoice->shop_type ?? 'workshop',
                'customer_id' => $job->intake->bike->customer_id,
                'bike_id' => $job->intake->bike_id,
                'subtotal' => $amount, 'total' => $amount, 'due_amount' => $amount, 'paid_amount' => 0,
                'status' => 'Unpaid', 'public_token' => Str::random(48),
                'internal_note' => 'Forgotten previous balance recorded from '.$job->number,
                'created_at' => $date, 'updated_at' => now(), 'created_by' => $request->user()->id,
            ]);
            $job->update(['show_previous_balance_on_bill' => true]);
        });

        return redirect()->route('jobs.show', $job)->with('success', 'Previous balance added to the customer’s dues and included on this bill.');
    }
}
