<x-layouts::app :title="$page['title']">
    <div class="space-y-5">
        <div>
            <p class="mb-1 text-[10px] font-bold uppercase tracking-[.18em] text-red-600">{{ $group['title'] }}</p>
            <h1 class="page-title">{{ $page['title'] }}</h1>
            <p class="page-subtitle">Dedicated {{ strtolower($page['title']) }} workspace for Fasmir Motors.</p>
        </div>

        <section class="panel overflow-hidden !p-0">
            <div class="flex flex-col gap-5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white p-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <span class="grid size-12 place-items-center rounded-2xl bg-[#0b2447] text-white shadow-lg shadow-slate-200"><x-sidebar-menu-icon :name="$group['icon']" class="size-6"/></span>
                    <div><h2 class="font-extrabold text-slate-900">{{ $page['title'] }}</h2><p class="mt-1 text-xs text-slate-500">{{ $group['title'] }} module</p></div>
                </div>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700">Page available</span>
            </div>
            <div class="grid min-h-64 place-items-center p-8 text-center">
                <div><span class="mx-auto grid size-16 place-items-center rounded-3xl bg-red-50 text-red-600 ring-1 ring-red-100"><x-sidebar-menu-icon :name="$group['icon']" class="size-7"/></span><h3 class="mt-5 text-lg font-extrabold text-slate-800">No records to display yet</h3><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">This separate workspace is ready in the system navigation. Records created for {{ strtolower($page['title']) }} will be managed here.</p></div>
            </div>
        </section>
    </div>
</x-layouts::app>
