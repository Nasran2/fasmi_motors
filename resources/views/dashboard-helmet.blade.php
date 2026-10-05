<x-layouts::app :title="__('Helmet Shop Dashboard')">
@php
    $tones = [
        'red' => ['bg' => 'bg-red-50', 'text' => 'text-red-600'], 
        'orange' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600'],
        'green' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600'], 
        'blue' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600'],
        'violet' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-600'], 
        'teal' => ['bg' => 'bg-teal-50', 'text' => 'text-teal-600'],
        'navy' => ['bg' => 'bg-slate-100', 'text' => 'text-[#0b2447]'], 
        'rose' => ['bg' => 'bg-rose-50', 'text' => 'text-rose-600'],
    ];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-950">🪖 Helmet Shop Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">Manage helmet inventory, point-of-sale billing, and sales analytics.</p>
        </div>
        
        <form method="GET" action="{{ route('dashboard') }}" x-data="{ filter: '{{ request('filter', 'today') }}' }" class="flex items-center gap-2">
            <select name="filter" x-model="filter" @change="if(filter !== 'custom') $el.form.submit()" class="rounded-lg border-slate-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_year">This Year</option>
                <option value="last_year">Last Year</option>
                <option value="custom">Custom Range</option>
            </select>
            
            <template x-if="filter === 'custom'">
                <div class="flex items-center gap-2">
                    <input type="date" name="start_date" value="{{ request('start_date') }}" class="rounded-lg border-slate-200 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    <span class="text-slate-500">to</span>
                    <input type="date" name="end_date" value="{{ request('end_date') }}" class="rounded-lg border-slate-200 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                </div>
            </template>
        </form>
    </div>
    <x-flash/>
    <x-cheque-alerts :cheques="$chequeAlerts" />

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($cards as $card)
            @php($tone = $tones[$card['tone']])
            <article class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_8px_26px_rgba(15,35,65,.055)] transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-[0_16px_36px_rgba(15,35,65,.09)]">
                <span class="absolute -right-5 -top-6 size-20 rounded-full {{ $tone['bg'] }} opacity-0 blur-2xl transition group-hover:opacity-100"></span>
                <div class="flex items-start gap-3">
                    <span class="relative grid size-11 shrink-0 place-items-center rounded-2xl {{ $tone['bg'] }} {{ $tone['text'] }} ring-1 ring-current/5 transition group-hover:scale-105">
                        <x-dashboard-icon :name="$card['icon']" class="size-6" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold text-slate-600">{{ $card['label'] }}</p>
                        <p class="mt-1 whitespace-nowrap text-xl font-extrabold text-slate-950">
                            {{ $card['format']==='money' ? 'Rs. '.number_format($card['value'], 2) : number_format($card['value']) }}
                        </p>
                    </div>
                </div>
                <p class="mt-3 truncate text-[11px] text-slate-500">
                    @if($card['trend'] !== null)
                        <span class="font-bold {{ $card['trend'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $card['trend'] >= 0 ? '↗' : '↘' }} {{ abs($card['trend']) }}%
                        </span> 
                    @endif
                    {{ $card['note'] }}
                </p>
            </article>
        @endforeach
    </section>

    <section class="grid gap-4 xl:grid-cols-12">
        <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_10px_35px_rgba(15,35,65,.055)] xl:col-span-8">
            <div class="flex items-center justify-between">
                <h2 class="font-bold text-slate-900">Helmet Sales Overview <span class="font-normal text-slate-500">({{ $periodName }})</span></h2>
                <span class="rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-500">POS Sales</span>
            </div>
            <div class="mt-4 overflow-hidden">
                <svg viewBox="0 0 600 180" class="h-44 w-full" preserveAspectRatio="none" aria-label="Weekly sales chart">
                    @foreach([30,72,114,155] as $y)
                        <line x1="0" y1="{{ $y }}" x2="600" y2="{{ $y }}" stroke="#e8edf4" stroke-width="1"/>
                    @endforeach
                    <defs>
                        <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#4f46e5" stop-opacity=".28"/>
                            <stop offset="1" stop-color="#4f46e5" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <polygon points="0,160 {{ $salesPoints }} 600,160" fill="url(#salesFill)"/>
                    <polyline points="{{ $salesPoints }}" fill="none" stroke="#4f46e5" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>
                    @foreach($salesSeries as $index=>$sale)
                        @php($cy = round(155 - (($sale['value'] / $salesMaximum) * 125), 2))
                        <circle cx="{{ $salesSeries->count() > 1 ? $index * 600 / ($salesSeries->count() - 1) : 300 }}" cy="{{ $cy }}" r="5" fill="#4f46e5" stroke="white" stroke-width="3">
                            <title>{{ $sale['date'] }}: Rs. {{ number_format($sale['value'],2) }}</title>
                        </circle>
                    @endforeach
                </svg>
                <div class="flex justify-between gap-2 overflow-x-auto text-center text-[10px] font-medium text-slate-500">
                    @foreach($salesSeries as $sale)
                        <span title="{{ $sale['date'] }}">{{ $sale['label'] }}</span>
                    @endforeach
                </div>
            </div>
            <div class="mt-4 grid grid-cols-3 divide-x divide-slate-200 border-t border-slate-100 pt-3 text-center">
                <div>
                    <p class="text-[10px] text-slate-500">Total Sales</p>
                    <p class="mt-1 text-sm font-bold text-indigo-600">Rs. {{ number_format($weekSales,2) }}</p>
                </div>
                <div>
                    <p class="text-[10px] text-slate-500">Average Daily</p>
                    <p class="mt-1 text-sm font-bold">Rs. {{ number_format($averageDailySales,2) }}</p>
                </div>
                <div>
                    <p class="text-[10px] text-slate-500">{{ $filterLabel ?? 'vs previous' }}</p>
                    <p class="mt-1 text-sm font-bold {{ $weekTrend>=0?'text-emerald-600':'text-red-600' }}">
                        {{ $weekTrend>=0?'↗':'↘' }} {{ abs($weekTrend) }}%
                    </p>
                </div>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_10px_35px_rgba(15,35,65,.05)] xl:col-span-4">
            <div class="flex justify-between">
                <h2 class="font-bold text-slate-900">Low Stock Helmets</h2>
                <a href="{{ route('products.index') }}" class="text-[10px] font-bold text-indigo-600">View all</a>
            </div>
            <div class="mt-3 divide-y divide-slate-100">
                @forelse($lowStock as $product)
                    <div class="flex items-center gap-3 py-2.5">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600">
                            <x-dashboard-icon name="parts" class="size-5"/>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold">{{ $product->name }}</p>
                            <p class="text-[10px] {{ ($product->stocks_sum_quantity??0)<=0?'text-red-600':'text-orange-600' }}">
                                Stock: {{ number_format((float)($product->stocks_sum_quantity??0),2) }} {{ $product->unit }}
                            </p>
                        </div>
                    </div>
                @empty
                    <div class="py-14 text-center">
                        <span class="mx-auto mb-2 grid size-10 place-items-center rounded-2xl bg-emerald-50 text-emerald-600">
                            <x-dashboard-icon name="parts" class="size-5"/>
                        </span>
                        <p class="text-xs font-bold text-emerald-700">Stock is healthy</p>
                        <p class="mt-1 text-[10px] text-slate-400">All helmets are fully stocked.</p>
                    </div>
                @endforelse
            </div>
            @if($lowStock->count())
                <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-[10px] font-semibold text-red-600">
                    ⚠ {{ $lowStock->count() }} helmet(s) require stock adjustment.
                </p>
            @endif
        </article>
    </section>

    <section class="grid gap-4 xl:grid-cols-12">
        <article class="min-w-0 rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-12">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h2 class="font-bold">Recent Helmet Sales</h2>
                <a href="{{ route('sales.invoices') }}" class="text-xs font-semibold text-indigo-600">View all invoices</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Invoice No</th>
                            <th class="px-3 py-3">Customer</th>
                            <th class="px-3 py-3">Subtotal</th>
                            <th class="px-3 py-3">Discount</th>
                            <th class="px-3 py-3">Total Amount</th>
                            <th class="px-3 py-3">Paid Amount</th>
                            <th class="px-3 py-3">Due</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recentSales as $sale)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3">
                                    <a href="{{ route('sales.invoices.show',$sale) }}" class="font-bold text-indigo-600">
                                        {{ $sale->number }}
                                    </a>
                                </td>
                                <td class="px-3 py-3">
                                    @if($sale->customer)
                                        <span class="block font-semibold">{{ $sale->customer->name }}</span>
                                        <span class="text-[10px] text-slate-400">{{ $sale->customer->phone }}</span>
                                    @else
                                        <span class="text-slate-400">Walk-in Customer</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">Rs. {{ number_format($sale->subtotal,2) }}</td>
                                <td class="px-3 py-3">Rs. {{ number_format($sale->discount,2) }}</td>
                                <td class="px-3 py-3 font-bold">Rs. {{ number_format($sale->total,2) }}</td>
                                <td class="px-3 py-3 text-emerald-600 font-semibold">Rs. {{ number_format($sale->paid_amount,2) }}</td>
                                <td class="px-3 py-3 text-red-600 font-semibold">Rs. {{ number_format($sale->due_amount,2) }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-md px-2 py-0.5 text-[9px] font-semibold 
                                        @if($sale->status === 'Paid') bg-emerald-50 text-emerald-700
                                        @elseif($sale->status === 'Unpaid') bg-red-50 text-red-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ $sale->status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-slate-500">{{ $sale->created_at->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center text-slate-400">No helmet sales have been recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>
</x-layouts::app>
