<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt {{ $invoice->number }}</title>
<style>
@page { size: 80mm 297mm; margin: 4mm; }
@if($pdfMode)
@page { size: 80mm {{ $receiptHeightMm ?? 297 }}mm; }
@endif
* { box-sizing: border-box; }
body { margin:0; color:#000; background:#eef2f6; font:12px/1.25 Arial, "DejaVu Sans", sans-serif; }
.toolbar { max-width:520px; margin:20px auto; padding:16px; background:#fff; border-radius:12px; }
.toolbar p { margin:8px 0 0; color:#475569; font-size:12px; }
.toolbar button,.toolbar a { display:inline-block; padding:12px 16px; border:1px solid #cbd5e1; background:#fff; border-radius:8px; font-weight:bold; color:#111; cursor:pointer; text-decoration:none; }
.toolbar-actions,.format-selector { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.toolbar .format-option[aria-current="page"] { background:#0b1d36; border-color:#0b1d36; color:#fff; }
.format-option:focus-visible { outline:2px solid #d91e2b; outline-offset:2px; }
.receipt { width:72mm; padding:4mm 2mm; margin:16px auto; background:#fff; overflow-wrap:anywhere; }
h1 { margin:0; font-size:18px; line-height:1.2; } h2 { margin:8px 0 2px; font-size:14px; }
p { margin:3px 0; } .center { text-align:center; } .small { font-size:10px; }
.rule { margin:6px 0; border:0; border-top:1px dashed #000; }
table { width:100%; border-collapse:collapse; table-layout:fixed; } th,td { padding:2px 0; vertical-align:top; } .right { text-align:right; white-space:nowrap; } .items th { border-bottom:1px solid #000; font-size:10px; } .item-name td { padding:4px 0 0; font-weight:bold; } .item-values td { padding:0 0 2px; } .items tbody { page-break-inside:avoid; } .total td { border-top:1px dashed #000; border-bottom:1px dashed #000; padding:4px 0; font-size:14px; font-weight:bold; } tr { page-break-inside:avoid; }
@media print { body { background:#fff; } .toolbar { display:none; } .receipt { width:72mm; padding:0 2mm; margin:0; } }
body.pdf { background:#fff; } .pdf .receipt { width:72mm; padding:0 2mm; margin:0; }
</style></head><body class="{{ $pdfMode ? 'pdf' : '' }}">
@unless($pdfMode)<div class="toolbar"><div class="toolbar-actions">@include('invoices.partials.format-selector', ['format' => '80mm'])<button type="button" onclick="window.print()">Print 80 mm</button>@if($allowPublicPdf)<a href="{{ route('invoices.public.pdf',array_merge(['token'=>$invoice->public_token,'format'=>'80mm'], $presentationQuery)) }}">Download 80 mm PDF</a>@endif</div><p>Select your receipt printer, 80 mm paper, 100% scale, and turn off browser headers and footers.</p></div>@endunless
<main class="receipt"><header class="center">@if($logo)<img src="{{ $logo }}" alt="" style="max-width:45mm;max-height:16mm;margin-bottom:5px">@endif<h1>{{ $business['business_name'] ?? 'Fasmir Motors' }}</h1><p class="small">{{ $business['business_subtitle'] ?? 'Bike Repair Center' }}</p>@if($business['business_address']??null)<p class="small">{{ $business['business_address'] }}</p>@endif @if($business['business_phone']??null)<p>{{ $business['business_phone'] }}</p>@endif<h2>{{ $isQuotation ? 'QUOTATION' : ($invoice->invoice_type === 'Job Card Bill' ? 'SERVICE RECEIPT' : 'SALES RECEIPT') }}</h2><b>{{ $invoice->number }}</b><p class="small">{{ $invoice->created_at->format('d M Y, h:i A') }}</p></header><hr class="rule">
<p>Customer: <b>{{ $invoice->customer?->name ?: 'Walk-in Customer' }}</b></p>@if($invoice->customer?->phone)<p class="small">{{ $invoice->customer->phone }}</p>@endif @if($invoice->store)<p class="small">Store: {{ $invoice->store->name }}</p>@endif
@if($invoice->jobCard)<p class="small">Job: {{ $invoice->jobCard->number }}</p>@endif @if($invoiceBike)<p class="small">Bike: {{ ($invoiceBike)->number }}</p>@endif
<table class="items"><colgroup><col style="width:20%"><col style="width:35%"><col style="width:45%"></colgroup><thead><tr><th>Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr></thead>@foreach($lines as $line)<tbody><tr class="item-name"><td colspan="3">{{ $line['name'] }}</td></tr><tr class="item-values"><td>{{ number_format($line['qty'],$line['qty']==floor($line['qty'])?0:2) }}</td><td class="right">{{ number_format($line['rate'],2) }}</td><td class="right">{{ number_format($line['total'],2) }}</td></tr></tbody>@endforeach</table><hr class="rule">
@if($showPreviousBalance && $previousBalances->isNotEmpty())
<p><b>Previous unpaid bills</b></p>
@foreach($previousBalances as $balance)
<p class="small">{{ $balance['reference'] }}</p>
<table><tr><td class="small">{{ $balance['date'] }}</td><td class="right">{{ number_format($balance['amount'],2) }}</td></tr></table>
@endforeach
<hr class="rule">
@endif
<table><tr><td>Subtotal</td><td class="right">{{ number_format($invoice->subtotal,2) }}</td></tr>@if($invoice->discount>0)<tr><td>Discount</td><td class="right">-{{ number_format($invoice->discount,2) }}</td></tr>@endif @if($invoice->tax>0)<tr><td>Tax</td><td class="right">{{ number_format($invoice->tax,2) }}</td></tr>@endif @if($invoice->return_amount>0)<tr><td>Returned credit</td><td class="right">{{ number_format($invoice->return_amount,2) }}</td></tr>@endif<tr class="total"><td>Total (LKR)</td><td class="right">{{ number_format($invoice->total,2) }}</td></tr>
@if($showPreviousBalance)
<tr><td>{{ $previousBalanceLabel }}</td><td class="right">{{ number_format($previousBalance,2) }}</td></tr>
<tr class="total"><td>Grand Total (LKR)</td><td class="right">{{ number_format($grandTotal,2) }}</td></tr>
@endif
@unless($isQuotation)<tr><td>Paid</td><td class="right">{{ number_format($summaryPaid,2) }}</td></tr>@if($summaryChange>0)<tr><td>Amount received</td><td class="right">{{ number_format($summaryReceived,2) }}</td></tr><tr><td>Change to return</td><td class="right">{{ number_format($summaryChange,2) }}</td></tr>@endif@if($summaryHold>0)<tr><td>Cheque Hold</td><td class="right">{{ number_format($summaryHold,2) }}</td></tr>@endif<tr><td>{{ $showPreviousBalance ? 'Balance due (this bill)' : 'Balance Due' }}</td><td class="right">{{ number_format($invoice->due_amount,2) }}</td></tr>@if($showPreviousBalance && $showTotalBalanceDue)<tr class="total"><td>Total balance due</td><td class="right">{{ number_format($totalBalanceDue,2) }}</td></tr>@endif
@endunless</table>
@if($wording['customerNote'])<hr class="rule"><p class="small">{{ $wording['customerNote'] }}</p>@endif @if($wording['terms'])<hr class="rule"><p class="small">{!! nl2br(e($wording['terms'])) !!}</p>@endif<hr class="rule"><p class="center small">{{ $wording['footerNote'] }}</p><p class="center small">Software developed by Twinsofte.com</p>
</main>@unless($pdfMode)<script>
function sizeReceipt() { const height = Math.max(80, Math.ceil(document.querySelector('.receipt').getBoundingClientRect().height * 25.4 / 96) + 12); let style = document.getElementById('receipt-page-size'); if (!style) { style=document.createElement('style'); style.id='receipt-page-size'; document.head.append(style); } style.textContent=`@page { size:80mm ${height}mm; margin:4mm; }`; }
window.addEventListener('beforeprint',sizeReceipt);
window.addEventListener('load', async () => { await document.fonts.ready; sizeReceipt(); if(new URLSearchParams(location.search).has('print')) window.print(); });
</script>@endunless</body></html>
