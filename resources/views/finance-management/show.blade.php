<x-layouts::app title="{{ ucfirst($module) }} · {{ $record->number }}">
<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase text-red-600">{{ ucfirst($module) }} details</p><h1 class="page-title">{{ $record->number }}</h1><p class="page-subtitle">{{ $record->status }}{{ $record->trashed() ? ' · Archived' : '' }}</p></div><a href="{{ route($module === 'expense' ? 'expenses.index' : 'drawings.index') }}" class="btn-secondary">View {{ $module === 'expense' ? 'Expenses' : 'Drawings' }}</a></div>
    <section class="panel p-5"><dl class="grid gap-5 sm:grid-cols-2">
    @foreach(['Date' => $record->{$module.'_date'}?->format('d M Y'), 'Amount' => 'Rs. '.number_format($record->amount,2), 'Account' => $record->account?->name,
        'Payment method' => $record->payment_method, 'Vendor' => $record->vendor, 'Category / type' => ($module === 'expense' ? $record->category : $record->drawing_type),
        'Reference' => $record->reference_number, 'Description' => ($module === 'expense' ? $record->description : $record->person_name), 'Note' => $record->note,
        'Recorded by' => $record->creator?->name, 'Cancellation reason' => $record->cancel_reason] as $label => $value)
        @if($value !== null && $value !== '')<div><dt class="text-xs font-bold uppercase text-slate-500">{{ $label }}</dt><dd class="mt-1 whitespace-pre-wrap text-base font-semibold">{{ $value }}</dd></div>@endif
    @endforeach
    </dl></section>
</div>
</x-layouts::app>
