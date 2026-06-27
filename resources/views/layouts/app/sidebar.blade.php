<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Main menu')" class="grid gap-1">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        <span class="block">Dashboard</span><span class="block text-[10px] opacity-60">Today summary & performance</span>
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="plus-circle" :href="route('intakes.index')" :current="request()->routeIs('intakes.*')"><span class="block">Bike Intake</span><span class="block text-[10px] opacity-60">New repair & customer bike</span></flux:sidebar.item>
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('jobs.index')" :current="request()->routeIs('jobs.*')"><span class="block">Job Cards</span><span class="block text-[10px] opacity-60">Technician work assignment</span></flux:sidebar.item>
                    <flux:sidebar.item icon="cube" :href="route('products.index')" :current="request()->routeIs('products.*')"><span class="block">Stock</span><span class="block text-[10px] opacity-60">Spare parts & store</span></flux:sidebar.item>
                    <flux:sidebar.item icon="banknotes" :href="route('finance.index')" :current="request()->routeIs('finance.*')"><span class="block">Accounts</span><span class="block text-[10px] opacity-60">Main, parts & bank ledgers</span></flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('technicians.index')" :current="request()->routeIs('technicians.*')"><span class="block">Technicians</span><span class="block text-[10px] opacity-60">Staff, salary & attendance</span></flux:sidebar.item>
                    <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')"><span class="block">Reports</span><span class="block text-[10px] opacity-60">Sales, profit & stock</span></flux:sidebar.item>
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('system-settings.index')" :current="request()->routeIs('system-settings.*')"><span class="block">Settings</span><span class="block text-[10px] opacity-60">System & account settings</span></flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

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
