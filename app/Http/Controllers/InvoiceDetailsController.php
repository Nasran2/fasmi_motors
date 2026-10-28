<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BillDetailsService;
use Illuminate\Http\Request;

class InvoiceDetailsController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, BillDetailsService $service)
    {
        $service->updateInvoice($invoice, $request->validate(BillDetailsService::rules()));

        return back()->with('success', 'Bill date updated. Existing payment dates are unchanged.');
    }
}
