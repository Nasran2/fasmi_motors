<section id="customer-bills" class="mt-6 scroll-mt-24" aria-labelledby="customer-bills-heading">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h2 id="customer-bills-heading" class="text-lg font-bold text-slate-900">Customer Bills</h2>
            <p class="mt-1 text-sm text-slate-500">Job bills, parts sales and opening balances.</p>
        </div>
        <span class="shrink-0 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">{{ number_format($bills->total()) }} records</span>
    </div>
    <div class="table-wrap">
        <table class="data-table w-full min-w-[780px] [&_th]:!px-2 [&_td]:!px-2 [&_td]:!py-3">
            <thead><tr><th>Bill / Date</th><th>Bike / Job</th><th>Total</th><th>Paid</th><th>Due</th><th>Payment Status</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($bills as $bill)
                    @php
                        $billBike = $bill->bike ?? $bill->jobCard?->intake?->bike;
                        $cancelled = $bill->status === 'Cancelled';
                        $statusLabel = $bill->status === 'Paid' ? 'Completed' : $bill->status;
                        $statusTone = match ($bill->status) {
                            'Paid' => 'bg-emerald-50 text-emerald-700',
                            'Hold', 'Partially Paid / Hold' => 'bg-amber-50 text-amber-700',
                            'Cancelled' => 'bg-slate-100 text-slate-500',
                            default => 'bg-red-50 text-red-600',
                        };
                        $billUrl = auth()->user()->canAccess('sales.view_invoices')
                            ? route('sales.invoices.show', $bill)
                            : ($bill->public_token && $bill->link_enabled && (! $bill->link_expires_at || $bill->link_expires_at->isFuture())
                                ? route('invoices.public', $bill->public_token) : null);
                    @endphp
                    <tr>
                        <td>
                            <span class="block whitespace-nowrap font-bold text-slate-900">{{ $bill->number }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $bill->created_at->format('d M Y') }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $bill->isOpeningDue() ? 'Opening balance' : $bill->invoice_type }}</span>
                        </td>
                        <td>
                            <span class="block whitespace-nowrap font-semibold">{{ $billBike?->number ?: '—' }}</span>
                            @if($bill->jobCard)<span class="mt-1 block text-xs text-slate-500">{{ $bill->jobCard->number }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap">Rs. {{ number_format($bill->total, 2) }}</td>
                        <td class="whitespace-nowrap text-emerald-600">Rs. {{ number_format($bill->paid_amount, 2) }}@if($bill->hold_amount > 0)<span class="mt-1 block text-xs text-amber-600">Cheque Hold<br>Rs. {{ number_format($bill->hold_amount, 2) }}</span>@endif</td>
                        <td class="whitespace-nowrap font-bold {{ ! $cancelled && $bill->due_amount > 0 ? 'text-red-600' : 'text-slate-500' }}">{{ $cancelled ? '—' : 'Rs. '.number_format($bill->due_amount, 2) }}</td>
                        <td><span class="inline-block rounded-lg px-2.5 py-1 text-xs font-bold {{ $statusTone }}">{{ $statusLabel }}</span></td>
                        <td>
                            <div class="flex flex-col items-start gap-2">
                                @if($billUrl)<a href="{{ $billUrl }}" class="btn-secondary !px-3 !py-2 text-xs whitespace-nowrap">View Bill</a>@endif
                                @unless($cancelled)<x-due-payment-button :invoice-id="$bill->id" :due="$bill->due_amount" :number="$bill->number" :paid="$bill->paid_amount" :hold="$bill->hold_amount" class="!px-3 !py-2" />@endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-12 text-center text-slate-500">No bills recorded for this customer.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if($bills->hasPages())<div class="border-t p-4">{{ $bills->links() }}</div>@endif
    </div>
</section>
