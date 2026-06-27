@props(['class' => ''])
<span {{ $attributes->merge(['class' => 'inline-grid place-items-center rounded-full bg-[#071423] '.$class]) }} aria-hidden="true">
    <svg viewBox="0 0 64 64" class="h-[72%] w-[72%]" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="18" cy="37" r="9" stroke="#ef172d" stroke-width="4"/>
        <circle cx="47" cy="37" r="9" stroke="#ef172d" stroke-width="4"/>
        <path d="M18 37 27 24h10l10 13M26 25l-6-5m17 4 7-6m-15 19h18M26 25l6 12" stroke="#ef172d" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M15 52h34M21 48l-5 8m27-8 5 8" stroke="white" stroke-width="4" stroke-linecap="round"/>
    </svg>
</span>
