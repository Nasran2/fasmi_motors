@props([
    'href',
    'icon',
    'title',
    'subtitle',
    'active' => false,
])

<flux:sidebar.item
    :icon="$icon"
    :href="$href"
    :current="$active"
    wire:navigate
    class="workshop-nav-item {{ $active ? 'workshop-nav-item-active' : '' }}"
>
    <span class="block min-w-0">
        <span class="block truncate text-[13px] font-bold leading-4 tracking-[-0.01em]">{{ $title }}</span>
        <span class="mt-1 block truncate text-[10px] font-medium leading-3 opacity-55">{{ $subtitle }}</span>
    </span>
</flux:sidebar.item>
