@if(\App\Support\RoutePermission::allows(auth()->user(), 'cashier.index'))
    @php($cashierOpen = \App\Models\CashierSession::isOpenToday())
    <a href="{{ route('cashier.index') }}" @if(!$cashierOpen) data-cashier-open="{{ route('cashier.opening-popup') }}" @endif {{ $attributes->class('inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl border-2 px-3 py-2 text-xs font-black shadow-lg '.($cashierOpen ? 'border-emerald-300 bg-emerald-600 text-white shadow-emerald-200 hover:bg-emerald-700' : 'border-amber-300 bg-amber-400 text-slate-950 shadow-amber-200 hover:bg-amber-300')) }}>
        <span class="size-2.5 rounded-full {{ $cashierOpen ? 'bg-white' : 'bg-red-600' }}" aria-hidden="true"></span>
        <span><span class="block text-[9px] uppercase tracking-widest">Cashier {{ $cashierOpen ? 'open' : 'closed' }}</span><span class="block">{{ $cashierOpen ? 'Close Cashier' : 'Open Cashier' }}</span></span>
    </a>
@endif
