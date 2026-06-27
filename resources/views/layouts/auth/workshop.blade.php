@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
<head>
    @include('partials.head')
</head>
<body class="m-0 min-h-screen bg-[#f4f6f9] font-sans text-slate-950 antialiased">
    <main class="min-h-svh lg:grid lg:grid-cols-[minmax(0,1.08fr)_minmax(440px,.92fr)]">
        <section class="workshop-hero relative isolate min-h-[150px] overflow-hidden bg-[#02070b] text-white lg:min-h-svh">
            <img src="{{ asset('images/login-motorcycle.png') }}" alt="" class="absolute inset-0 z-0 h-full w-full object-cover object-[54%_54%] lg:object-[58%_58%]" aria-hidden="true">
            <div class="absolute inset-0 z-10 bg-[linear-gradient(90deg,rgba(1,7,13,.98)_0%,rgba(1,8,15,.95)_38%,rgba(1,8,15,.56)_68%,rgba(1,8,15,.72)_100%)]"></div>
            <div class="absolute inset-0 z-10 bg-[linear-gradient(0deg,rgba(1,7,13,.72)_0%,transparent_46%,rgba(1,7,13,.35)_100%)]"></div>
            <div class="absolute left-0 top-0 z-10 h-full w-1 bg-[#ed1028] lg:w-1.5"></div>

            <div class="relative z-20 mx-auto flex h-full max-w-3xl flex-col px-5 py-5 sm:px-8 sm:py-7 lg:px-12 lg:py-10 xl:px-16 xl:py-12">
                <a href="{{ route('home') }}" class="inline-flex w-fit flex-col" wire:navigate>
                    <span class="text-3xl font-black italic leading-none tracking-tight sm:text-4xl lg:text-5xl xl:text-6xl">
                        <span class="text-[#ef172d]">FASMIR</span> <span class="text-white">MOTORS</span>
                    </span>
                    <span class="mt-2 h-1 w-24 bg-[#ef172d] shadow-[0_0_18px_rgba(239,23,45,.55)] lg:w-40"></span>
                    <span class="mt-2 text-xs font-medium tracking-wide text-slate-300 sm:text-sm lg:mt-3 lg:text-base">Bike Repair Center Management System</span>
                </a>

                <div class="mt-8 hidden max-w-[34rem] lg:block xl:mt-12">
                    <span class="block h-0.5 w-14 bg-[#ef172d]"></span>
                    <h1 class="mt-5 max-w-lg text-2xl font-bold leading-snug drop-shadow-lg xl:text-[2rem]">Run every workshop operation<br>from one powerful system.</h1>
                    <p class="mt-3 max-w-md text-sm leading-relaxed text-slate-300">Fast job tracking, accurate stock and clear accounts—built for the daily rhythm of Fasmir Motors.</p>
                    <div class="mt-6 grid max-w-[29rem] gap-2.5 xl:mt-8">
                        @foreach([
                            ['clipboard', 'Job Card Management', 'Create, assign and track job cards easily.'],
                            ['user', 'Technician Tracking', 'Monitor technician activities and performance.'],
                            ['gear', 'Spare Parts Stock', 'Manage inventory and get low stock alerts.'],
                            ['calculator', 'Daily Accounts', 'Track income, expenses and workshop profitability.'],
                        ] as [$icon, $title, $copy])
                            <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/25 px-3 py-2.5 shadow-lg shadow-black/10 backdrop-blur-[3px]">
                                <span class="grid size-11 shrink-0 place-items-center rounded-xl border border-white/15 bg-[#07111d]/90 text-[#ef172d] shadow-[inset_0_1px_rgba(255,255,255,.08)]">
                                    @if($icon === 'clipboard')
                                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5h6m-7 0H6a2 2 0 0 0-2 2v12h16V7a2 2 0 0 0-2-2h-2M9 3h6v4H9V3Z"/><path d="m8 13 2 2 5-5"/></svg>
                                    @elseif($icon === 'user')
                                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="7" r="4"/><path d="M4 21c.6-5 3-8 8-8s7.4 3 8 8M4 18h4m8 0h4"/></svg>
                                    @elseif($icon === 'gear')
                                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2.2-.6-.7-1.7 1.1-2-2.1-2.1-2 1.1-1.7-.7L10.5 2h-3l-.6 2.5-1.7.7-2-1.1-2.1 2.1 1.1 2-.7 1.7-2.5.6v3l2.5.6.7 1.7-1.1 2 2.1 2.1 2-1.1 1.7.7.6 2.5h3l.6-2.5 1.7-.7 2 1.1 2.1-2.1-1.1-2 .7-1.7 2.5-.6Z" transform="translate(1.5) scale(.88)"/></svg>
                                    @else
                                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 5h8v4H8zM8 13h2m2 0h2m2 0h1M8 17h2m2 0h2m2 0h1"/></svg>
                                    @endif
                                </span>
                                <div><h2 class="text-sm font-semibold xl:text-base">{{ $title }}</h2><p class="mt-0.5 max-w-xs text-xs leading-relaxed text-slate-300/80 xl:text-sm">{{ $copy }}</p></div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>
        </section>

        <section class="relative flex min-h-[calc(100svh-132px)] items-start justify-center overflow-hidden px-4 py-6 sm:items-center sm:px-8 sm:py-10 lg:min-h-svh lg:px-10">
            <div class="absolute -right-20 -top-24 size-72 rotate-45 bg-white/70"></div>
            <div class="absolute bottom-0 right-0 h-40 w-40 opacity-20 [background-image:radial-gradient(#64748b_1.2px,transparent_1.2px)] [background-size:12px_12px]"></div>
            <div class="relative w-full max-w-[32rem] rounded-3xl border border-white/80 bg-white p-5 shadow-[0_20px_60px_rgba(15,23,42,.14)] sm:p-8 lg:p-10">
                {{ $slot }}
            </div>
        </section>
    </main>
    @fluxScripts
</body>
</html>
