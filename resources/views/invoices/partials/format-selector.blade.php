<nav class="format-selector" aria-label="Paper size">
    @foreach(['a4' => 'A4', 'a5' => 'A5', '80mm' => '80 mm'] as $paperFormat => $paperLabel)
        <a class="action format-option" href="{{ route('invoices.public', array_merge(['token' => $invoice->public_token, 'format' => $paperFormat], $presentationQuery)) }}" @if($format === $paperFormat) aria-current="page" @endif>{{ $paperLabel }}</a>
    @endforeach
</nav>
