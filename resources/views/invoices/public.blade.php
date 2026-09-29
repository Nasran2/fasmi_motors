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
    @endphp
    <style>
        @page { size: A4; margin: 16mm 17mm 30mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9edf3; color: #17243a; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; line-height: 1.48; }
        .toolbar { max-width: 794px; margin: 20px auto 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-family: Arial, sans-serif; }
        .toolbar-title { font-size: 13px; font-weight: 700; color: #40516b; }
        .toolbar-actions { display: flex; gap: 8px; }
        .action { display: inline-block; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; padding: 10px 14px; color: #16243a; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .action-primary { border-color: #d91e2b; background: #d91e2b; color: #fff; }
        .sheet { display: flex; flex-direction: column; width: 794px; min-height: 1123px; margin: 0 auto 28px; padding: 53px 64px 48px; background: #fff; box-shadow: 0 14px 45px rgba(13, 28, 50, .15); }
        .top-rule { height: 6px; background: #d91e2b; margin: -53px -64px 35px; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand-cell { width: 56%; }
        .brand-logo { max-width: 68px; max-height: 68px; margin-right: 12px; vertical-align: middle; }
        .brand-name { margin: 0; color: #0b1d36; font-size: 22px; font-weight: 700; line-height: 1.13; letter-spacing: -.5px; }
        .brand-subtitle { margin: 5px 0 0; color: #d91e2b; font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; }
        .business-contact { margin-top: 12px; color: #536178; font-size: 9px; line-height: 1.55; }
        .document-cell { width: 44%; text-align: right; }
        .document-type { color: #d91e2b; font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; }
        .document-number { margin: 5px 0 10px; color: #0b1d36; font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
        .meta { margin-left: auto; width: 90%; font-size: 10px; }
        .meta td { padding: 3px 0; }
        .meta td:first-child { color: #68788f; text-align: left; }
        .meta td:last-child { color: #17243a; font-weight: 700; text-align: right; }
        .divider { height: 1px; margin: 17px 0; background: #dce3eb; }
        .party td { width: 50%; vertical-align: top; }
        .party td + td { padding-left: 28px; }
        .eyebrow { margin: 0 0 7px; color: #d91e2b; font-size: 9px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase; }
        .party-name { color: #0b1d36; font-size: 13px; font-weight: 700; }
        .muted { color: #64748b; }
        .detail-line { margin-top: 4px; }
        .items { margin-top: 20px; table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items th { padding: 11px 10px; background: #0b1d36; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: .6px; text-align: left; text-transform: uppercase; }
        .items td { padding: 9px 10px; border-bottom: 1px solid #e5eaf0; vertical-align: top; }
        .items tbody tr:nth-child(even) td { background: #f7f9fc; }
        .items tr { page-break-inside: avoid; }
        .items .num { text-align: right; white-space: nowrap; }
        .item-name { color: #13233a; font-weight: 700; }
        .item-detail { margin-top: 3px; color: #7b8ba1; font-size: 9px; }
        .summary-wrap { margin-top: 16px; page-break-inside: avoid; }
        .summary { width: 49%; margin-left: auto; font-size: 10px; }
        .summary td { padding: 6px 0; }
        .summary td:first-child { color: #607087; }
        .summary td:last-child { text-align: right; font-weight: 700; white-space: nowrap; }
        .summary .grand td { padding: 12px 10px; background: #f1f4f8; color: #0b1d36; font-size: 13px; font-weight: 700; }
        .summary .grand td:last-child { color: #d91e2b; }
        .summary .balance td { border-top: 1px solid #dce3eb; padding-top: 9px; color: #a91d2c; font-weight: 700; }
        .note-box { margin-top: 13px; padding: 10px 14px; border-left: 3px solid #d91e2b; background: #f7f9fc; page-break-inside: avoid; }
        .note-title { margin: 0 0 5px; color: #0b1d36; font-size: 10px; font-weight: 700; }
        .note-box p { margin: 0; color: #4e5e73; white-space: pre-line; }
        .terms { margin-top: 13px; color: #66768a; font-size: 9px; page-break-inside: avoid; }
        .terms strong { color: #22344e; }
        .signature { width: 42%; margin: 44px 0 0 auto; padding-top: 7px; border-top: 1px solid #a8b4c2; color: #64748b; font-size: 9px; text-align: center; page-break-inside: avoid; }
        .document-footer-screen { margin-top: auto; padding-top: 24px; }
        .document-footer-print { display: none; }
        .footer { padding-top: 12px; border-top: 1px solid #dce3eb; color: #6a7b91; font-size: 9px; page-break-inside: avoid; }
        .footer td:last-child { text-align: right; }
        .footer strong { color: #0b1d36; }
        .developer-credit { margin-top: 8px; color: #94a3b8; font-size: 8px; text-align: right; }
        .developer-credit a { color: inherit; text-decoration: none; }
        body.pdf { background: #fff; }
        .pdf .sheet { display: block; width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        .pdf .top-rule { margin: -16mm -17mm 17px; }
        .pdf .document-footer-screen { display: none; }
        .pdf .document-footer-print { display: block; position: fixed; right: 0; bottom: -10mm; left: 0; }
        @media print {
            body { background: #fff; }
            .toolbar, .document-footer-screen, .document-footer-print { display: none; }
            .sheet { display: block; width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .top-rule { margin: 0 -17mm 25px; }
        }
        @unless($pdfMode)
        @media print {
            @page {
                @bottom-left {
                    content: {!! $printContent($wording['footerNote']) !!};
                    border-top: 1px solid #dce3eb;
                    padding-top: 3mm;
                    color: #0b1d36;
                    font: 700 9px Arial, sans-serif;
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
        @media screen and (max-width: 820px) { .sheet { width: 100%; min-height: 0; padding: 28px 24px; } .top-rule { margin: -28px -24px 24px; } .toolbar { margin: 12px; flex-wrap: wrap; } .document-number { font-size: 13px; } }
    </style>
</head>
<body class="{{ $pdfMode ? 'pdf' : '' }}">
@unless($pdfMode)
<div class="toolbar"><span class="toolbar-title">{{ $isQuotation ? 'Quotation' : 'Bill' }} preview</span><div class="toolbar-actions"><button type="button" class="action" onclick="window.print()">Print</button>@if($allowPublicPdf)<a class="action action-primary" href="{{ route('invoices.public.pdf', $invoice->public_token) }}">Download PDF</a>@endif</div></div>
@endunless
<div class="document-footer-print">@include('invoices.partials.footer')</div>
<main class="sheet">
    <div class="top-rule"></div>
    <table class="header"><tr>
        <td class="brand-cell">
            @if($logo)<img class="brand-logo" src="{{ $logo }}" alt="">@endif
            <div class="brand-name">{{ $business['business_name'] ?? 'Fasmir Motors' }}</div>
            @if($business['business_subtitle'] ?? 'Bike Repair Center')<div class="brand-subtitle">{{ $business['business_subtitle'] ?? 'Bike Repair Center' }}</div>@endif
            <div class="business-contact">
                @if($business['business_address'] ?? null)<div>{{ $business['business_address'] }}</div>@endif
                @if($business['business_phone'] ?? null)<div>{{ $business['business_phone'] }}@if($business['business_email'] ?? null) &nbsp;|&nbsp; {{ $business['business_email'] }}@endif</div>@endif
                @if($business['business_website'] ?? null)<div>{{ $business['business_website'] }}</div>@endif
            </div>
        </td>
        <td class="document-cell">
            <div class="document-type">{{ $isQuotation ? 'Quotation' : ($invoice->invoice_type === 'Job Card Bill' ? 'Service Invoice' : 'Sales Invoice') }}</div>
            <div class="document-number">{{ $invoice->number }}</div>
            <table class="meta">
                <tr><td>Date</td><td>{{ $invoice->created_at->format('d M Y') }}</td></tr>
                @if(!$isQuotation)<tr><td>Status</td><td>{{ $invoice->status }}</td></tr>@endif
                @if($business['business_registration_no'] ?? null)<tr><td>BRN</td><td>{{ $business['business_registration_no'] }}</td></tr>@endif
                @if($business['tax_registration_no'] ?? null)<tr><td>TIN</td><td>{{ $business['tax_registration_no'] }}</td></tr>@endif
            </table>
        </td>
    </tr></table>
    <div class="divider"></div>
    @php($documentBike = $invoice->bike ?? $invoice->jobCard?->intake?->bike)
    <table class="party"><tr>
        <td><p class="eyebrow">Prepared for</p><div class="party-name">{{ $invoice->customer?->name ?: 'Walk-in Customer' }}</div>@if($invoice->customer?->phone)<div class="detail-line muted">{{ $invoice->customer->phone }}</div>@endif</td>
        <td><p class="eyebrow">Reference</p><div class="party-name">{{ $documentBike?->number ?? 'Counter Sale' }}</div>
            @if($invoice->jobCard?->number)<div class="detail-line muted">Job {{ $invoice->jobCard->number }}</div>@elseif($invoice->store?->name)<div class="detail-line muted">{{ $invoice->store->name }}</div>@endif
            @if(($business['show_bike_invoice'] ?? '1') !== '0' && $documentBike)<div class="detail-line muted">{{ collect([$documentBike->brand, $documentBike->model, $documentBike->color])->filter()->implode(' · ') }}</div>@endif
            @if(($business['show_technician_invoice'] ?? '1') !== '0' && $invoice->jobCard?->technician?->name)<div class="detail-line muted">Technician: {{ $invoice->jobCard->technician->name }}</div>@endif
        </td>
    </tr></table>
    <table class="items">
        <thead><tr><th style="width:49%">Description</th><th class="num" style="width:13%">Qty</th><th class="num" style="width:19%">Unit price</th><th class="num" style="width:19%">Amount</th></tr></thead>
        <tbody>
            @forelse($lines as $line)
                <tr><td><div class="item-name">{{ $line['name'] }}</div>@if($line['detail'])<div class="item-detail">{{ $line['detail'] }}</div>@endif</td><td class="num">{{ number_format($line['qty'], $line['qty'] == floor($line['qty']) ? 0 : 2) }}</td><td class="num">{{ number_format($line['rate'], 2) }}</td><td class="num">{{ number_format($line['total'], 2) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No line items recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="summary-wrap"><table class="summary">
        <tr><td>Subtotal</td><td>Rs. {{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
        @if((float) $invoice->discount > 0)<tr><td>Discount</td><td>- Rs. {{ number_format((float) $invoice->discount, 2) }}</td></tr>@endif
        @if((float) $invoice->tax > 0)<tr><td>Tax</td><td>+ Rs. {{ number_format((float) $invoice->tax, 2) }}</td></tr>@endif
        <tr class="grand"><td>{{ $isQuotation ? 'Quoted total' : 'Total' }}</td><td>Rs. {{ number_format((float) $invoice->total, 2) }}</td></tr>
        @unless($isQuotation)<tr><td>Paid</td><td>Rs. {{ number_format((float) $invoice->paid_amount, 2) }}</td></tr><tr class="balance"><td>Balance due</td><td>Rs. {{ number_format((float) $invoice->due_amount, 2) }}</td></tr>@endunless
    </table></div>
    @if($wording['introText'])<div class="note-box">@if($wording['introHeading'])<p class="note-title">{{ $wording['introHeading'] }}</p>@endif<p>{{ $wording['introText'] }}</p></div>@endif
    @if($wording['customerNote'])<div class="note-box">@if($wording['customerNoteHeading'])<p class="note-title">{{ $wording['customerNoteHeading'] }}</p>@endif<p>{{ $wording['customerNote'] }}</p></div>@endif
    @if($wording['terms'])<div class="terms">@if($wording['termsHeading'])<strong>{{ $wording['termsHeading'] }}</strong><br>@endif{!! nl2br(e($wording['terms'])) !!}</div>@endif
    @if($business['invoice_signature_text'] ?? null)<div class="signature">{{ $business['invoice_signature_text'] }}</div>@endif
    <div class="document-footer-screen">@include('invoices.partials.footer')</div>
</main>
@unless($pdfMode)<script>window.addEventListener('load', () => { if (new URLSearchParams(window.location.search).has('print')) window.print(); });</script>@endunless
</body>
</html>
