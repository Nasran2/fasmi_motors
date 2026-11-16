<x-layouts::app title="Bike Intake">
    <div class="space-y-5">
        <div class="flex flex-wrap justify-between gap-3">
            <div>
                <h1 class="page-title">Bike Intake</h1>
                <p class="page-subtitle">New repair entries and customer bike history.</p>
            </div>
            <a href="{{ route('intakes.create') }}" class="btn-primary">+ New intake</a>
        </div>
        <x-flash/>
        <form class="panel flex gap-2">
            <input class="field max-w-sm" name="q" value="{{ request('q') }}" placeholder="Search intake #, bike number, customer or phone...">
            <button class="btn-secondary">Search</button>
            @if(request('q'))
                <a href="{{ route('intakes.index') }}" class="btn-secondary">Clear</a>
            @endif
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Intake</th>
                        <th>Bike number</th>
                        <th>Service or not</th>
                        <th>Complaint</th>
                        <th>Job card</th>
                        <th>Date</th>
                        <th>Customer & Phone</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($intakes as $i)
                        <tr>
                            <td>
                                <a class="font-semibold text-emerald-600 hover:underline" href="{{ route('intakes.show', $i) }}">
                                    {{ $i->intake_number }}
                                </a>
                            </td>
                            <td>
                                <a href="{{ route('customers.bikes.show', $i->bike) }}" class="font-semibold text-slate-900 hover:text-red-600 hover:underline">
                                    {{ $i->bike->number }}
                                </a>
                                <br>
                                <span class="text-xs font-normal text-zinc-500">{{ $i->bike->model }}</span>
                            </td>
                            <td>
                                @if($i->is_service)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-bold text-blue-700 border border-blue-200">
                                        <span class="size-1.5 rounded-full bg-blue-500"></span> Service
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 border border-amber-200">
                                        <span class="size-1.5 rounded-full bg-amber-500"></span> Repair
                                    </span>
                                @endif
                            </td>
                            <td class="max-w-xs">
                                <span class="truncate block" title="{{ $i->complaint }}">{{ $i->complaint }}</span>
                            </td>
                            <td>
                                @if($i->jobCard)
                                    <a href="{{ route('jobs.show', $i->jobCard) }}" class="font-semibold text-blue-600 hover:underline" title="Open Job Card">
                                        {{ $i->jobCard->number }}
                                    </a>
                                @else
                                    <span class="text-xs font-medium text-slate-400">Not created</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-slate-600">
                                {{ ($i->intake_date ?? $i->created_at)->format('d M Y') }}
                            </td>
                            <td class="whitespace-nowrap">
                                @if($i->bike?->customer)
                                    <a href="{{ route('customers.show', $i->bike->customer) }}" class="font-semibold text-slate-900 hover:text-blue-600 hover:underline">
                                        {{ $i->bike->customer->name ?: 'Walk-in' }}
                                    </a>
                                    <br>
                                    <span class="text-xs text-slate-500">{{ $i->bike->customer->phone }}</span>
                                @else
                                    <span class="text-slate-500 font-semibold">Walk-in</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('intakes.edit', $i) }}" class="text-slate-400 hover:text-blue-600 mr-2 inline-block" title="Edit">
                                    <svg class="size-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                </a>
                                <form method="POST" action="{{ route('intakes.destroy', $i) }}" data-confirm="Delete this intake?" class="inline">
                                    @csrf<x-submission-token/> @method('DELETE')
                                    <button type="submit" class="text-slate-400 hover:text-red-600" title="Delete">
                                        <svg class="size-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-slate-500">No intakes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $intakes->links() }}
    </div>
</x-layouts::app>
