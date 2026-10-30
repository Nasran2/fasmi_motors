@if(\App\Support\RoutePermission::allows(auth()->user(), 'cashier.index'))
    @php
        $activeSession = \App\Models\CashierSession::active();
        $isToday = $activeSession?->session_date?->isToday();
        $hasOpenSession = (bool) $activeSession;
    @endphp
    @if($hasOpenSession)
        <a href="{{ route('cashier.index', ['date' => $activeSession->session_date->toDateString()]) }}" data-cashier-closing="{{ route('cashier.closing-popup', $activeSession) }}" {{ $attributes->class('inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl border-2 px-3 py-2 text-xs font-black shadow-lg ' . ($isToday ? 'border-emerald-300 bg-emerald-600 text-white shadow-emerald-200 hover:bg-emerald-700' : 'border-amber-400 bg-amber-500 text-slate-950 shadow-amber-200 hover:bg-amber-400')) }}>
            <span class="size-2.5 rounded-full bg-white animate-pulse" aria-hidden="true"></span>
            <span>
                <span class="block text-[9px] uppercase tracking-widest">{{ $isToday ? 'Cashier Open' : 'Open (' . $activeSession->session_date->format('d M') . ')' }}</span>
                <span class="block">{{ $isToday ? 'Close Cashier' : 'Close ' . $activeSession->session_date->format('d M') }}</span>
            </span>
        </a>
    @else
        <a href="{{ route('cashier.index') }}" data-cashier-open="{{ route('cashier.opening-popup') }}" {{ $attributes->class('inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl border-2 px-3 py-2 text-xs font-black shadow-lg border-amber-300 bg-amber-400 text-slate-950 shadow-amber-200 hover:bg-amber-300') }}>
            <span class="size-2.5 rounded-full bg-red-600" aria-hidden="true"></span>
            <span>
                <span class="block text-[9px] uppercase tracking-widest">Cashier Closed</span>
                <span class="block">Open Cashier</span>
            </span>
        </a>
    @endif
@endif

@if(\App\Support\RoutePermission::allows(auth()->user(), 'cashier.index'))
    <a href="{{ route('cashier.index') }}#cashier-history" class="inline-flex shrink-0 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm">Closing history</a>
@endif
