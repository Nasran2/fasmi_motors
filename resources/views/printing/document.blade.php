@component('printing.document-layout', ['title' => $document['title'], 'identity' => $document, 'period' => $document['period'], 'orientation' => $document['orientation'], 'filters' => $document['filters'], 'summary' => $document['summary'], 'autoPrint' => $autoPrint ?? false, 'pdfMode' => $pdfMode ?? false])
  @foreach($document['sections'] as $section)
    <section class="document-section">@if($section['heading'])<h2 class="document-section-title">{{ $section['heading'] }}</h2>@endif{!! $section['html'] !!}</section>
  @endforeach
@endcomponent
