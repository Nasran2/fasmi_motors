<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PublicBillAccess;
use Illuminate\Http\Request;

class PublicInvoiceController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $invoice = Invoice::with(['customer', 'bike', 'jobCard.intake.bike.customer', 'jobCard.parts.product', 'jobCard.services', 'items.product', 'payments'])->where('public_token', $token)->firstOrFail();
        abort_unless($invoice->link_enabled, 410, 'This invoice link has been disabled.');
        abort_if($invoice->link_expires_at?->isPast(), 410, 'This invoice link has expired.');
        $invoice->increment('open_count');
        $invoice->update(['last_opened_at' => now()]);
        PublicBillAccess::create(['invoice_id' => $invoice->id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'opened_at' => now()]);

        return view('invoices.public', compact('invoice'));
    }
}
