@php
  $identity = $identity ?? \App\Support\PrintDocument::configuration();
  $orientation = ($orientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
  $compact = $compact ?? false;
@endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} - {{ $identity['business'] }}</title>
  <style>{!! file_get_contents(resource_path('css/print-document.css')) !!}
    @page { size: A4 {{ $orientation }}; margin: {{ $compact ? ($orientation === 'landscape' ? '6mm 8mm 11mm' : '8mm 10mm 12mm') : '14mm 12mm 18mm' }}; @unless($pdfMode ?? false) @bottom-left { content: {!! \Illuminate\Support\Js::encode($identity['business'].' | '.$title) !!}; font: 7pt Arial; color: #647184; } @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 7pt Arial; color: #647184; } @endunless }
    @if($pdfMode ?? false) body { background: #fff; } .document { margin: 0; padding: 0; max-width: none; box-shadow: none; } @endif
    @if($compact)
    .document-header { margin-bottom: 5px; border-bottom-width: 1.5px; }
    .document-header td { padding: 0 0 4px; }
    .business-name { font-size: 13pt; }
    .business-detail { font-size: 6.8pt; margin-top: 1px; }
    .document-title { font-size: 12pt; margin: 0 0 2px; }
    .document-meta { font-size: 6.8pt; }
    .document-filters { padding: 2.5px 6px; margin: 0 0 4px; font-size: 6.8pt; }
    .document-summary { margin: 0 0 5px; }
    .document-summary td { padding: 3px 5px; }
    .summary-label { font-size: 6pt; }
    .summary-value { font-size: 8.5pt; margin-top: 1px; }
    .document-table { font-size: 6.8pt; margin: 0 0 4px; }
    .document-table th, .document-table td { padding: 2px 3.5px; line-height: 1.15; }
    .document-table th { font-size: 6.8pt; padding: 2.5px 3.5px; }
    .document-totals td, .document-totals th { padding: 2.5px 3.5px; font-size: 6.8pt; }
    @endif
  </style>
</head>
<body>
  @unless($pdfMode ?? false)
  <div class="document-toolbar"><span>Print preview · A4 {{ $orientation }}</span><div><button type="button" class="primary" onclick="window.print()">Print / Save PDF</button> <button type="button" onclick="window.close()">Close</button></div></div>
  @endunless
  <main class="document" data-print-document>
    <table class="document-header" role="presentation"><tbody><tr>
      <td class="identity"><div class="business-name">{{ $identity['business'] }}</div><div class="business-detail">{{ $identity['shop'] }}</div>@if($identity['address'])<div class="business-detail">{{ $identity['address'] }}</div>@endif @if($identity['contact'])<div class="business-detail">{{ $identity['contact'] }}</div>@endif</td>
      <td class="document-heading"><h1 class="document-title">{{ $title }}</h1>@if($period ?? '')<div class="document-meta">{{ $period }}</div>@endif<div class="document-meta">Generated: {{ $identity['generated'] }}</div><div class="document-meta">Prepared by: {{ $identity['preparedBy'] }}</div></td>
    </tr></tbody></table>
    @if($filters ?? '')<p class="document-filters">{{ $filters }}</p>@endif
    @if($summary ?? [])
    @php
      if ($compact && $orientation === 'landscape' && count($summary) <= 6) {
        $summaryRows = [$summary];
      } elseif (count($summary) === 5) {
        $summaryRows = [array_slice($summary, 0, 2), array_slice($summary, 2)];
      } else {
        $summaryRows = array_chunk($summary, count($summary) === 6 ? 3 : 4);
      }
    @endphp
    @foreach($summaryRows as $summaryRow)
    <table class="document-summary" role="presentation"><tbody><tr>@foreach($summaryRow as $item)<td><div class="summary-label">{{ $item['label'] }}</div><div class="summary-value">{{ $item['value'] }}</div></td>@endforeach</tr></tbody></table>
    @endforeach
    @endif
    {{ $slot }}
    @unless($pdfMode ?? false)<footer class="document-footer">{{ $identity['business'] }} · {{ $title }} · {{ $identity['generated'] }}</footer>@endunless
  </main>
  @if(($autoPrint ?? false) && !($pdfMode ?? false))
  <script>window.addEventListener('load', async () => { if (document.fonts) await document.fonts.ready; window.print(); }, {once: true});</script>
  @endif
</body>
</html>
