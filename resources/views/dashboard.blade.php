<x-layouts::app :title="__('Dashboard')">
@php
    $tones = [
        'red' => ['bg' => 'bg-red-50', 'text' => 'text-red-600'], 'orange' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600'],
        'green' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600'], 'blue' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600'],
        'violet' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-600'], 'teal' => ['bg' => 'bg-teal-50', 'text' => 'text-teal-600'],
        'navy' => ['bg' => 'bg-slate-100', 'text' => 'text-[#0b2447]'], 'rose' => ['bg' => 'bg-rose-50', 'text' => 'text-rose-600'],
    ];
    $statusTones = ['Received'=>'bg-slate-100 text-slate-700','Assigned'=>'bg-indigo-50 text-indigo-700','In Progress'=>'bg-orange-50 text-orange-700','Waiting for Parts'=>'bg-blue-50 text-blue-700','Completed'=>'bg-emerald-50 text-emerald-700','Billed'=>'bg-violet-50 text-violet-700','Delivered'=>'bg-green-50 text-green-700','Cancelled'=>'bg-red-50 text-red-700'];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold tracking-tight text-slate-950">Dashboard</h1><p class="mt-1 text-sm text-slate-500">Welcome back, manage today’s workshop operations with live business data.</p></div>
        
        <form method="GET" action="{{ route('dashboard') }}" x-data="{ filter: '{{ request('filter', 'today') }}' }" class="flex items-center gap-2">
            <select name="filter" x-model="filter" @change="if(filter !== 'custom') $el.form.submit()" class="rounded-lg border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This Week</option>
                <option value="last_week">Last Week</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_year">This Year</option>
                <option value="last_year">Last Year</option>
                <option value="custom">Custom Range</option>
            </select>
            
            <template x-if="filter === 'custom'">
                <div class="flex items-center gap-2">
                    <input type="date" name="start_date" value="{{ request('start_date') }}" class="rounded-lg border-slate-200 text-sm focus:border-red-500 focus:ring-red-500" required>
                    <span class="text-slate-500">to</span>
                    <input type="date" name="end_date" value="{{ request('end_date') }}" class="rounded-lg border-slate-200 text-sm focus:border-red-500 focus:ring-red-500" required>
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                </div>
            </template>
        </form>
    </div>
    <x-flash/>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($cards as $card)
            @php($tone = $tones[$card['tone']])
            <article class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_26px_rgba(15,35,65,.055)] transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-[0_16px_36px_rgba(15,35,65,.09)]">
                <span class="absolute -right-5 -top-6 size-20 rounded-full {{ $tone['bg'] }} opacity-0 blur-2xl transition group-hover:opacity-100"></span>
                <div class="flex items-start gap-3">
                    <span class="relative grid size-11 shrink-0 place-items-center rounded-2xl {{ $tone['bg'] }} {{ $tone['text'] }} ring-1 ring-current/5 transition group-hover:scale-105"><x-dashboard-icon :name="$card['icon']" class="size-6" /></span>
                    <div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-600">{{ $card['label'] }}</p><p class="mt-1 whitespace-nowrap text-xl font-extrabold text-slate-950">{{ $card['format']==='money' ? 'Rs. '.number_format($card['value'], 2) : number_format($card['value']) }}</p></div>
                </div>
                <p class="mt-3 truncate text-[11px] text-slate-500">
                    @if($card['trend'] !== null)<span class="font-bold {{ $card['trend'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $card['trend'] >= 0 ? '↗' : '↘' }} {{ abs($card['trend']) }}%</span> @endif{{ $card['note'] }}
                </p>
            </article>
        @endforeach
    </section>

    <section class="grid gap-4 xl:grid-cols-12">
        <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_10px_35px_rgba(15,35,65,.055)] xl:col-span-5">
            <div class="flex items-center justify-between"><h2 class="font-bold text-slate-900">Sales Overview <span class="font-normal text-slate-500">({{ $periodName }})</span></h2><span class="rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-500">Live invoices</span></div>
            <div class="mt-4 overflow-hidden">
                <svg viewBox="0 0 600 180" class="h-44 w-full" preserveAspectRatio="none" aria-label="Weekly sales chart">
                    @foreach([30,72,114,155] as $y)<line x1="0" y1="{{ $y }}" x2="600" y2="{{ $y }}" stroke="#e8edf4" stroke-width="1"/>@endforeach
                    <defs><linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ef172d" stop-opacity=".28"/><stop offset="1" stop-color="#ef172d" stop-opacity="0"/></linearGradient></defs>
                    <polygon points="0,160 {{ $salesPoints }} 600,160" fill="url(#salesFill)"/>
                    <polyline points="{{ $salesPoints }}" fill="none" stroke="#ef172d" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>
                    @foreach($salesSeries as $index=>$sale)
                        @php($cy = round(155 - (($sale['value'] / $salesMaximum) * 125), 2))
                        <circle cx="{{ $salesSeries->count() > 1 ? $index * 600 / ($salesSeries->count() - 1) : 300 }}" cy="{{ $cy }}" r="5" fill="#ef172d" stroke="white" stroke-width="3"><title>{{ $sale['date'] }}: Rs. {{ number_format($sale['value'],2) }}</title></circle>
                    @endforeach
                </svg>
                <div class="flex justify-between gap-2 overflow-x-auto text-center text-[10px] font-medium text-slate-500">@foreach($salesSeries as $sale)<span title="{{ $sale['date'] }}">{{ $sale['label'] }}</span>@endforeach</div>
            </div>
            <div class="mt-4 grid grid-cols-3 divide-x divide-slate-200 border-t border-slate-100 pt-3 text-center"><div><p class="text-[10px] text-slate-500">Total Sales</p><p class="mt-1 text-sm font-bold text-red-600">Rs. {{ number_format($weekSales,2) }}</p></div><div><p class="text-[10px] text-slate-500">Average Daily</p><p class="mt-1 text-sm font-bold">Rs. {{ number_format($averageDailySales,2) }}</p></div><div><p class="text-[10px] text-slate-500">{{ $filterLabel ?? 'vs previous' }}</p><p class="mt-1 text-sm font-bold {{ $weekTrend>=0?'text-emerald-600':'text-red-600' }}">{{ $weekTrend>=0?'↗':'↘' }} {{ abs($weekTrend) }}%</p></div></div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_10px_35px_rgba(15,35,65,.055)] xl:col-span-3">
            <h2 class="font-bold text-slate-900">Job Status Overview</h2>
            <div class="mt-6 flex items-center justify-center gap-5">
                <div class="relative size-40 shrink-0 rounded-full" style="background: conic-gradient({{ $jobGradient }})"><div class="absolute inset-7 grid place-items-center rounded-full bg-white text-center"><div><strong class="block text-2xl">{{ $jobTotal }}</strong><span class="text-[10px] text-slate-500">Total Jobs</span></div></div></div>
                <div class="space-y-3">@foreach($jobSegments as $segment)<div class="text-xs"><span class="mr-2 inline-block size-2.5 rounded-full" style="background:{{ $segment['color'] }}"></span><span class="text-slate-600">{{ $segment['label'] }}</span><strong class="ml-1">{{ $segment['count'] }}</strong></div>@endforeach</div>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_10px_35px_rgba(15,35,65,.055)] xl:col-span-4">
            <div class="flex items-center justify-between"><h2 class="font-bold text-slate-900">Technician Performance <span class="font-normal text-slate-500">({{ $periodName }})</span></h2><a href="{{ route('technicians.index') }}" class="text-xs font-semibold text-red-600">View all</a></div>
            <div class="mt-5 flex h-52 items-end gap-3 border-b border-slate-200 px-2">
                @forelse($technicianPerformance as $technician)
                    @php($height = max(4, round(((float)$technician->performance_total/$performanceMaximum)*165)))
                    <div class="flex min-w-0 flex-1 flex-col items-center justify-end"><span class="mb-1 text-[9px] font-bold text-slate-600">Rs. {{ number_format((float)$technician->performance_total/1000,1) }}K</span><div class="w-full max-w-14 rounded-t-lg bg-gradient-to-t from-[#09244b] to-[#184d88]" style="height:{{ $height }}px"></div><span class="mt-2 w-full truncate text-center text-[10px] font-semibold">{{ $technician->name }}</span></div>
                    @empty<div class="grid h-full w-full place-items-center px-5 text-center"><div><span class="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200"><x-dashboard-icon name="technician" class="size-6"/></span><p class="text-sm font-semibold text-slate-500">No performance data yet</p><p class="mt-1 text-[11px] text-slate-400">Billed technician jobs will appear here.</p></div></div>@endforelse
            </div>
        </article>
    </section>

    <section class="grid min-w-0 gap-4 xl:grid-cols-12">
        <article class="min-w-0 rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-6">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3"><h2 class="font-bold">Recent Bike Intakes</h2><a href="{{ route('intakes.index') }}" class="text-xs font-semibold text-red-600">View all</a></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-left text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3">Bike No</th><th class="px-3 py-3">Customer</th><th class="px-3 py-3">Model</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Technician</th><th class="px-3 py-3">Time</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($recentIntakes as $intake)<tr class="hover:bg-slate-50/70"><td class="px-4 py-3"><a href="{{ route('intakes.show',$intake) }}" class="font-bold text-red-600">{{ $intake->bike->number }}</a></td><td class="px-3 py-3"><span class="block font-semibold">{{ $intake->bike->customer->name ?: 'Walk-in customer' }}</span><span class="text-[10px] text-slate-400">{{ $intake->bike->customer->phone }}</span></td><td class="px-3 py-3">{{ $intake->bike->model }}</td><td class="px-3 py-3"><span class="rounded-md px-2 py-1 text-[10px] font-semibold {{ $statusTones[$intake->jobCard?->status]??'bg-slate-100 text-slate-600' }}">{{ $intake->jobCard?->status ?? 'Intake' }}</span></td><td class="px-3 py-3">{{ $intake->jobCard?->technician?->name ?? 'Unassigned' }}</td><td class="whitespace-nowrap px-3 py-3 text-slate-500">{{ $intake->created_at->format('h:i A') }}</td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">No bike intakes have been recorded yet.</td></tr>@endforelse</tbody></table></div>
        </article>

        <div class="grid min-w-0 gap-4 md:grid-cols-3 xl:col-span-6">
            <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_10px_35px_rgba(15,35,65,.05)]"><div class="flex justify-between"><h2 class="font-bold">Low Stock Alerts</h2><a href="{{ route('products.index') }}" class="text-[10px] font-bold text-red-600">View all</a></div><div class="mt-3 divide-y divide-slate-100">@forelse($lowStock as $product)<div class="flex items-center gap-3 py-2"><span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><x-dashboard-icon name="parts" class="size-5"/></span><div class="min-w-0"><p class="truncate text-xs font-semibold">{{ $product->name }}</p><p class="text-[10px] {{ ($product->stocks_sum_quantity??0)<=0?'text-red-600':'text-orange-600' }}">Stock: {{ number_format((float)($product->stocks_sum_quantity??0),2) }} {{ $product->unit }}</p></div></div>@empty<div class="py-7 text-center"><span class="mx-auto mb-2 grid size-10 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><x-dashboard-icon name="parts" class="size-5"/></span><p class="text-xs font-bold text-emerald-700">Stock is healthy</p><p class="mt-1 text-[10px] text-slate-400">No items need attention.</p></div>@endforelse</div>@if($lowStock->count())<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-[10px] font-semibold text-red-600">⚠ {{ $lowStock->count() }} shown item(s) require attention</p>@endif</article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex justify-between"><h2 class="font-bold">Service Reminders</h2><a href="{{ route('reports.index') }}" class="text-[10px] text-red-600">View report</a></div><div class="mt-3 divide-y divide-slate-100">@forelse($reminders as $reminder)<div class="flex items-center gap-2 py-2"><span class="text-red-600"><x-dashboard-icon name="bike" class="size-6"/></span><div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold">{{ $reminder->bike->number }}</p><p class="truncate text-[10px] text-slate-500">{{ $reminder->bike->model }} · {{ $reminder->bike->customer->name ?: $reminder->bike->customer->phone }}</p></div><time class="rounded-lg bg-slate-50 px-2 py-1 text-center text-[9px] font-semibold {{ $reminder->due_date?->isPast()?'text-red-600':'text-slate-600' }}">{{ $reminder->due_date?->format('d M') }}</time></div>@empty<p class="py-8 text-center text-xs text-slate-400">No service reminders scheduled.</p>@endforelse</div></article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex justify-between"><h2 class="font-bold">Today Attendance</h2><a href="{{ route('technicians.index') }}" class="text-[10px] text-red-600">View all</a></div><div class="mt-3 divide-y divide-slate-100">@forelse($attendance as $technician)@php($record=$technician->attendance->first())<div class="flex items-center gap-2 py-2"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-[#0b2447] text-[10px] font-bold text-white">{{ mb_strtoupper(mb_substr($technician->name,0,1)) }}</span><span class="min-w-0 flex-1 truncate text-xs font-semibold">{{ $technician->name }}</span><span class="rounded px-2 py-1 text-[9px] font-semibold {{ in_array($record?->status,['Present','Late'])?'bg-emerald-50 text-emerald-600':($record?'bg-red-50 text-red-600':'bg-slate-100 text-slate-500') }}">{{ $record?->status ?? 'Not marked' }}</span></div>@empty<p class="py-8 text-center text-xs text-slate-400">No active technicians.</p>@endforelse</div></article>
        </div>
    </section>

    <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-12">
        <div class="lg:col-span-8"><div class="flex justify-between"><h2 class="font-bold">Recent Transactions / Account Summary</h2><a href="{{ route('finance.index') }}" class="text-xs font-semibold text-red-600">View all</a></div><div class="mt-3 divide-y divide-slate-100">@forelse($recentTransactions as $transaction)<div class="grid grid-cols-[auto_1fr_auto] items-center gap-3 py-2 text-xs"><span class="grid size-7 place-items-center rounded-lg {{ $transaction->debit>0?'bg-red-50 text-red-600':'bg-emerald-50 text-emerald-600' }}">{{ $transaction->debit>0?'−':'+' }}</span><div class="min-w-0"><p class="truncate font-semibold">{{ $transaction->description }}</p><p class="text-[10px] text-slate-400">{{ $transaction->account->name }} · {{ $transaction->transaction_date->diffForHumans() }}</p></div><strong class="{{ $transaction->debit>0?'text-red-600':'text-emerald-600' }}">{{ $transaction->debit>0?'−':'+' }} Rs. {{ number_format($transaction->amount,2) }}</strong></div>@empty<p class="py-8 text-center text-xs text-slate-400">No account transactions yet.</p>@endforelse</div></div>
        <div class="grid gap-3 sm:grid-cols-3 lg:col-span-4"><div class="rounded-xl bg-blue-50 p-3"><p class="text-[10px] text-blue-600">Today Collection</p><p class="mt-1 text-lg font-extrabold text-blue-900">Rs. {{ number_format($collectionToday,2) }}</p></div><div class="rounded-xl bg-red-50 p-3"><p class="text-[10px] text-red-600">Today Expense</p><p class="mt-1 text-lg font-extrabold text-red-900">Rs. {{ number_format($expensesToday,2) }}</p></div><div class="rounded-xl bg-emerald-50 p-3"><p class="text-[10px] text-emerald-600">Net Today</p><p class="mt-1 text-lg font-extrabold {{ $netToday>=0?'text-emerald-900':'text-red-700' }}">Rs. {{ number_format($netToday,2) }}</p></div></div>
    </section>
</div>
</x-layouts::app>
