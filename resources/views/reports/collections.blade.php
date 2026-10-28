<x-layouts::app title="Payment Collections Report">
  <div class="space-y-6">
    @include('reports._filter', ['title' => 'Payment Collections Report', 'subtitle' => 'Receipts by payment date, net of refunds to date. Held cheques and non-cash offsets are separate. This is not a cash-flow statement.'])
    @include('reports._stats')
    <div class="panel p-0 overflow-hidden">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Receipt</th><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Bike</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Net amount</th></tr></thead><tbody class="divide-y divide-slate-100">
          @forelse($rows as $row)<tr><td class="px-4 py-3">{{ $row->paid_at->format('d M Y h:i A') }}</td><td class="px-4 py-3">{{ $row->number }}</td><td class="px-4 py-3">{{ $row->invoice?->number }}</td><td class="px-4 py-3">{{ $row->invoice?->customer?->name ?? $row->invoice?->jobCard?->customer?->name ?? 'Walk-in' }}</td><td class="px-4 py-3">{{ $row->invoice?->bike?->number ?? $row->invoice?->jobCard?->bike?->number }}</td><td class="px-4 py-3">{{ $row->method }}</td><td class="px-4 py-3">{{ $row->status }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->amount - $row->refunded_amount, 2) }}</td></tr>
          @empty<tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No records match these filters.</td></tr>@endforelse
          </tbody>
        </table>
      </div>
      <div class="p-4 print:hidden">{{ $rows->links() }}</div>
    </div>
  </div>
</x-layouts::app>
