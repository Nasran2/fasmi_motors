@props(['name', 'class' => 'size-5'])

@switch($name)
    @case('dashboard')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>
        @break
    @case('bike')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="16" r="3"/><circle cx="19" cy="16" r="3"/><path d="M5 16 9 9h5l5 7M9 9 7 7m7 2 3-3m-6 10h8"/></svg>
        @break
    @case('clipboard')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H6a2 2 0 0 0-2 2v14h16V7a2 2 0 0 0-2-2h-3"/><path d="M9 3h6v4H9zM8 12h8m-8 4h6"/></svg>
        @break
    @case('customers')
    @case('users')
    @case('technician')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.4-4 2.2-6 5.5-6s5.1 2 5.5 6M16 5.5a3 3 0 0 1 0 5.5m1 2c2.2.6 3.3 2.4 3.5 5"/></svg>
        @break
    @case('stock')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z"/><path d="m4 7 8 4 8-4m-8 4v10"/></svg>
        @break
    @case('purchase')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6H18a2 2 0 0 0 2-1.6L21 8H7"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
        @break
    @case('billing')
    @case('accounts')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h15a2 2 0 0 1 2 2v11H5a2 2 0 0 1-2-2V6Zm0 0 12-3v3"/><path d="M15 11h7v5h-7a2.5 2.5 0 0 1 0-5Z"/></svg>
        @break
    @case('expense')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z"/><path d="M8 8h8m-8 4h8m-8 4h5"/></svg>
        @break
    @case('reminder')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l3 2M8 3h8"/></svg>
        @break
    @case('sms')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H8l-4 4V4Z"/><path d="M8 9h8m-8 4h5"/></svg>
        @break
    @case('reports')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10h4v10m4 0V4h4v16m4 0v-7h-4"/><path d="M2 20h20"/></svg>
        @break
    @case('settings')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg>
        @break
    @default
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8"/><path d="M12 8v8m-4-4h8"/></svg>
@endswitch
