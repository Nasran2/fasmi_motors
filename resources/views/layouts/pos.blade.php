<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="pos-shell">
        <x-workshop-sidebar />
        <div class="pos-workspace" x-data="posApp()" @keydown.window="handleShortcut($event)">
            <header class="pos-header">
                <x-workshop-menu-button />
                <a href="{{ route('dashboard') }}" class="pos-brand hidden shrink-0 items-center gap-2 lg:flex" aria-label="Dashboard">
                    <x-workshop-mark class="size-9" />
                    <span class="hidden whitespace-nowrap text-base font-black italic xl:inline"><span class="text-[#ff263d]">FASMIR</span> MOTORS</span>
                </a>
                @yield('pos-search')
                <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                    <x-cashier-control :show-history="false" />
                    <x-workshop-header-more>
                        <x-cashier-control :show-control="false" />
                        <x-collection-control />
                        @yield('pos-actions')
                        <a href="{{ route('dashboard') }}" class="workshop-header-action">Dashboard</a>
                    </x-workshop-header-more>
                </div>
            </header>

            <main class="pos-main">
                {{ $slot }}
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
        @stack('scripts')
    </body>
</html>
