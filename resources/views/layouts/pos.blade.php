<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <style>
            /* Custom POS styles */
            body.pos-shell {
                background-color: #f7f9fc;
                color: #0f172a; /* slate-900 */
                margin: 0;
                padding: 0;
                height: 100vh;
                display: flex;
                flex-direction: column;
                overflow: hidden; /* Prevent full body scroll, let panels scroll */
            }
            .pos-header {
                background-color: #06162f;
                color: #ffffff;
                height: 72px;
                display: flex;
                align-items: center;
                padding: 0 1rem;
                flex-shrink: 0;
                position: relative;
                z-index: 50;
            }
            .pos-main {
                flex: 1;
                display: flex;
                overflow: hidden;
            }
            .pos-left {
                flex: 1;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                background: #f7f9fc;
            }
            .pos-right {
                width: 440px;
                background-color: #ffffff;
                border-left: 1px solid #e2e8f0;
                display: flex;
                flex-direction: column;
                flex-shrink: 0;
                z-index: 40;
                box-shadow: -4px 0 15px rgba(0,0,0,0.03);
            }
            @media (max-width: 1200px) {
                .pos-right {
                    width: 390px;
                }
            }
            @media (max-width: 768px) {
                .pos-main {
                    flex-direction: column;
                }
                .pos-right {
                    width: 100%;
                    height: 50vh;
                    border-left: none;
                    border-top: 1px solid #e2e8f0;
                }
            }
        </style>
    </head>
    <body class="pos-shell">
        <header class="pos-header shadow-xl">
            <!-- Left Side -->
            <div class="flex items-center gap-4 w-1/4">
                <a href="{{ route('dashboard') }}" class="grid size-10 place-items-center rounded-xl hover:bg-white/10 transition">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </a>
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 group">
                    <x-workshop-mark class="size-10 shadow-[0_0_20px_rgba(239,23,45,.2)] ring-1 ring-red-500/35 transition group-hover:ring-red-400/70" />
                    <span class="hidden sm:block whitespace-nowrap text-[18px] font-black italic leading-none tracking-[-0.06em]">
                        <span class="text-[#ff263d]">FASMIR</span> <span class="text-white">MOTORS</span>
                    </span>
                </a>
            </div>

            <!-- Center (Injected from view if needed, or handled inside the view) -->
            <div class="flex-1 flex justify-center px-4" id="pos-header-center">
                <!-- Slot for search bar -->
                @yield('pos-search')
            </div>

            <!-- Right Side -->
            <div class="flex items-center justify-end gap-3 w-1/4">
                <x-cashier-control /><x-collection-control />
                @yield('pos-actions')
                
                <div class="ml-2 flex items-center gap-2 border-l border-white/20 pl-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-sm font-bold text-white ring-2 ring-white/20">
                        {{ auth()->user()->initials() }}
                    </span>
                    <div class="hidden xl:block text-right">
                        <p class="text-sm font-bold leading-tight">{{ auth()->user()->username ?: auth()->user()->name }}</p>
                        <div class="flex items-center justify-end gap-1 mt-0.5">
                            <span class="size-2 rounded-full bg-green-500"></span>
                            <p class="text-[9px] uppercase tracking-wider text-slate-300">{{ auth()->user()->role?->name ?? 'Admin' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="pos-main">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
        @stack('scripts')
    </body>
</html>
