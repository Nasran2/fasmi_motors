<dialog id="workshop-sidebar" class="workshop-sidebar print:hidden" aria-label="Workshop menu">
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true"><div class="absolute -right-20 -top-24 size-64 rounded-full bg-red-600/10 blur-3xl"></div><div class="absolute bottom-20 left-0 size-48 rounded-full bg-blue-500/10 blur-3xl"></div></div>
    <div class="relative flex items-center justify-between gap-1 border-b border-white/10 pb-4 pt-2">
        <a href="{{ route('dashboard') }}" class="workshop-sidebar-brand group flex min-w-0 items-center gap-2 rounded-2xl px-1 py-1" wire:navigate>
            <x-workshop-mark class="size-12 shrink-0 shadow-[0_0_30px_rgba(239,23,45,.18)] ring-1 ring-red-500/35 transition group-hover:ring-red-400/70" />
            <span class="min-w-0"><span class="block whitespace-nowrap text-[20px] font-black italic leading-none tracking-[-0.06em]"><span class="text-[#ff263d]">FASMIR</span> <span class="text-white">MOTORS</span></span><span class="mt-2 block whitespace-nowrap text-[9px] font-semibold uppercase tracking-[.12em] text-slate-400">Bike Repair Center System</span></span>
        </a>
        <button type="button" data-close-dialog class="workshop-sidebar-close grid size-8 shrink-0 place-items-center rounded-lg text-white hover:bg-white/10" aria-label="Close menu" autofocus>
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
    </div>

    @php($helmetMode = session('shop_mode', 'workshop') === 'helmet')
    <div class="relative z-10 mt-4 mb-3 flex shrink-0 items-center gap-2 px-1">
        <form method="POST" action="{{ route('shop.switch', ['mode' => $helmetMode ? 'workshop' : 'helmet']) }}" class="min-w-0 flex-1">
            @csrf
            <x-submission-token />
            <button type="submit" @class([
                'flex min-h-11 w-full items-center justify-center gap-1 rounded-xl px-2 py-2 text-center text-[11px] leading-tight font-bold text-white transition',
                'bg-slate-800 hover:bg-slate-700' => $helmetMode,
                'bg-indigo-600 shadow-lg shadow-indigo-500/20 hover:bg-indigo-500' => !$helmetMode,
            ])>
                <span aria-hidden="true">{{ $helmetMode ? '🔧' : '🪖' }}</span>
                <span>{{ $helmetMode ? 'Switch to Workshop' : 'Switch to Helmet Shop' }}</span>
            </button>
        </form>
        <button type="button" data-open-dialog="workshop-menu-search" class="workshop-menu-button border border-white/15 bg-white/5" aria-label="Search menu" aria-controls="workshop-menu-search" aria-haspopup="dialog" title="Search menu">
            <x-workshop-search-icon />
        </button>
    </div>

    <x-erp-sidebar-menu />

    <div class="relative mt-3 border-t border-white/10 bg-[#06162f]/80 pt-3 backdrop-blur"><x-desktop-user-menu :name="auth()->user()->name" /></div>
</dialog>

<x-workshop-search-dialog id="workshop-menu-search" title="Search menu" data-menu-search-dialog>
    <form class="workshop-search-row" data-menu-search-form>
        <input id="sidebar-menu-search" type="search" class="field" aria-label="Search menu" placeholder="Search pages and actions" autocomplete="off" autofocus>
        <button type="submit" class="btn-primary">Open</button>
    </form>
    <p class="mt-4 mb-2 text-xs font-semibold text-slate-500" data-search-summary aria-live="polite"></p>
    <ul class="workshop-search-results" data-search-results></ul>
</x-workshop-search-dialog>
