<x-layouts::app title="Quotation List">
    <div class="space-y-5" x-data="{ cancelAction: '', cancelNumber: '' }">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Sales / Billing</p>
                <h1 class="page-title">Quotation List</h1>
                <p class="page-subtitle">All parts and accident bike quotations in one place.</p>
            </div>
            @if(auth()->user()->canAccess('sales.parts_sales'))
                <a href="{{ route('sales.accident-quotations.create') }}" class="btn-primary">＋ Accident Bike Quotation</a>
            @endif
        </div>

        <x-flash />

        @php($activeType = request('type', 'all'))
        <nav aria-label="Quotation type" class="flex flex-wrap gap-2">
            @foreach(['all' => 'All quotations', 'accident' => 'Accident quotations', 'normal' => 'Normal quotations'] as $type => $label)
                <a href="{{ route('sales.quotations', $type === 'all' ? request()->except('type', 'page') : array_merge(request()->except('type', 'page'), ['type' => $type])) }}"
                   @if($activeType === $type) aria-current="page" @endif
                   class="rounded-xl border px-4 py-2.5 text-sm font-bold transition-colors {{ $activeType === $type ? ($type === 'accident' ? 'border-violet-600 bg-violet-600 text-white' : ($type === 'normal' ? 'border-slate-900 bg-slate-900 text-white' : 'border-red-600 bg-red-600 text-white')) : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('sales.quotations') }}" class="panel grid gap-3 md:grid-cols-[minmax(0,1fr)_160px_170px_170px_auto] md:items-end">
            @if($activeType !== 'all')<input type="hidden" name="type" value="{{ $activeType }}">@endif
            <label>
                <span class="label">Search quotations</span>
                <input name="q" value="{{ request('q') }}" class="field" placeholder="Number, customer, phone or bike">
            </label>
            <label>
                <span class="label">Status</span>
                <select name="status" class="field">
                    <option value="">All statuses</option>
                    <option value="Draft" @selected(request('status') === 'Draft')>Draft</option>
                    <option value="Converted" @selected(request('status') === 'Converted')>Converted</option>
                    <option value="Cancelled" @selected(request('status') === 'Cancelled')>Cancelled</option>
                </select>
            </label>
            <label><span class="label">From</span><input type="date" name="from" value="{{ request('from') }}" class="field"></label>
            <label><span class="label">To</span><input type="date" name="to" value="{{ request('to') }}" class="field"></label>
            <button class="btn-secondary">Filter</button>
        </form>

        <div class="table-wrap">
            <table class="data-table min-w-[1160px]">
                <thead><tr><th>Quotation</th><th>Type</th><th>Date</th><th>Customer</th><th>Bike</th><th>Products</th><th>Charges</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse($quotations as $quotation)
                        <tr class="{{ $quotation->is_accident_quotation ? 'bg-violet-50/60' : '' }}">
                            <td><span class="font-black {{ $quotation->is_accident_quotation ? 'text-violet-700' : 'text-red-600' }}">{{ $quotation->number }}</span></td>
                            <td><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold {{ $quotation->is_accident_quotation ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600' }}">{{ $quotation->is_accident_quotation ? 'Accident bike' : 'Normal' }}</span></td>
                            <td class="whitespace-nowrap">{{ $quotation->created_at->format('d M Y') }}</td>
                            <td><strong>{{ $quotation->customer?->name ?: 'Walk-in Customer' }}</strong><span class="block text-xs text-slate-500">{{ $quotation->customer?->phone }}</span></td>
                            <td>{{ $quotation->bike?->number ?: 'Counter sale' }}@if($quotation->bike?->model)<span class="block text-xs text-slate-500">{{ $quotation->bike->model }}</span>@endif</td>
                            <td>{{ $quotation->items_count }}</td>
                            <td>{{ $quotation->charges_count }}</td>
                            <td class="whitespace-nowrap font-extrabold">Rs. {{ number_format((float) $quotation->total, 2) }}</td>
                            <td><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $quotation->status === 'Cancelled' ? 'bg-red-50 text-red-700' : ($quotation->status === 'Converted' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700') }}">{{ $quotation->status }}</span>@if($quotation->convertedInvoice)<a class="mt-1 block text-xs font-bold text-emerald-700 underline" href="{{ route('sales.invoices.show', $quotation->convertedInvoice) }}">{{ $quotation->convertedInvoice->number }}</a>@endif</td>
                            <td>
                                <div class="flex min-w-[205px] flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('sales.invoices.show', $quotation) }}" class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50">View</a>
                                        @if($quotation->status === 'Draft' && ! $quotation->is_accident_quotation && auth()->user()->canAccess('sales.parts_sales'))
                                            <a href="{{ route('sales.parts', ['quotation' => $quotation->id]) }}" class="inline-flex h-9 items-center justify-center whitespace-nowrap rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700">Open in POS</a>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 whitespace-nowrap text-xs font-semibold">
                                        <a href="{{ route('invoices.public', $quotation->public_token) }}" target="_blank" rel="noopener" class="text-slate-500 hover:text-slate-900 hover:underline">Preview</a>
                                        <a href="{{ route('sales.invoices.pdf', $quotation) }}" class="text-slate-500 hover:text-slate-900 hover:underline">PDF</a>
                                        @if($quotation->status === 'Draft' && auth()->user()->canAccess('sales.cancel_invoice'))
                                            <button type="button" @click="cancelAction = @js(route('sales.invoices.cancel', $quotation)); cancelNumber = @js($quotation->number); $refs.cancelDialog.showModal()" class="text-red-600 hover:text-red-700 hover:underline">Cancel</button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-12 text-center text-slate-500">No quotations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t p-4">{{ $quotations->links() }}</div>
        </div>
        <dialog x-ref="cancelDialog" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60">
            <form method="POST" :action="cancelAction" class="space-y-4 p-6">
                @csrf @method('DELETE') <x-submission-token />
                <div><h2 class="text-lg font-black text-slate-900">Cancel quotation</h2><p class="mt-1 text-sm text-slate-600">Cancel <strong x-text="cancelNumber"></strong>? Enter a reason for the record.</p></div>
                <label class="block text-sm font-bold text-slate-700">Reason
                    <textarea name="reason" required minlength="5" maxlength="500" rows="3" class="field mt-1" placeholder="Why is this quotation being cancelled?"></textarea>
                </label>
                <div class="flex justify-end gap-2"><button type="button" @click="$refs.cancelDialog.close()" class="btn-secondary">Keep quotation</button><button type="submit" class="rounded-xl bg-red-600 px-4 py-2.5 font-bold text-white hover:bg-red-700">Cancel quotation</button></div>
            </form>
        </dialog>
    </div>
</x-layouts::app>
