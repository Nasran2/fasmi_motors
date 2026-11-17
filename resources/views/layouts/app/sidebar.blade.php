<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="workshop-shell min-h-screen bg-[#f7f9fc] text-slate-900">
        <x-workshop-sidebar />

        <script type="application/json" id="workshop-print-config">{!! \Illuminate\Support\Js::encode(\App\Support\PrintDocument::configuration() + [
            'title' => $title ?? 'Report',
            'reportUrl' => request()->routeIs('reports.*') ? request()->fullUrlWithQuery(['print' => 1, 'page' => null, 'export_csv' => null]) : null,
        ]) !!}</script>
        @php($layoutNotifications = app(\App\Services\WorkshopNotificationService::class)->summary(auth()->user()))
        <header class="workshop-topbar print:hidden">
            <x-workshop-menu-button />
            <button type="button" data-open-dialog="workshop-menu-search" class="workshop-search-button" aria-label="Search menu" aria-haspopup="dialog">
                <x-workshop-search-icon />
                <span class="truncate">Search menu</span>
            </button>
            <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                <x-cashier-control :show-history="false" />
                <x-workshop-notifications :summary="$layoutNotifications" />
                <x-workshop-header-more>
                    <x-cashier-control :show-control="false" />
                    <x-collection-control />
                    <button type="button" class="workshop-header-action" onclick="window.workshopPrint(event)">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                        Print
                    </button>
                    <button type="button" class="workshop-header-action" onclick="document.fullscreenElement ? document.exitFullscreen?.() : document.documentElement.requestFullscreen?.()">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                        Fullscreen
                    </button>
                    @if(session('shop_mode', 'workshop') === 'helmet')
                        <form method="POST" action="{{ route('shop.switch', ['mode' => 'workshop']) }}">@csrf<x-submission-token/><button class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-800 px-4 text-xs font-bold text-white shadow-lg hover:bg-slate-900 xl:text-sm">
                            🔧 Workshop Mode
                        </button></form>
                    @else
                        @if(auth()->user()->canAccess('job_card.view'))
                        <a href="{{ route('live-board.index') }}" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#0b2447] shadow-sm hover:border-red-200 hover:text-red-600" title="Live Bike Status Board"><span class="text-base">▦</span> Live Board</a>
                        <a href="{{ route('live-board.customer') }}" target="_blank" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-[#0b2447] shadow-sm hover:border-red-200 hover:text-red-600" title="Customer TV Display"><span class="text-base">📺</span> TV Board</a>
                        @endif
                        <form method="POST" action="{{ route('shop.switch', ['mode' => 'helmet']) }}">@csrf<x-submission-token/><button class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-indigo-600 px-4 text-xs font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 xl:text-sm">
                            🪖 Helmet Shop
                        </button></form>
                    @endif
                </x-workshop-header-more>
            </div>
            @if(session('shop_mode', 'workshop') !== 'helmet')
                @php($canTakeIntake = auth()->user()->canAccess('intake.add'))
                @php($canCreateJob = auth()->user()->canAccess('job_card.create'))
                @if($canTakeIntake || $canCreateJob)
                    <nav class="workshop-quick-actions" aria-label="Workshop quick actions">
                        @if($canTakeIntake)
                            <a href="{{ route('intakes.create') }}" class="btn-primary gap-2 !px-3">
                                <x-dashboard-icon name="bike" class="size-5 shrink-0" /> New Bike Intake
                            </a>
                        @endif
                        @if($canCreateJob)
                            <a href="{{ route('jobs.create') }}" class="btn-secondary gap-2 !px-3">
                                <x-dashboard-icon name="clipboard" class="size-5 shrink-0" /> Create Job Card
                            </a>
                        @endif
                    </nav>
                @endif
            @endif
        </header>

        {{ $slot }}

        @if(\App\Support\RoutePermission::allows(auth()->user(), 'sales.parts'))
            <a href="{{ route('sales.parts') }}"
               aria-label="Open POS"
               title="Open POS"
               class="workshop-pos-link fixed z-50 inline-flex h-14 items-center gap-2 rounded-full bg-red-600 px-5 text-sm font-bold text-white shadow-xl shadow-red-600/30 transition hover:bg-red-700 hover:shadow-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 print:hidden">
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
