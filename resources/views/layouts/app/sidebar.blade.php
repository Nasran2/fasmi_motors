<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="workshop-shell min-h-screen bg-[#f7f9fc] text-slate-900">
        <flux:sidebar sticky collapsible="mobile" class="workshop-sidebar border-e-0 !bg-[#06162f] text-white shadow-2xl" style="background-color:#06162f;color:#fff">
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true"><div class="absolute -right-20 -top-24 size-64 rounded-full bg-red-600/10 blur-3xl"></div><div class="absolute bottom-20 left-0 size-48 rounded-full bg-blue-500/10 blur-3xl"></div></div>
            <flux:sidebar.header class="relative border-b border-white/10 px-2 pb-5 pt-3">
                <a href="{{ route('dashboard') }}" class="group flex items-center gap-3 rounded-2xl px-1 py-1" wire:navigate>
                    <x-workshop-mark class="size-12 shrink-0 shadow-[0_0_30px_rgba(239,23,45,.18)] ring-1 ring-red-500/35 transition group-hover:ring-red-400/70" />
                    <span class="min-w-0"><span class="block whitespace-nowrap text-[20px] font-black italic leading-none tracking-[-0.06em]"><span class="text-[#ff263d]">FASMIR</span> <span class="text-white">MOTORS</span></span><span class="mt-2 block whitespace-nowrap text-[9px] font-semibold uppercase tracking-[.12em] text-slate-400">Bike Repair Center System</span></span>
                </a>
                
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="mt-4 px-3 relative z-10">
                @if(session('shop_mode', 'workshop') === 'helmet')
                    <a href="{{ route('shop.switch', ['mode' => 'workshop']) }}" class="flex w-full items-center justify-center gap-2 rounded-xl bg-slate-800 px-3 py-2.5 text-[11px] font-bold text-white hover:bg-slate-700 transition">
                        🔧 Switch to Workshop
                    </a>
                @else
                    <a href="{{ route('shop.switch', ['mode' => 'helmet']) }}" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 py-2.5 text-[11px] font-bold text-white hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/20">
                        🪖 Switch to Helmet Shop
                    </a>
                @endif
            </div>

            <x-erp-sidebar-menu />

            <div class="relative mt-3 border-t border-white/10 bg-[#06162f]/80 pt-3 backdrop-blur"><x-desktop-user-menu :name="auth()->user()->name" /></div>
        </flux:sidebar>

        @php($layoutNotificationCount = \App\Models\Invoice::where('due_amount','>',0)->count() + \App\Models\ServiceReminder::where('status','Pending')->whereDate('due_date','<=',today()->addDays(3))->count())
        <header class="workshop-topbar [grid-area:header] sticky top-0 z-30 hidden h-[68px] items-center gap-4 border-b border-slate-200 bg-white/95 px-5 shadow-sm backdrop-blur lg:flex">
            <button type="button" aria-label="Menu" class="grid size-10 shrink-0 place-items-center rounded-lg text-[#0b2447] hover:bg-slate-100"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            <form action="{{ route('search') }}" class="relative w-full max-w-xl"><svg class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input name="q" minlength="2" required placeholder="Search by bike no, customer, job card..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none focus:border-red-400 focus:bg-white focus:ring-4 focus:ring-red-50"></form>
            <div class="ml-auto flex items-center gap-3">
                <span class="relative grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 cursor-pointer" title="Notifications">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M10 21h4"/></svg>
                    @if($layoutNotificationCount)<span class="absolute right-0 top-0 grid size-5 place-items-center rounded-full bg-red-600 text-[9px] font-bold text-white">{{ min(99,$layoutNotificationCount) }}</span>@endif
                </span>
                <span class="grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 cursor-pointer" onclick="window.print()" title="Print">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                </span>
                <span class="grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 cursor-pointer" onclick="document.documentElement.requestFullscreen()" title="Fullscreen">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                </span>
                @if(session('shop_mode', 'workshop') === 'helmet')
                    <a href="{{ route('shop.switch', ['mode' => 'workshop']) }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-800 px-4 text-xs font-bold text-white shadow-lg hover:bg-slate-900 xl:text-sm">
                        🔧 Workshop Mode
                    </a>
                @else
                    <a href="{{ route('live-board.index') }}" class="hidden h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#0b2447] shadow-sm hover:border-red-200 hover:text-red-600 xl:inline-flex" title="Live Bike Status Board"><span class="text-base">▦</span> Live Board</a>
                    <a href="{{ route('intakes.create') }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-red-600 px-3 text-xs font-bold text-white shadow-lg shadow-red-200 hover:bg-red-700 xl:px-4 xl:text-sm"><x-dashboard-icon name="bike" class="size-5"/> New Bike Intake</a>
                    <a href="{{ route('jobs.create') }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#0b2447] px-3 text-xs font-bold text-white shadow-lg shadow-slate-200 hover:bg-[#123665] xl:px-4 xl:text-sm"><x-dashboard-icon name="clipboard" class="size-5"/> Create Job Card</a>
                    <a href="{{ route('shop.switch', ['mode' => 'helmet']) }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-indigo-600 px-4 text-xs font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 xl:text-sm">
                        🪖 Helmet Shop
                    </a>
                @endif
                <div class="ml-1 flex items-center gap-2 border-l border-slate-200 pl-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-[#0b2447] text-sm font-bold text-white ring-4 ring-slate-100">{{ auth()->user()->initials() }}</span><div class="hidden xl:block"><p class="text-sm font-bold">{{ auth()->user()->username ?: auth()->user()->name }}</p><p class="text-[10px] text-slate-500">{{ auth()->user()->role?->name ?? 'Staff' }}</p></div></div>
            </div>
        </header>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-slate-200 bg-white lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" data-testid="mobile-sidebar-toggle" />

            <span class="ml-2 text-sm font-black italic"><span class="text-red-600">FASMIR</span> MOTORS</span>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
