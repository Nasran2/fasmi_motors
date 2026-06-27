<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

class PublicInvoiceController extends Controller
{
    public function __invoke(string $token)
    {
        $invoice = Invoice::with('jobCard.intake.bike.customer', 'jobCard.parts.product', 'jobCard.services', 'payments')->where('public_token', $token)->firstOrFail();
        abort_if($invoice->link_expires_at?->isPast(), 410, 'This invoice link has expired.');

        return view('invoices.public', compact('invoice'));
    }
}
