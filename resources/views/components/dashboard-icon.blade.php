@props(['name', 'class' => 'size-5'])
@switch($name)
    @case('bike')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="16" r="3"/><circle cx="19" cy="16" r="3"/><path d="M5 16 9 9h5l5 7M9 9 7 7m7 2 3-3m-6 10h8m-9-7 3 7"/></svg>
        @break
    @case('clipboard')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H6a2 2 0 0 0-2 2v14h16V7a2 2 0 0 0-2-2h-3"/><path d="M9 3h6v4H9zM8 12h8m-8 4h6"/></svg>
        @break
    @case('check')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
        @break
    @case('money')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.7-1-3-1-1.7 0-3 .8-3 2s1.3 1.8 3 2 3 1 3 2.3-1.3 2.2-3 2.2c-1.2 0-2.3-.4-3-1.1M12 5v14"/></svg>
        @break
    @case('parts')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z"/><path d="m4 7 8 4 8-4m-8 4v10"/></svg>
        @break
    @case('wallet')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h15a2 2 0 0 1 2 2v11H5a2 2 0 0 1-2-2V6Zm0 0 12-3v3"/><path d="M15 11h7v5h-7a2.5 2.5 0 0 1 0-5Z"/></svg>
        @break
    @case('bank')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 9 9-6 9 6H3Zm2 0v9m5-9v9m4-9v9m5-9v9M3 21h18"/></svg>
        @break
    @case('warning')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5m0 3v.1"/></svg>
        @break
    @default
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/></svg>
@endswitch
