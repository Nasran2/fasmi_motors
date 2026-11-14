@props(['method'])

<option value="">Automatic allocation</option>
@foreach(\App\Models\Payment::RECEIVING_ACCOUNTS as $destination => $label)
    <option value="{{ $destination }}" :disabled="({{ $method }} === 'Cash') === @js($destination === 'bank')">{{ $label }}</option>
@endforeach
