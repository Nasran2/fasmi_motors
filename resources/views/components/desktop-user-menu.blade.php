@props(['name'])

<div {{ $attributes }}>
    <flux:dropdown position="top" align="start">
        <button type="button" data-test="sidebar-menu-button" class="flex w-full items-center gap-3 rounded-xl border border-white/10 bg-white/[.055] p-2 text-left transition hover:bg-white/[.09]">
            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-slate-500 to-slate-700 text-xs font-extrabold text-white ring-1 ring-white/15">{{ auth()->user()->initials() }}</span>
            <span class="min-w-0 flex-1"><span class="block truncate text-xs font-bold text-white">{{ $name }}</span><span class="mt-0.5 block truncate text-[9px] font-semibold uppercase tracking-wider text-slate-500">{{ auth()->user()->role?->name ?? 'Staff' }}</span></span>
            <svg class="size-4 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 10 4 4 4-4"/></svg>
        </button>

        <flux:menu class="min-w-56">
            <div class="flex items-center gap-2 px-2 py-2 text-start text-sm">
                <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                <div class="min-w-0 flex-1"><p class="truncate font-semibold">{{ auth()->user()->name }}</p><p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p></div>
            </div>
            <flux:menu.separator />
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>Profile Settings</flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf<x-submission-token/>
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">Log out</flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
