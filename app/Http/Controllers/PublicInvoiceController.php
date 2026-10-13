<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PublicBillAccess;
use App\Models\Setting;
use App\Scopes\ShopScope;
use App\Support\InvoiceDocument;
use App\Support\ThermalReceipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PublicInvoiceController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $invoice = $this->openInvoice($request, $token);

        return view($request->query('format') === '80mm' ? 'invoices.thermal' : 'invoices.public', InvoiceDocument::data(
            $invoice,
            allowPublicPdf: filter_var(Setting::valueFor('public_pdf_download', true), FILTER_VALIDATE_BOOL),
            showTotalBalanceDue: ! $request->boolean('hide_total_balance_due'),
        ));
    }

    public function pdf(Request $request, string $token)
    {
        abort_unless(filter_var(Setting::valueFor('public_pdf_download', true), FILTER_VALIDATE_BOOL), 403);
        $invoice = $this->openInvoice($request, $token);
        $data = InvoiceDocument::data($invoice, pdfMode: true, showTotalBalanceDue: ! $request->boolean('hide_total_balance_due'));

        if ($request->query('format') === '80mm') {
            return ThermalReceipt::pdf($data)->download($this->filename($invoice));
        }

        return Pdf::loadView('invoices.public', $data)
            ->setPaper($request->query('format') === 'a5' ? 'a5' : 'a4')->download($this->filename($invoice));
    }

    private function openInvoice(Request $request, string $token): Invoice
    {
        $invoice = Invoice::withoutGlobalScope(ShopScope::class)->with(['customer', 'bike', 'store', 'jobCard.intake.bike.customer', 'jobCard.technician', 'jobCard.parts.product', 'jobCard.services', 'items.product', 'payments'])->where('public_token', $token)->firstOrFail();
        abort_if($invoice->status === 'Cancelled', 410, 'This invoice has been cancelled.');
        abort_unless($invoice->link_enabled, 410, 'This invoice link has been disabled.');
        abort_if($invoice->link_expires_at?->isPast(), 410, 'This invoice link has expired.');
        $invoice->increment('open_count');
        $invoice->update(['last_opened_at' => now()]);
        PublicBillAccess::create(['invoice_id' => $invoice->id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'opened_at' => now()]);

        return $invoice;
    }

    private function filename(Invoice $invoice): string
    {
        return ($invoice->invoice_type === 'Quotation' ? 'Quotation-' : 'Bill-').preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->number).'.pdf';
    }
}
