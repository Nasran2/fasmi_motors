<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-3">
  @foreach($stats ?? [] as $label => $value)
    @php
      $count = str_ends_with($label, '_count') || in_array($label, ['due_invoices', 'due_customers', 'completed', 'received', 'assigned', 'billed', 'delivered', 'in_progress', 'pending', 'waiting_for_parts', 'cancelled', 'in_stock', 'low', 'out', 'active', 'present', 'absent', 'late', 'leave', 'staff_count', 'upcoming', 'sent', 'overdue', 'failed', 'items_sold']);
      $money = !$count;
    @endphp
    <div data-print-summary class="panel border border-slate-200 rounded-xl p-4 bg-white">
      <p class="text-xs text-slate-500 font-bold uppercase tracking-wide mb-2">{{ $statLabels[$label] ?? ucwords(str_replace('_', ' ', $label)) }}</p>
      <p class="text-2xl font-black text-slate-900">{{ $money ? 'Rs. ' : '' }}{{ number_format((float) $value, $money || $label === 'items_sold' ? 2 : 0) }}</p>
    </div>
  @endforeach
</div>
