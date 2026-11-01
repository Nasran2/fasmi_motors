<?php

namespace App\Http\Controllers;

use App\Models\CashierSession;
use App\Services\OutgoingFundingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FundingOptionsController extends Controller
{
    public function __invoke(Request $request, OutgoingFundingService $funding)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $date = Carbon::parse($data['date'])->startOfDay();
        $open = ! $date->isFuture() && CashierSession::whereDate('session_date', $data['date'])->where('status', 'open')->exists();
        $message = 'Cashier for '.$date->format('d M Y').' is '.($open ? 'open. Its cashier funds are selectable.' : 'closed. Cashier funds are unavailable.');
        if (! $open && ($active = CashierSession::active())) {
            $message .= ' The open cashier is '.$active->session_date->format('d M Y').'; select that payment date to use its cashier funds.';
        }

        return response()->json(['options' => $funding->options($data['date']), 'message' => $message])
            ->header('Cache-Control', 'no-store');
    }
}
