@if($sheet->additions->isNotEmpty() || $sheet->paymentDeductions->isNotEmpty())
<section class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
    <h2 class="font-black text-sm">Adjustment descriptions</h2>
    <p class="mt-1 text-xs text-slate-500">These items are included in the manual addition and deduction totals above.</p>
    <table class="mt-3 w-full text-sm">
        <thead><tr class="border-b"><th class="py-2 text-left">Type / description</th><th class="py-2 text-right">Amount (LKR)</th></tr></thead>
        <tbody>
        @foreach($sheet->additions as $item)
            <tr class="border-b"><td class="py-2"><strong class="text-emerald-700">Addition · {{ $item->addition_name }}</strong>@if($item->reason)<p class="text-xs text-slate-600">{{ $item->reason }}</p>@endif</td><td class="text-right font-bold text-emerald-700">+ {{ number_format($item->amount, 2) }}</td></tr>
        @endforeach
        @foreach($sheet->paymentDeductions as $item)
            <tr class="border-b"><td class="py-2"><strong class="text-red-700">Deduction · {{ $item->deduction_name }}</strong>@if($item->reason)<p class="text-xs text-slate-600">{{ $item->reason }}</p>@endif</td><td class="text-right font-bold text-red-700">− {{ number_format($item->amount, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif
