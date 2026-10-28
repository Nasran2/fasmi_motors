<x-layouts::app title="Sales Returns Report">
  <div class="space-y-6">
    @include('reports._filter', ['title' => 'Sales Returns Report', 'subtitle' => 'Returns by return date, including returns on subsequently cancelled invoices. Credit reduces sales balances; cash refunds are shown separately.'])
    @include('reports._stats')
    <div class="panel p-0 overflow-hidden">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Return date</th><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Credit</th><th class="px-4 py-3">Refund</th><th class="px-4 py-3">Reason</th></tr></thead><tbody class="divide-y divide-slate-100">
          @forelse($rows as $row)<tr><td class="px-4 py-3">{{ $row->created_at->format('d M Y h:i A') }}</td><td class="px-4 py-3">{{ $row->invoice?->number }}</td><td class="px-4 py-3">{{ $row->invoice?->customer?->name ?? $row->invoice?->jobCard?->customer?->name ?? 'Walk-in' }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->amount, 2) }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->refund_amount, 2) }}</td><td class="px-4 py-3">{{ $row->reason }}</td></tr>
          @empty<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No records match these filters.</td></tr>@endforelse
          </tbody>
        </table>
      </div>
      <div class="p-4 print:hidden">{{ $rows->links() }}</div>
    </div>
  </div>
</x-layouts::app>
