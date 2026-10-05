@props(['cheque'])
@if($cheque)
<small class="block text-amber-700">Cheque {{ $cheque->cheque_number }} · {{ $cheque->cheque_date->format('d M Y') }}</small>
@if($cheque->forwarded_supplier_id)<small class="block text-violet-600">{{ $cheque->party_name }} → {{ $cheque->forwardedSupplier?->name }} (Customer → Supplier)</small>@endif
@endif
