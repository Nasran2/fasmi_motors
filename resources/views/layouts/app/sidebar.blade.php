<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="workshop-shell min-h-screen bg-[#f7f9fc] text-slate-900">
        <flux:sidebar sticky collapsible="mobile" class="workshop-sidebar border-e-0 !bg-[#06162f] text-white shadow-2xl print:hidden" style="background-color:#06162f;color:#fff">
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
                    <form method="POST" action="{{ route('shop.switch', ['mode' => 'workshop']) }}">@csrf<x-submission-token/><button class="flex w-full items-center justify-center gap-2 rounded-xl bg-slate-800 px-3 py-2.5 text-[11px] font-bold text-white hover:bg-slate-700 transition">
                        🔧 Switch to Workshop
                    </button></form>
                @else
                    <form method="POST" action="{{ route('shop.switch', ['mode' => 'helmet']) }}">@csrf<x-submission-token/><button class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 py-2.5 text-[11px] font-bold text-white hover:bg-indigo-500 transition shadow-lg shadow-indigo-500/20">
                        🪖 Switch to Helmet Shop
                    </button></form>
                @endif
            </div>

            <x-erp-sidebar-menu />

            <div class="relative mt-3 border-t border-white/10 bg-[#06162f]/80 pt-3 backdrop-blur"><x-desktop-user-menu :name="auth()->user()->name" /></div>
        </flux:sidebar>

        <script type="application/json" id="workshop-print-config">{!! \Illuminate\Support\Js::encode(\App\Support\PrintDocument::configuration() + [
            'title' => $title ?? 'Report',
            'reportUrl' => request()->routeIs('reports.*') ? request()->fullUrlWithQuery(['print' => 1, 'page' => null, 'export_csv' => null]) : null,
        ]) !!}</script>
        @php($layoutNotifications = app(\App\Services\WorkshopNotificationService::class)->summary(auth()->user()))
        <header class="workshop-topbar [grid-area:header] sticky top-0 z-30 hidden h-[68px] items-center gap-4 border-b border-slate-200 bg-white/95 px-5 shadow-sm backdrop-blur lg:flex print:hidden">
            <button type="button" aria-label="Menu" class="grid size-10 shrink-0 place-items-center rounded-lg text-[#0b2447] hover:bg-slate-100"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            
            <div class="ml-auto flex items-center gap-3">
                <x-workshop-notifications :summary="$layoutNotifications" />
                <span class="grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 cursor-pointer" onclick="window.workshopPrint(event)" title="Print">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                </span>
                <span class="grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 cursor-pointer" onclick="document.documentElement.requestFullscreen()" title="Fullscreen">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                </span>
                @if(session('shop_mode', 'workshop') === 'helmet')
                    <form method="POST" action="{{ route('shop.switch', ['mode' => 'workshop']) }}">@csrf<x-submission-token/><button class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-800 px-4 text-xs font-bold text-white shadow-lg hover:bg-slate-900 xl:text-sm">
                        🔧 Workshop Mode
                    </button></form>
                @else
                    @if(auth()->user()->canAccess('job_card.view'))
                    <a href="{{ route('live-board.index') }}" class="hidden h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#0b2447] shadow-sm hover:border-red-200 hover:text-red-600 2xl:inline-flex" title="Live Bike Status Board"><span class="text-base">▦</span> Live Board</a>
                    <a href="{{ route('live-board.customer') }}" target="_blank" class="hidden h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#0b2447] shadow-sm hover:border-red-200 hover:text-red-600 2xl:inline-flex" title="Customer TV Display"><span class="text-base">📺</span> TV Board</a>
                    @endif
                    @if(auth()->user()->canAccess('intake.add'))
                    <a href="{{ route('intakes.create') }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-red-600 px-3 text-xs font-bold text-white shadow-lg shadow-red-200 hover:bg-red-700 xl:px-4 xl:text-sm"><x-dashboard-icon name="bike" class="size-5"/> New Bike Intake</a>
                    @endif
                    @if(auth()->user()->canAccess('job_card.create'))
                    <a href="{{ route('jobs.create') }}" class="hidden h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#0b2447] px-4 text-sm font-bold text-white shadow-lg shadow-slate-200 hover:bg-[#123665] 2xl:inline-flex"><x-dashboard-icon name="clipboard" class="size-5"/> Create Job Card</a>
                    @endif
                    <form method="POST" action="{{ route('shop.switch', ['mode' => 'helmet']) }}">@csrf<x-submission-token/><button class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-indigo-600 px-4 text-xs font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 xl:text-sm">
                        🪖 Helmet Shop
                    </button></form>
                @endif
                <div class="ml-1 flex items-center gap-2 border-l border-slate-200 pl-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-[#0b2447] text-sm font-bold text-white ring-4 ring-slate-100">{{ auth()->user()->initials() }}</span><div class="hidden 2xl:block"><p class="text-sm font-bold">{{ auth()->user()->username ?: auth()->user()->name }}</p><p class="text-[10px] text-slate-500">{{ auth()->user()->role?->name ?? 'Staff' }}</p></div></div>
            </div>
        </header>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-slate-200 bg-white lg:hidden print:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" data-testid="mobile-sidebar-toggle" />

            <span class="ml-2 text-sm font-black italic"><span class="text-red-600">FASMIR</span> MOTORS</span>

            <flux:spacer />

            <x-workshop-notifications :summary="$layoutNotifications" />

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
                        @csrf<x-submission-token/>
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

        @if(\App\Support\RoutePermission::allows(auth()->user(), 'sales.parts'))
            <a href="{{ route('sales.parts') }}"
               aria-label="Open POS"
               title="Open POS"
               class="fixed bottom-6 right-6 z-50 inline-flex h-14 items-center gap-2 rounded-full bg-red-600 px-5 text-sm font-bold text-white shadow-xl shadow-red-600/30 transition hover:bg-red-700 hover:shadow-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 print:hidden">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 10h11.6l2-7H6"/><circle cx="9" cy="19" r="1"/><circle cx="18" cy="19" r="1"/></svg>
                <span>POS</span>
            </a>
        @endif

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
