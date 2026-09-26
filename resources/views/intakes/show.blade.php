<x-layouts::app title="{{ $intake->intake_number }}">
    <div class="space-y-6">
        <!-- Header Section -->
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div class="flex items-start gap-4">
                <div class="grid size-14 shrink-0 place-items-center rounded-2xl bg-slate-900 text-white shadow-lg">
                    <x-dashboard-icon name="bike" class="size-7" />
                </div>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="page-title">{{ $intake->bike->number }}</h1>
                        <span class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 ring-1 ring-inset ring-blue-600/20">
                            {{ $intake->intake_number }}
                        </span>
                    </div>
                    <p class="page-subtitle mt-1 flex items-center gap-2">
                        <span class="font-medium text-slate-700">{{ $intake->bike->model }}</span>
                        <span class="size-1 rounded-full bg-slate-300"></span>
                        Received {{ $intake->created_at->format('d M Y, h:i A') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if(!$intake->jobCard)
                    <a href="{{ route('jobs.create',['intake'=>$intake->id]) }}" class="btn-primary shadow-red-500/20">
                        <svg class="mr-2 size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        Create job card
                    </a>
                @else
                    <a href="{{ route('jobs.show',$intake->jobCard) }}" class="btn-primary shadow-emerald-500/20 bg-emerald-600 hover:bg-emerald-700">
                        <svg class="mr-2 size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                        Open {{ $intake->jobCard->number }}
                    </a>
                @endif
                <div class="flex items-center gap-2 rounded-xl bg-slate-100 p-1">
                    <a href="{{ route('intakes.edit', $intake) }}" class="flex h-9 items-center justify-center rounded-lg bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:text-blue-600">Edit</a>
                    <form method="POST" action="{{ route('intakes.destroy', $intake) }}" data-confirm="Are you sure you want to delete this intake?" class="inline">
                        @csrf<x-submission-token/> 
                        @method('DELETE') 
                        <button type="submit" class="flex h-9 items-center justify-center rounded-lg px-4 text-sm font-bold text-slate-500 transition hover:bg-red-50 hover:text-red-600">Delete</button>
                    </form>
                </div>
            </div>
        </div>

        <x-flash/>

        <div class="grid gap-6 lg:grid-cols-12">
            <!-- Left Column: Primary Details -->
            <div class="space-y-6 lg:col-span-8">
                <div class="panel border-t-4 border-t-slate-800">
                    <h2 class="mb-5 flex items-center gap-2 text-lg font-bold text-slate-900">
                        <svg class="size-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        Intake Summary
                    </h2>
                    
                    <div class="rounded-xl bg-slate-50 p-4 mb-6">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Customer Complaint</dt>
                        <dd class="text-slate-800 font-medium leading-relaxed">{{ $intake->complaint }}</dd>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Customer</dt>
                            <dd class="font-semibold text-slate-900">{{ $intake->bike->customer->name ?: 'Walk-in' }}</dd>
                            <dd class="text-xs text-slate-500 mt-0.5">{{ $intake->bike->customer->phone }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Current Mileage</dt>
                            <dd class="font-semibold text-slate-900">{{ number_format($intake->current_mileage ?? 0) }} <span class="text-xs font-normal text-slate-500">KM</span></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Intake Type</dt>
                            <dd class="font-semibold text-slate-900">
                                @if($intake->is_service)
                                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-blue-500"></span> Service</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-500"></span> Repair</span>
                                @endif
                            </dd>
                            @if($intake->bike->is_technician_bike)
                                <dd class="text-[10px] font-bold text-red-600 mt-1 uppercase tracking-wider">Staff Bike</dd>
                            @endif
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Est. Amount</dt>
                            <dd class="font-semibold text-slate-900">Rs. {{ number_format($intake->estimated_amount, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Advance Paid</dt>
                            <dd class="font-semibold text-slate-900">Rs. {{ number_format($intake->advance_payment ?? 0, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Delivery Estimate</dt>
                            <dd class="font-semibold {{ $intake->estimated_delivery_date ? 'text-slate-900' : 'text-slate-400 italic' }}">
                                {{ $intake->estimated_delivery_date?->format('d M Y') ?: 'Not scheduled' }}
                            </dd>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <h2 class="mb-5 flex items-center gap-2 text-lg font-bold text-slate-900">
                        <svg class="size-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Service History
                    </h2>
                    
                    @if($intake->bike->intakes->count() > 0)
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Job Details</th>
                                        <th>Status</th>
                                        <th class="text-right">Due Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($intake->bike->intakes->sortByDesc('created_at') as $past)
                                        <tr class="{{ $past->id === $intake->id ? 'bg-blue-50/50' : '' }}">
                                            <td class="whitespace-nowrap">
                                                <div class="font-semibold text-slate-700">{{ $past->created_at->format('d M Y') }}</div>
                                                <div class="text-[10px] text-slate-500">{{ $past->created_at->format('h:i A') }}</div>
                                                @if($past->id === $intake->id)
                                                    <span class="mt-1 inline-block rounded border border-blue-200 bg-blue-100 px-1.5 py-0.5 text-[9px] font-bold text-blue-700 uppercase tracking-wider">Current</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="max-w-xs truncate text-sm font-medium text-slate-800" title="{{ $past->complaint }}">{{ $past->complaint }}</div>
                                                <div class="text-xs text-slate-500 mt-0.5">Job: {{ $past->jobCard?->number ?: 'Not created' }}</div>
                                            </td>
                                            <td>
                                                @if($past->jobCard)
                                                    @php
                                                        $status = $past->jobCard->status;
                                                        $color = match($status) {
                                                            'Pending' => 'bg-amber-100 text-amber-700 border-amber-200',
                                                            'In Progress' => 'bg-blue-100 text-blue-700 border-blue-200',
                                                            'Completed' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                                            'Billed' => 'bg-purple-100 text-purple-700 border-purple-200',
                                                            'Delivered' => 'bg-slate-100 text-slate-700 border-slate-200',
                                                            'Cancelled' => 'bg-red-100 text-red-700 border-red-200',
                                                            default => 'bg-slate-100 text-slate-700 border-slate-200'
                                                        };
                                                    @endphp
                                                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold {{ $color }}">{{ $status }}</span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-[10px] font-bold text-slate-500">Intake Only</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                @php $due = $past->jobCard?->invoice?->due_amount ?? 0; @endphp
                                                @if($due > 0)
                                                    <span class="font-bold text-red-600">Rs. {{ number_format($due, 2) }}</span>
                                                @else
                                                    <span class="text-slate-400">Rs. 0.00</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 py-8 text-center">
                            <p class="text-sm text-slate-500">No previous service history found for this bike.</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Right Column: Account & Meta -->
            <div class="space-y-6 lg:col-span-4">
                @php
                    $totalDue = $intake->bike->intakes->flatMap(fn($x)=>$x->jobCard?->invoice ? [$x->jobCard->invoice] : [])->sum('due_amount');
                @endphp
                <div class="panel border-t-4 {{ $totalDue > 0 ? 'border-t-red-500 bg-red-50/30' : 'border-t-emerald-500' }}">
                    <h2 class="mb-1 text-sm font-bold {{ $totalDue > 0 ? 'text-red-900' : 'text-slate-700' }}">Previous Due Balance</h2>
                    <p class="text-xs {{ $totalDue > 0 ? 'text-red-600/80' : 'text-slate-500' }}">Total outstanding from past invoices</p>
                    
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-lg font-bold {{ $totalDue > 0 ? 'text-red-600' : 'text-emerald-600' }}">Rs.</span>
                        <span class="text-3xl font-extrabold tracking-tight {{ $totalDue > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ number_format($totalDue, 2) }}</span>
                    </div>
                    
                    @if($totalDue > 0)
                        <div class="mt-4 rounded-lg bg-red-100/50 p-3 text-xs text-red-800">
                            <strong>Notice:</strong> Please collect the outstanding balance from previous visits before proceeding.
                        </div>
                    @else
                        <div class="mt-4 flex items-center gap-2 text-xs font-medium text-emerald-700">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            Account is clear
                        </div>
                    @endif
                </div>
                
                <div class="panel">
                    <h3 class="mb-4 text-xs font-bold uppercase tracking-wider text-slate-500">Condition Notes & Extras</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <div class="text-xs font-medium text-slate-500">Fuel Level</div>
                            <div class="mt-1 font-semibold text-slate-800">{{ $intake->fuel_level ?: 'Not recorded' }}</div>
                        </div>
                        <div class="h-px bg-slate-100"></div>
                        <div>
                            <div class="text-xs font-medium text-slate-500">Accessories Received</div>
                            <div class="mt-1 font-semibold text-slate-800">{{ $intake->accessories ?: 'None' }}</div>
                        </div>
                        <div class="h-px bg-slate-100"></div>
                        <div>
                            <div class="text-xs font-medium text-slate-500">Condition Notes</div>
                            @if($intake->condition_notes)
                                <p class="mt-1 text-sm italic text-slate-700">"{{ $intake->condition_notes }}"</p>
                            @else
                                <div class="mt-1 text-sm text-slate-400">No condition notes.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::app>
