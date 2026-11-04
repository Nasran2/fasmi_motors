<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase text-red-600">Separate commission</p><h2 class="text-2xl font-black">{{ $technician->name }}</h2><p class="text-sm text-slate-500">{{ $period['label'] }}</p></div><a target="_blank" class="btn-secondary" href="{{ route('technicians.commissions.slip', ['technician' => $technician->id] + request()->only(['period','date','month','from','to'])) }}">Print / Save Commission Slip</a></div>
    @include('technicians.partials.commission-report')
    @if($totals['balance'] > 0)
    <div class="grid gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('technicians.commissions.deduct', $technician) }}" class="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4">
            @csrf<x-submission-token />
            @foreach(request()->only(['period','date','month','from','to']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <input type="hidden" name="balance_version" value="{{ $balanceVersion }}">
            <h3 class="font-black text-red-800">Deduct from commission</h3><p class="text-xs text-slate-600">Deductions apply to the oldest outstanding bills in this period. This does not deduct salary or record a cash expense.</p>
            <label class="block"><span class="label">Description / reason</span><input name="description" minlength="3" maxlength="500" class="field" placeholder="e.g. Agreed tool recovery" required></label>
            <label class="block"><span class="label">Amount · LKR</span><input name="amount" type="number" step="0.01" min="0.01" max="{{ $totals['balance'] }}" class="field" required></label>
            <button class="btn-primary">Save Commission Deduction</button>
        </form>
        <form method="POST" action="{{ route('technicians.commissions.pay', $technician) }}" class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            @csrf<x-submission-token />
            @foreach(request()->only(['period','date','month','from','to']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <input type="hidden" name="balance_version" value="{{ $balanceVersion }}">
            <h3 class="font-black text-emerald-800">Pay commission</h3><p class="text-xs text-slate-600">Pay all or part of this balance. Saving posts the selected payment to the accounts and leaves salary unchanged.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Amount · LKR</span><input name="amount" type="number" step="0.01" min="0.01" max="{{ $totals['balance'] }}" value="{{ $totals['balance'] }}" class="field" required></label>
                <label><span class="label">Payment date</span><input name="payment_date" type="date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="field" required></label>
                <x-outgoing-funding default="main" class="sm:col-span-2"/>
                <label><span class="label">Payment method</span><select name="payment_method" class="field" required>@foreach(['Cash','Bank Transfer','Card','Online','Other'] as $method)<option>{{ $method }}</option>@endforeach</select></label>
            </div>
            <label class="block"><span class="label">Payment note</span><input name="note" maxlength="1000" class="field"></label>
            <button class="btn-primary">Record Commission Payment</button>
        </form>
    </div>
    @endif
</div>
