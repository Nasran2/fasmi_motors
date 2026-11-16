@props(['id', 'title'])

<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" {{ $attributes->class('workshop-search-dialog') }}>
    <div class="mb-4 flex items-center justify-between gap-2">
        <h2 id="{{ $id }}-title" class="text-lg font-bold text-slate-900">{{ $title }}</h2>
        <button type="button" data-close-dialog class="grid size-10 shrink-0 place-items-center rounded-xl text-slate-600 hover:bg-slate-100" aria-label="Close search">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
    </div>
    {{ $slot }}
</dialog>
