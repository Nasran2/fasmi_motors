<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isQuotation ? 'Quotation' : 'Invoice' }} {{ $invoice->number }} - {{ $business['business_name'] ?? 'Fasmir Motors' }}</title>
    @php
        $printContent = static function ($value): string {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            return '"'.str_replace(['\\', '"', '<', '>'], ['\\\\', '\\"', '\\3C ', '\\3E '], $text).'"';
        };
        $format = request()->query('format', 'a4');
    @endphp
    <style>
        @page { size: A4; margin: 12mm 12mm 22mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9edf3; color: #17243a; font-family: Arial, "DejaVu Sans", sans-serif; font-size: 13px; line-height: 1.3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { max-width: 794px; margin: 20px auto 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-family: Arial, sans-serif; }
        .toolbar-title { font-size: 13px; font-weight: 700; color: #40516b; }
        .toolbar-actions { display: flex; gap: 8px; }
        .action { display: inline-block; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; padding: 10px 14px; color: #16243a; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .action-primary { border-color: #d91e2b; background: #d91e2b; color: #fff; }
        .sheet { display: flex; flex-direction: column; width: 794px; min-height: 1123px; margin: 0 auto 28px; padding: 45px 45px 38px; background: #fff; box-shadow: 0 14px 45px rgba(13, 28, 50, .15); }
        .top-rule { height: 5px; background: #d91e2b; margin: 0 0 14px; }
        .top-rule span { display: block; width: 76%; height: 100%; background: #0b1d36; }
        table { width: 100%; border-collapse: collapse; }
        .header { background: #0b1d36; border-bottom: 4px solid #d91e2b; }
        .header > tbody > tr > td { padding: 16px; vertical-align: top; }
        .brand-cell { width: 56%; }
        .brand-logo { max-width: 68px; max-height: 68px; margin-right: 12px; vertical-align: middle; }
        .brand-name { margin: 0; color: #fff; font-size: 30px; font-weight: 700; line-height: 1.13; letter-spacing: -.5px; }
        .brand-partner { display: inline-block; padding: 4px 7px; margin-top: 7px; background: #fff; color: #c41426; font-size: 11px; font-weight: 700; border-radius: 3px; }
        .brand-subtitle { margin: 7px 0 0; color: #dce3eb; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; }
        .business-contact { margin-top: 9px; color: #e4eaf2; font-size: 10px; line-height: 1.4; overflow-wrap: anywhere; }
        .contact-strip { padding: 8px 10px; background: #f0f3f8; color: #40516b; font-size: 10px; line-height: 1.4; }
        .contact-strip span { display: inline-block; margin-right: 12px; }
        .document-cell { width: 44%; text-align: right; }
        .document-type { padding: 8px 10px; background: #d91e2b; color: #fff; font-size: 13px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; border-radius: 3px; }
        .document-number { margin: 10px 0; color: #fff; font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
        .meta { margin-left: auto; width: 95%; font-size: 12px; }
        .meta td { padding: 3px 0; }
        .meta td:first-child { color: #c4cfdf; text-align: left; }
        .meta td:last-child { color: #fff; font-weight: 700; text-align: right; }
        .divider { height: 0; margin: 14px 0; }
        .party { border-collapse: separate; border-spacing: 0; }
        .party td { width: 50%; padding: 10px 14px; background: #f5f7fa; border-top: 3px solid #d91e2b; vertical-align: top; border-radius: 4px; }
        .party td + td { border-left: 8px solid #fff; border-top-color: #0b1d36; }
        .eyebrow { margin: 0 0 7px; color: #d91e2b; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .party-name { color: #0b1d36; font-size: 16px; font-weight: 700; }
        .muted { color: #40516b; }
        .detail-line { margin-top: 4px; }
        .items { margin-top: 20px; table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items th { padding: 11px 10px; background: #0b1d36; color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .3px; text-align: left; text-transform: uppercase; border-bottom: 3px solid #d91e2b; }
        .items td { padding: 8px 10px; border-bottom: 1px solid #dce3eb; vertical-align: top; overflow-wrap: anywhere; }
        .items tbody tr:nth-child(even) td { background: #f7f9fc; }
        .items tr { page-break-inside: avoid; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .line-number { padding-right: 0; color: #c41426; font-size: 10px; font-weight: 700; text-align: center; }
        .items .line-amount { font-weight: 700; color: #0b1d36; }
        .item-name { color: #13233a; font-weight: 700; }
        .item-detail { margin-top: 3px; color: #7b8ba1; font-size: 9px; }
        .summary-wrap { margin-top: 16px; page-break-inside: avoid; }
        .closing { margin-top: 16px; table-layout: fixed; page-break-inside: avoid; }
        .closing > tbody > tr > td { width: 50%; vertical-align: top; }
        .closing .document-notes { padding-right: 16px; }
        .closing .summary-wrap { margin-top: 0; }
        .closing .summary { width: 100%; }
        .closing .note-box { margin-top: 0; margin-bottom: 10px; }
        .closing .terms { margin-top: 0; }
        .summary { width: 52%; margin-left: auto; font-size: 13px; }
        .summary td { padding: 6px 0; }
        .summary td:first-child { color: #607087; }
        .summary td:last-child { text-align: right; font-weight: 700; white-space: nowrap; }
        .summary .grand td { padding: 12px 10px; background: #d91e2b; color: #fff; font-size: 18px; font-weight: 700; border-top: 3px solid #0b1d36; }
        .summary .grand td:last-child { color: #fff; }
        .summary .balance td { border-top: 1px solid #dce3eb; padding: 8px 10px; background: #fff0f1; color: #a91d2c; font-weight: 700; }
        .summary.previous-balance td { padding: 4px 0; }
        .summary.previous-balance .grand td { padding: 8px 6px; font-size: 15px; }
        .summary.previous-balance .balance td { padding: 6px; }
        .note-box { margin-top: 13px; padding: 10px 14px; border-left: 3px solid #d91e2b; background: #f7f9fc; page-break-inside: avoid; }
        .note-title { margin: 0 0 5px; color: #d91e2b; font-size: 13px; font-weight: 700; }
        .note-box p { margin: 0; color: #4e5e73; white-space: pre-line; }
        .terms { margin-top: 13px; padding: 9px 12px; border: 1px solid #dce3eb; border-radius: 4px; color: #40516b; font-size: 12px; page-break-inside: avoid; }
        .terms strong { color: #22344e; }
        .signature { width: 42%; margin: 44px 0 0 auto; padding-top: 7px; border-top: 1px solid #a8b4c2; color: #64748b; font-size: 9px; text-align: center; page-break-inside: avoid; }
        .document-footer-screen { margin-top: auto; padding-top: 24px; }
        .document-footer-print { display: none; }
        .footer { padding-top: 8px; border-top: 3px solid #d91e2b; color: #40516b; font-size: 11px; page-break-inside: avoid; }
        .footer td:last-child { text-align: right; }
        .footer strong { color: #0b1d36; }
        .developer-credit { margin-top: 8px; color: #94a3b8; font-size: 8px; text-align: right; }
        .developer-credit a { color: inherit; text-decoration: none; }
        body.pdf { background: #fff; }
        .pdf .sheet { display: block; width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        .pdf .top-rule { margin: 0 0 14px; }
        .pdf .document-footer-screen { display: none; }
        .pdf .document-footer-print { display: block; position: fixed; right: 0; bottom: -10mm; left: 0; }
        @media print {
            body { background: #fff; }
            .toolbar, .document-footer-screen, .document-footer-print { display: none; }
            .sheet { display: block; width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .top-rule { margin: 0 0 14px; }
        }
        @unless($pdfMode)
        @media print {
            @page {
                @bottom-left {
                    content: {!! $printContent($wording['footerNote']) !!};
                    border-top: 1px solid #dce3eb;
                    padding-top: 3mm;
                    color: #0b1d36;
                    font: 700 11px Arial, sans-serif;
                    vertical-align: top;
                }
                @bottom-right {
                    content: {!! $printContent($business['business_phone'] ?? '') !!} "\A" "Software developed by Twinsofte.com";
                    border-top: 1px solid #dce3eb;
                    padding-top: 3mm;
                    color: #94a3b8;
                    font: 8px Arial, sans-serif;
                    text-align: right;
                    vertical-align: top;
                    white-space: pre;
                }
            }
        }
        @endunless
        @if($format === '80mm')
        @page { size: 72mm auto; margin: 0; }
        body { background: #fff; color: #000; font-family: monospace, sans-serif; font-size: 12px; line-height: 1.3; }
        .toolbar, .top-rule { display: none !important; }
        .sheet { width: 100%; max-width: 72mm; min-height: auto; margin: 0 auto; padding: 4mm; background: transparent; box-shadow: none; display: block; }
        table { width: 100%; border-collapse: collapse; }
        .header { display: block; text-align: center; }
        .header tbody, .header tr { display: block; }
        .header td { display: block; width: 100%; text-align: center; }
        .brand-logo { max-height: 40px; margin: 0 auto 5px; }
        .brand-name { font-size: 18px; font-weight: bold; margin: 0; }
        .brand-subtitle { font-size: 12px; margin: 2px 0 0; }
        .business-contact { font-size: 11px; margin-top: 5px; }
        .document-cell { text-align: center !important; margin-top: 10px; border-top: 1px dashed #000; padding-top: 10px; }
        .document-type { font-size: 14px; font-weight: bold; }
        .document-number { font-size: 15px; font-weight: bold; margin-bottom: 5px; }
        .meta { width: 100%; font-size: 11px; margin: 0 auto; }
        .meta td { text-align: center !important; padding: 1px 0; display: inline-block; width: auto; color: #000 !important; font-weight: normal !important; }
        .meta td:first-child:after { content: ': '; }
        .meta tr { display: block; }
        .divider { height: 1px; border-bottom: 1px dashed #000; background: transparent; margin: 10px 0; }
        .party { display: block; margin-top: 5px; }
        .party tbody, .party tr { display: block; }
        .party td { display: block; width: 100%; padding: 0 !important; text-align: left; margin-bottom: 5px; }
        .eyebrow { display: inline-block; margin: 0 5px 0 0; font-size: 11px; color: #000 !important; }
        .party-name { display: inline; font-size: 12px; font-weight: bold; }
        .detail-line { display: inline; font-size: 11px; margin-left: 5px; color: #000 !important; }
        .items { margin-top: 10px; }
        .items th { display: none; }
        .items td { padding: 4px 0; border-bottom: 1px dashed #ccc; font-size: 12px; vertical-align: top; background: transparent !important; }
        .items tr { display: flex; flex-wrap: wrap; page-break-inside: avoid; }
        .items td:first-child { width: 100%; font-weight: bold; padding-bottom: 2px; border-bottom: none; }
        .item-detail { font-weight: normal; font-size: 11px; }
        .items td.num { text-align: right; }
        .items td:nth-child(2) { width: 20%; text-align: left; }
        .items td:nth-child(3) { width: 40%; text-align: center; }
        .items td:nth-child(4) { width: 40%; }
        .summary-wrap { margin-top: 10px; }
        .summary { width: 100%; font-size: 12px; }
        .summary td { padding: 3px 0; color: #000 !important; }
        .summary .grand td { font-size: 14px; font-weight: bold; background: transparent !important; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 6px 0; color: #000 !important; }
        .note-box, .terms { font-size: 11px; text-align: center; margin-top: 15px; background: transparent !important; border: none !important; padding: 0 !important; }
        .note-title { font-weight: bold; margin-bottom: 3px; font-size: 12px; }
        .signature, .document-footer-screen, .document-footer-print { display: none !important; }
        @elseif($format === 'a5')
        /* A5 portrait: reserve the bottom margin for the repeating footer. */
        @page { size: 148mm 210mm; margin: 10mm 10mm 18mm; }
        .sheet { width: 148mm; min-height: 210mm; padding: 10mm 10mm 18mm; }
        .top-rule { margin: 0 0 10px; height: 4px; }
        .toolbar { max-width: 148mm; }
        body { font-size: 12px; line-height: 1.25; }
        .brand-name { font-size: 23px; }
        .header > tbody > tr > td { padding: 10px; }
        .brand-partner { font-size: 9px; padding: 3px 5px; margin-top: 5px; }
        .brand-subtitle { font-size: 9px; letter-spacing: .7px; }
        .brand-logo { max-width: 48px; max-height: 48px; }
        .business-contact { font-size: 9px; margin-top: 6px; line-height: 1.3; }
        .contact-strip { padding: 5px 8px; font-size: 9px; }
        .contact-strip span { margin-right: 8px; }
        .document-type { font-size: 11px; padding: 6px; letter-spacing: .7px; }
        .document-number { font-size: 13px; }
        .meta { font-size: 10px; }
        .divider { margin: 8px 0; }
        .party td { padding: 6px 9px; font-size: 11px; }
        .eyebrow { font-size: 10px; margin-bottom: 5px; }
        .party-name { font-size: 13px; }
        .detail-line { margin-top: 3px; }
        .items { margin-top: 10px; }
        .items th { font-size: 9px; padding: 8px 6px; }
        .items td { font-size: 11px; padding: 5px 6px; }
        .items .line-number { font-size: 9px; padding-left: 3px; }
        .summary-wrap { margin-top: 7px; }
        .closing { margin-top: 10px; }
        .closing .document-notes { padding-right: 10px; }
        .closing .note-box { font-size: 10px; padding: 6px 8px; margin-bottom: 7px; }
        .closing .terms { font-size: 9.5px; padding: 5px 8px; }
        .summary { width: 60%; font-size: 11px; }
        .summary td { padding: 4px 0; }
        .summary .grand td { font-size: 14px; padding: 8px 6px; }
        .note-box { font-size: 11px; padding: 6px 10px; margin-top: 8px; }
        .terms { font-size: 10px; margin-top: 8px; padding: 6px 9px; }
        .note-title { font-size: 11px; }
        .footer { font-size: 9px; }
        .pdf .document-footer-print { bottom: -12mm; }
        .pdf .top-rule { margin-bottom: 10px; }
        @media print { .sheet { width: auto; min-height: 0; margin: 0; padding: 0; } .top-rule { margin-bottom: 10px; } }
        @endif
        @media screen and (max-width: 820px) { .sheet { width: 100%; min-height: 0; padding: 28px 24px; } .top-rule { margin: 0 0 24px; } .toolbar { margin: 12px; flex-wrap: wrap; } }
    </style>
</head>
<body class="{{ $pdfMode ? 'pdf' : '' }}">
@unless($pdfMode)
<div class="toolbar"><span class="toolbar-title">{{ $isQuotation ? 'Quotation' : 'Bill' }} preview</span><div class="toolbar-actions"><button type="button" class="action" onclick="window.print()">Print</button>@if($allowPublicPdf)<a class="action action-primary" href="{{ route('invoices.public.pdf', ['token' => $invoice->public_token, 'format' => $format]) }}">Download PDF</a>@endif</div></div>
@endunless
<div class="document-footer-print">@include('invoices.partials.footer')</div>
<main class="sheet">
    <div class="top-rule"><span></span></div>
    <table class="header"><tr>
        <td class="brand-cell">
            @if($logo)<img class="brand-logo" src="{{ $logo }}" alt="">@endif
            <div class="brand-name">{{ $business['business_name'] ?? 'Fasmir Motors' }}</div>
            @if(($invoice->shop_type ?? 'workshop') === 'workshop' && $format !== '80mm')<div class="brand-partner">Yamaha Authorized Partner</div>@endif
            @if($business['business_subtitle'] ?? 'Bike Repair Center')<div class="brand-subtitle">{{ $business['business_subtitle'] ?? 'Bike Repair Center' }}</div>@endif
        </td>
        <td class="document-cell">
            <div class="document-type">{{ $isQuotation ? 'Quotation' : ($invoice->invoice_type === 'Job Card Bill' ? 'Service Invoice' : 'Sales Invoice') }}</div>
            <div class="document-number">{{ $invoice->number }}</div>
            <table class="meta">
                <tr><td>Date</td><td>{{ $invoice->created_at->format('d M Y') }}</td></tr>
                @if(!$isQuotation)
                    @if((float) $invoice->paid_amount > 0 || (float) $invoice->hold_amount > 0 || $invoice->status === 'Paid')
                        <tr><td>Status</td><td>{{ $invoice->status }}</td></tr>
                    @endif
                @endif
                @if($business['business_registration_no'] ?? null)<tr><td>BRN</td><td>{{ $business['business_registration_no'] }}</td></tr>@endif
                @if($business['tax_registration_no'] ?? null)<tr><td>TIN</td><td>{{ $business['tax_registration_no'] }}</td></tr>@endif
            </table>
        </td>
    </tr></table>
    <div class="contact-strip">
        @foreach(['business_address', 'business_phone', 'business_email', 'business_website'] as $contactKey)
            @if($business[$contactKey] ?? null)<span>{{ $business[$contactKey] }}</span>@endif
        @endforeach
    </div>
    <div class="divider"></div>
    @php($documentBike = $invoice->bike ?? $invoice->jobCard?->intake?->bike)
    <table class="party"><tr>
        <td><p class="eyebrow">Prepared for</p><div class="party-name">{{ $invoice->customer?->name ?: 'Walk-in Customer' }}</div>@if($invoice->customer?->phone)<div class="detail-line muted">{{ $invoice->customer->phone }}</div>@endif</td>
        <td><p class="eyebrow">{{ $invoice->jobCard ? 'Vehicle & Job Card' : 'Sale Details' }}</p><div class="party-name">{{ $documentBike?->number ?? 'Counter Sale' }}</div>
            @if($invoice->jobCard?->number)<div class="detail-line muted">Job {{ $invoice->jobCard->number }}</div>@elseif($invoice->store?->name)<div class="detail-line muted">{{ $invoice->store->name }}</div>@endif
            @if(($business['show_bike_invoice'] ?? '1') !== '0' && $documentBike)<div class="detail-line muted">{{ collect([$documentBike->brand, $documentBike->model, $documentBike->color])->filter()->implode(' · ') }}</div>@endif
            @if(($business['show_technician_invoice'] ?? '1') !== '0' && $invoice->jobCard?->technician?->name)<div class="detail-line muted">Technician: {{ $invoice->jobCard->technician->name }}</div>@endif
        </td>
    </tr></table>
    <table class="items">
        <thead><tr>@if($format !== '80mm')<th class="line-number" style="width:5%;color:#fff">#</th>@endif<th style="width:{{ $format === '80mm' ? '49' : '43' }}%">Description</th><th class="num" style="width:10%">Qty</th><th class="num" style="width:20%">Unit price</th><th class="num" style="width:22%">Amount</th></tr></thead>
        <tbody>
            @forelse($lines as $line)
                <tr>@if($format !== '80mm')<td class="line-number">{{ $loop->iteration }}</td>@endif<td><div class="item-name">{{ $line['name'] }}</div></td><td class="num">{{ number_format($line['qty'], $line['qty'] == floor($line['qty']) ? 0 : 2) }}</td><td class="num">{{ number_format($line['rate'], 2) }}</td><td class="num line-amount">{{ number_format($line['total'], 2) }}</td></tr>
            @empty
                <tr><td colspan="{{ $format === '80mm' ? 4 : 5 }}" class="muted">No line items recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($showPreviousBalance && $previousBalances->isNotEmpty())
        <p class="eyebrow" style="margin-top:16px">Previous unpaid bills</p>
        <table class="items">
            <thead><tr><th style="width:45%">Job / Bill</th><th style="width:25%">Date</th><th class="num" style="width:30%">Balance (LKR)</th></tr></thead>
            <tbody>@foreach($previousBalances as $balance)<tr><td>{{ $balance['reference'] }}</td><td>{{ $balance['date'] }}</td><td class="num">{{ number_format($balance['amount'],2) }}</td></tr>@endforeach</tbody>
        </table>
    @endif
    @php($compactClosing = $format !== '80mm' && mb_strlen($wording['introText'].$wording['customerNote'].$wording['terms']) <= 600)
    @if($compactClosing)<table class="closing"><tr><td class="document-notes">@include('invoices.partials.notes')</td><td>@endif
    <div class="summary-wrap"><table class="summary {{ $showPreviousBalance ? 'previous-balance' : '' }}">
        <tr><td>Subtotal</td><td>Rs. {{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
        @if((float) $invoice->discount > 0)<tr><td>Discount</td><td>- Rs. {{ number_format((float) $invoice->discount, 2) }}</td></tr>@endif
        @if((float) $invoice->tax > 0)<tr><td>Tax</td><td>+ Rs. {{ number_format((float) $invoice->tax, 2) }}</td></tr>@endif
        @if($invoice->return_amount>0)<tr><td>Returned credit</td><td>Rs. {{ number_format($invoice->return_amount,2) }}</td></tr>@endif
        <tr class="{{ $showPreviousBalance ? '' : 'grand' }}"><td>{{ $isQuotation ? 'Quoted total' : 'Total' }}</td><td>Rs. {{ number_format((float) $invoice->total, 2) }}</td></tr>
        @if($showPreviousBalance)
            <tr><td>Previous Balance</td><td>Rs. {{ number_format($previousBalance,2) }}</td></tr>
            <tr class="grand"><td>Grand Total</td><td>Rs. {{ number_format($grandTotal,2) }}</td></tr>
        @endif
        @unless($isQuotation)
            @if((float) $invoice->paid_amount > 0 || (float) $invoice->hold_amount > 0 || $invoice->status === 'Paid')
                <tr><td>Paid</td><td>Rs. {{ number_format((float) $invoice->paid_amount, 2) }}</td></tr>
                @if($invoice->change_amount > 0)
                <tr><td>Amount received</td><td>Rs. {{ number_format($invoice->received_amount, 2) }}</td></tr>
                <tr><td>Change to return</td><td>Rs. {{ number_format($invoice->change_amount, 2) }}</td></tr>
                @endif
                @if((float) $invoice->hold_amount > 0)<tr><td>Cheque Hold</td><td>Rs. {{ number_format((float) $invoice->hold_amount, 2) }}</td></tr>@endif
                <tr class="balance"><td>{{ $showPreviousBalance ? 'Current bill due' : 'Balance due' }}</td><td>Rs. {{ number_format((float) $invoice->due_amount, 2) }}</td></tr>
            @endif
            @if($showPreviousBalance)<tr class="balance"><td>Total balance due</td><td>Rs. {{ number_format($totalBalanceDue,2) }}</td></tr>@endif
        @endunless
    </table></div>
    @if($compactClosing)</td></tr></table>@else @include('invoices.partials.notes') @endif
    @if($business['invoice_signature_text'] ?? null)<div class="signature">{{ $business['invoice_signature_text'] }}</div>@endif
    <div class="document-footer-screen">@include('invoices.partials.footer')</div>
</main>
@unless($pdfMode)
<script>
    window.addEventListener('load', () => { 
        if (new URLSearchParams(window.location.search).has('print')) {
            setTimeout(() => window.print(), 500);
        }
    });
    window.addEventListener('afterprint', () => {
        if (new URLSearchParams(window.location.search).has('print')) {
            window.close();
            setTimeout(() => window.history.back(), 500);
        }
    });
</script>
@endunless
</body>
</html>
