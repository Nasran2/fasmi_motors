@php $timingPenalties = data_get($sheet->calculation_snapshot, 'attendance_summary.timing_half_day_penalties', []); @endphp
@if(count($timingPenalties))
<section class="mt-4 rounded-xl border border-slate-200 p-4">
    <h2 class="font-bold text-sm">Late / early half-day deductions</h2>
    <p class="mt-1 text-xs text-slate-500">Each entry is a separate half day, included in the half-day deduction total. Its group’s occurrence fees are removed; incomplete groups remain under Late / Early leave deductions.</p>
    @foreach($timingPenalties as $penalty)
        <div class="mt-2 flex justify-between gap-3 text-xs">
            <span>{{ \Carbon\Carbon::parse($penalty['date'])->format('d M Y') }} · {{ $penalty['description'] }} · Half day (0.5)@if(isset($penalty['waived_fees']))<span class="block text-slate-500">Occurrence fees removed: Rs. {{ number_format($penalty['waived_fees'], 2) }}</span>@endif</span>
            <strong>Rs. {{ number_format($penalty['amount'], 2) }}</strong>
        </div>
    @endforeach
</section>
@endif
