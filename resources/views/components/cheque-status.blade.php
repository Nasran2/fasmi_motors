@props(['cheque'])
@php $color = $cheque->trashed() ? 'bg-slate-100 text-slate-500' : match($cheque->status) {'Cleared' => 'bg-emerald-50 text-emerald-700', 'Returned' => 'bg-red-50 text-red-600', 'Cancelled' => 'bg-slate-100 text-slate-600', default => 'bg-amber-50 text-amber-700'}; @endphp
<span class="inline-block whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold {{ $color }}">{{ $cheque->trashed() ? 'Deleted' : ($cheque->status === 'Cleared' ? 'Passed' : $cheque->status) }}</span>
