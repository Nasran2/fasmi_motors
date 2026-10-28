@php
  $identity = $identity ?? \App\Support\PrintDocument::configuration();
  $orientation = ($orientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
@endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} - {{ $identity['business'] }}</title>
  <style>{!! file_get_contents(resource_path('css/print-document.css')) !!}
    @page { size: A4 {{ $orientation }}; margin: 14mm 12mm 18mm; @unless($pdfMode ?? false) @bottom-left { content: {!! \Illuminate\Support\Js::encode($identity['business'].' | '.$title) !!}; font: 7pt Arial; color: #647184; } @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 7pt Arial; color: #647184; } @endunless }
    @if($pdfMode ?? false) body { background: #fff; } .document { margin: 0; padding: 0; max-width: none; box-shadow: none; } @endif
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
    @php($summaryRows = count($summary) === 5 ? [array_slice($summary, 0, 2), array_slice($summary, 2)] : array_chunk($summary, count($summary) === 6 ? 3 : 4))
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
