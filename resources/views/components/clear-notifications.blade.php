@props(['keys', 'label' => 'Clear', 'description' => 'Clear notification'])

<form method="POST" action="{{ route('notifications.clear') }}" class="shrink-0">
    @csrf<x-submission-token/>
    @foreach($keys as $key)
        <input type="hidden" name="keys[]" value="{{ $key }}">
    @endforeach
    <button type="submit" aria-label="{{ $description }}" title="{{ $description }}" class="rounded-lg px-2 py-1 text-xs font-bold text-slate-500 hover:bg-red-50 hover:text-red-600 focus-visible:outline-2 focus-visible:outline-red-600">{{ $label }}</button>
</form>
