<x-layouts::app title="Customer Due"><div class="space-y-5"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Customers & Bikes / Accounts</p><h1 class="page-title">Customer Due</h1><p class="page-subtitle">Track customer pending payments and due balances</p></div><x-flash/><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">@foreach([['Total Due Amount','Rs. '.number_format($stats['total'],2),'text-red-600'],['Due Customers',$stats['customers'],'text-orange-600'],['Overdue Amount','Rs. '.number_format($stats['overdue'],2),'text-violet-600'],['Today Collections','Rs. '.number_format($stats['collections'],2),'text-emerald-600']] as [$label,$value,$tone])<div class="panel"><p class="text-xs font-bold {{ $tone }}">{{ $label }}</p><p class="mt-2 text-3xl font-black">{{ $value }}</p></div>@endforeach</div>
<form class="panel grid gap-3 md:grid-cols-2 xl:grid-cols-6"><label class="xl:col-span-2"><span class="label">Search</span><input name="q" value="{{ request('q') }}" class="field" placeholder="Customer, phone, bike or invoice"></label><label><span class="label">Due From</span><input type="date" name="from" value="{{ request('from') }}" class="field"></label><label><span class="label">Due To</span><input type="date" name="to" value="{{ request('to') }}" class="field"></label><label><span class="label">Minimum Due</span><input type="number" name="minimum" value="{{ request('minimum') }}" class="field"></label><label><span class="label">Status</span><div class="flex gap-2"><select name="status" class="field"><option value="">All unpaid</option><option value="overdue" @selected(request('status')==='overdue')>Overdue</option></select><button class="btn-secondary">Filter</button></div></label></form>
<div class="flex flex-wrap items-center justify-between gap-3">
  <span class="text-sm text-slate-500" data-due-sms-selection>0 invoices selected</span>
  <button type="button" data-due-sms-bulk class="btn-secondary flex items-center gap-2" disabled><flux:icon.chat-bubble-left-right class="size-4"/> SMS Selected</button>
</div>
<div class="table-wrap">
  <table class="data-table min-w-[1350px]">
    <thead><tr><th><input type="checkbox" data-due-sms-all aria-label="Select all invoices on this page"></th><th>Customer</th><th>Phone</th><th>Bike</th><th>Invoice</th><th>Total Bill</th><th>Paid</th><th>Cheque Hold</th><th>Due</th><th>Due Date</th><th>Last Payment</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      @forelse($invoices as $invoice)
        @php($bike=$invoice->bike ?? $invoice->jobCard?->intake?->bike)
        @php($customer=$invoice->customer ?? $bike?->customer)
        <tr>
          <td><input type="checkbox" data-due-sms-select value="{{ $invoice->id }}" aria-label="Select invoice {{ $invoice->number }}"></td>
          <td><a @if($customer) href="{{ route('customers.show',$customer) }}" @endif class="font-bold">{{ $customer?->name ?: 'Walk-in' }}</a></td>
          <td>{{ $customer?->phone ?? 'Not provided' }}</td>
          <td>@if($bike)<a href="{{ route('customers.bikes.show',$bike) }}" class="font-bold text-red-600">{{ $bike->number }}</a>@else Not linked @endif</td>
          <td>{{ $invoice->number }}</td><td>Rs. {{ number_format($invoice->total,2) }}</td><td class="text-emerald-600">Rs. {{ number_format($invoice->paid_amount,2) }}</td><td class="text-amber-600">Rs. {{ number_format($invoice->hold_amount,2) }}</td><td class="font-black text-red-600">Rs. {{ number_format($invoice->due_amount,2) }}</td>
          <td>{{ $invoice->due_date?->format('d M Y') ?? 'Not set' }}</td><td>{{ $invoice->payments->sortByDesc('paid_at')->first()?->paid_at?->format('d M Y') ?? 'No payment' }}</td>
          <td><span class="rounded-lg px-2.5 py-1 text-xs font-bold {{ $invoice->due_date?->isPast()?'bg-red-50 text-red-600':'bg-orange-50 text-orange-600' }}">{{ $invoice->due_date?->isPast()?'Overdue':$invoice->status }}</span></td>
          <td><div class="flex items-center gap-2">
            <button type="button" data-payment-open data-invoice="{{ $invoice->id }}" data-number="{{ $invoice->number }}" data-due="{{ $invoice->due_amount }}" class="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-bold text-red-600">Receive Payment</button>
            <a href="{{ route('invoices.public',$invoice->public_token) }}" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-bold">Invoice</a>
            <button type="button" data-due-sms-one="{{ $invoice->id }}" title="SMS due reminder" aria-label="SMS due reminder for {{ $invoice->number }}" class="grid size-8 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-700"><flux:icon.chat-bubble-left-right class="size-4"/></button>
          </div></td>
        </tr>
      @empty
        <tr><td colspan="13" class="py-16 text-center"><p class="font-bold">No outstanding customer dues</p></td></tr>
      @endforelse
    </tbody>
  </table>
  <div class="border-t p-4">{{ $invoices->links() }}</div>
</div>
@include('customers.partials.due-sms')
<dialog id="due-payment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90vh] overflow-y-auto rounded-3xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/55"><form method="POST" class="p-6" data-payment-form>@csrf<x-submission-token/><div class="flex justify-between"><div><p class="text-xs font-bold uppercase tracking-wider text-red-600">Payment Collection</p><h2 class="mt-1 text-2xl font-black" data-payment-title>Receive Payment</h2></div><button type="button" data-payment-close class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl">×</button></div><div class="mt-5 rounded-2xl bg-red-50 p-4"><p class="text-xs font-bold text-red-500">Outstanding Due</p><p class="mt-1 text-2xl font-black text-red-700">Rs. <span data-payment-due>0.00</span></p></div><div class="mt-4"><x-split-payments :accounts="$accounts" /></div><div class="mt-5 flex justify-end gap-2"><button type="button" data-payment-close class="btn-secondary">Cancel</button><button class="btn-primary">Save Payment</button></div></form></dialog>
<script>(()=>{const dialog=document.getElementById('due-payment-dialog');const form=dialog?.querySelector('[data-payment-form]');document.querySelectorAll('[data-payment-open]').forEach(button=>button.addEventListener('click',()=>{form.action=`{{ url('customer-dues') }}/${button.dataset.invoice}/payment`;dialog.querySelector('[data-payment-title]').textContent=button.dataset.number;dialog.querySelector('[data-payment-due]').textContent=Number(button.dataset.due).toLocaleString(undefined,{minimumFractionDigits:2});dialog.showModal();}));dialog?.querySelectorAll('[data-payment-close]').forEach(x=>x.addEventListener('click',()=>dialog.close()));})();</script></div></x-layouts::app>
