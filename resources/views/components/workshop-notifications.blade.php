@props(['summary'])

<div class="workshop-notifications relative" x-data="{ open: false }" x-id="['notifications-panel']" @keydown.escape.stop.prevent="open = false; $refs.bell.focus()" @click.outside="open = false" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <button type="button" x-ref="bell" @click="open = !open" :aria-expanded="open" :aria-controls="$id('notifications-panel')" aria-label="Notifications ({{ $summary['count'] }})" title="Notifications" class="relative grid size-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-red-600">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M10 21h4"/></svg>
        @if($summary['count'])
            <span class="absolute right-0 top-0 grid size-5 place-items-center rounded-full bg-red-600 text-[9px] font-bold text-white">{{ $summary['count'] > 99 ? '99+' : $summary['count'] }}</span>
        @endif
    </button>
    <div x-show="open" style="display: none" :id="$id('notifications-panel')" role="region" aria-label="Notifications" class="absolute right-0 top-full z-50 mt-3 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-extrabold">Notifications</h2>
            <div class="flex items-center gap-1">
                @if($summary['count'])
                    <x-clear-notifications :keys="$summary['keys']" label="Clear all" description="Clear all notifications" />
                @endif
                <button type="button" @click="open = false; $refs.bell.focus()" aria-label="Close notifications" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100">×</button>
            </div>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            @if($summary['birthdays'])
                <h3 class="bg-amber-50 px-4 py-2 text-xs font-bold text-amber-800">Staff Birthdays · Today & next 7 days</h3>
                @foreach($summary['birthdays'] as $birthday)
                    <div class="flex items-center gap-1 border-b border-slate-100 px-3 py-2">
                        <a href="{{ $birthday['url'] }}" class="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-1 py-1 hover:bg-slate-50">
                            <span aria-hidden="true">🎂</span>
                            <span class="min-w-0"><b class="block break-words text-sm">{{ $birthday['name'] }}</b><span class="text-xs text-slate-500">{{ $birthday['days'] === 0 ? 'Birthday today!' : ($birthday['days'] === 1 ? 'Birthday tomorrow' : 'Birthday in '.$birthday['days'].' days') }} · {{ $birthday['date'] }}</span></span>
                        </a>
                        <x-clear-notifications :keys="$birthday['keys']" :description="'Clear birthday notification for '.$birthday['name']" />
                    </div>
                @endforeach
            @endif
            @foreach($summary['alerts'] as $alert)
                <div class="flex items-center gap-1 border-b border-slate-100 px-3 py-2">
                    <a href="{{ $alert['url'] }}" class="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-lg px-1 py-1 text-sm hover:bg-slate-50">
                        <span>{{ $alert['title'] }}</span><b class="rounded-full bg-red-50 px-2 py-1 text-xs text-red-600">{{ $alert['count'] }}</b>
                    </a>
                    <x-clear-notifications :keys="$alert['keys']" :description="'Clear '.$alert['title']" />
                </div>
            @endforeach
            @unless($summary['count'])
                <p class="px-4 py-6 text-center text-sm text-slate-500">No notifications right now.</p>
            @endunless
        </div>
    </div>
</div>
