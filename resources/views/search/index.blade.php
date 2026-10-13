<x-layouts::app :title="'Search: '.$term">
<div class="space-y-5">
    <div><h1 class="page-title">Search results</h1><p class="page-subtitle">Matching bikes, customers and job cards for “{{ $term }}”.</p></div>
    <form action="{{ route('search') }}" class="panel flex gap-2"><input name="q" value="{{ $term }}" class="field" minlength="2" required><button class="btn-primary">Search</button></form>
    <div class="grid gap-5 lg:grid-cols-3">
        <section class="panel"><h2 class="mb-4 font-bold">Bikes <span class="text-sm text-slate-400">({{ $bikes->count() }})</span></h2><div class="divide-y divide-slate-100">@forelse($bikes as $bike)<a href="{{ $bike->intakes()->latest()->first() ? route('intakes.show',$bike->intakes()->latest()->first()) : route('intakes.create') }}" class="block py-3 hover:text-red-600"><strong class="block">{{ $bike->number }}</strong><span class="text-sm text-slate-500">{{ $bike->model }} · {{ $bike->customer->name ?: $bike->customer->phone }}</span></a>@empty<p class="text-sm text-slate-400">No matching bikes.</p>@endforelse</div></section>
        <section class="panel"><h2 class="mb-4 font-bold">Customers <span class="text-sm text-slate-400">({{ $customers->count() }})</span></h2><div class="divide-y divide-slate-100">@forelse($customers as $customer)<div class="py-3"><strong class="block">{{ $customer->name ?: 'Walk-in customer' }}</strong><span class="text-sm text-slate-500">{{ $customer->phone }} · {{ $customer->bikes()->count() }} bike(s)</span></div>@empty<p class="text-sm text-slate-400">No matching customers.</p>@endforelse</div></section>
        <section class="panel"><h2 class="mb-4 font-bold">Job Cards <span class="text-sm text-slate-400">({{ $jobs->count() }})</span></h2><div class="divide-y divide-slate-100">@forelse($jobs as $job)<a href="{{ route('jobs.show',$job) }}" class="block py-3 hover:text-red-600"><strong class="block">{{ $job->number }}</strong><span class="text-sm text-slate-500">{{ $job->bike?->number }} · {{ $job->status }}</span></a>@empty<p class="text-sm text-slate-400">No matching job cards.</p>@endforelse</div></section>
    </div>
</div>
</x-layouts::app>
