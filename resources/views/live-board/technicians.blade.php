<x-layouts::app>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-black text-slate-800">Technician Bike Load</h1>
        <div class="flex gap-2">
            <a href="{{ route('live-board.index') }}" class="btn-secondary">Switch to Board View</a>
            <a href="{{ route('live-board.fullscreen') }}" target="_blank" class="btn-primary">Fullscreen TV View</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($technicians as $tech)
        <div class="panel border-t-4 border-t-indigo-500">
            <div class="flex items-center justify-between border-b pb-3 mb-3">
                <div class="flex items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-full bg-indigo-100 text-indigo-700 font-bold text-lg">
                        {{ mb_strtoupper(mb_substr($tech->name, 0, 1)) }}
                    </div>
                    <div>
                        <h2 class="font-bold text-slate-800">{{ $tech->name }}</h2>
                        <p class="text-xs text-slate-500 font-semibold">{{ $tech->jobCards->count() }} active bike(s)</p>
                    </div>
                </div>
            </div>

            <div class="space-y-3 max-h-[400px] overflow-y-auto pr-1">
                @forelse($tech->jobCards as $job)
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 relative hover:border-indigo-200 transition-colors">
                    <div class="flex justify-between items-start mb-1">
                        <span class="font-bold text-sm text-slate-700">{{ $job->bike?->number ?? 'N/A' }}</span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-white border border-slate-200 text-slate-600">
                            {{ $job->number }}
                        </span>
                    </div>
                    <div class="text-xs text-slate-600 mb-2">
                        {{ $job->bike?->model ?? 'Unknown Model' }}
                        @if($job->customer)
                            &bull; {{ $job->customer?->name }}
                        @endif
                    </div>
                    
                    @php
                        $statusColors = [
                            'Received' => 'bg-slate-100 text-slate-700',
                            'Diagnosis' => 'bg-amber-100 text-amber-700',
                            'Waiting for Parts' => 'bg-purple-100 text-purple-700',
                            'In Progress' => 'bg-blue-100 text-blue-700',
                            'Washing / Final Check' => 'bg-cyan-100 text-cyan-700',
                            'Ready for Delivery' => 'bg-emerald-100 text-emerald-700',
                            'On Hold' => 'bg-red-100 text-red-700',
                            'Pending Payment' => 'bg-orange-100 text-orange-700',
                        ];
                        $color = $statusColors[$job->status] ?? 'bg-slate-100 text-slate-700';
                    @endphp
                    
                    <div class="flex justify-between items-center mt-2 pt-2 border-t border-slate-200/60">
                        <span class="inline-flex items-center px-2 py-1 rounded text-[10px] font-bold {{ $color }}">
                            {{ $job->status }}
                        </span>
                        <a href="{{ route('jobs.show', $job) }}" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">View Job &rarr;</a>
                    </div>
                </div>
                @empty
                <div class="py-6 text-center text-slate-400 text-sm">
                    No active bikes assigned.
                </div>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>
</x-layouts::app>
