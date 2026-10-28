<x-layouts::app title="Supplier Due Bills Report">
  <div class="space-y-6">
    @include('reports._filter', ['title' => 'Supplier Due Bills Report', 'subtitle' => 'Current unpaid purchase balances, filtered by purchase date. Supplier opening balances and advances are available in the supplier account.'])
    @include('reports._stats')
    <div class="panel p-0 overflow-hidden">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Purchase date</th><th class="px-4 py-3">Purchase</th><th class="px-4 py-3">Supplier bill</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Net amount</th><th class="px-4 py-3">Paid</th><th class="px-4 py-3">Held</th><th class="px-4 py-3">Outstanding</th></tr></thead><tbody class="divide-y divide-slate-100">
          @forelse($rows as $row)<tr><td class="px-4 py-3">{{ $row->purchase_date->format('d M Y') }}</td><td class="px-4 py-3">{{ $row->number }}</td><td class="px-4 py-3">{{ $row->invoice_number }}</td><td class="px-4 py-3">{{ $row->supplier?->name }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->total_amount - $row->returned_amount, 2) }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->paid_amount, 2) }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->hold_amount, 2) }}</td><td class="px-4 py-3">{{ 'Rs. '.number_format($row->due_amount, 2) }}</td></tr>
          @empty<tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No records match these filters.</td></tr>@endforelse
          </tbody>
        </table>
      </div>
      <div class="p-4 print:hidden">{{ $rows->links() }}</div>
    </div>
  </div>
</x-layouts::app>
