<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerReceiptService;
use App\Services\SettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerPaymentController extends Controller
{
    public function __invoke(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $rows = app(SettlementService::class)->rows($request->all());
        app(CustomerReceiptService::class)->receive($customer, $rows, $data['paid_at']);

        return back()->with('success', 'Customer payment saved. Cheques remain on hold until cleared.');
    }
}
