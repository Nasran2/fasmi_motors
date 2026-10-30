<form method="POST" action="{{ $recording ? route('cashier.historical-opening') : ($editing ? route('cashier.opening-balances', $editingSession) : route('cashier.open')) }}" data-cashier-opening-form class="space-y-5">
    @csrf<x-submission-token /><input type="hidden" name="separate_cash_balance" value="1">
    @if($recording)<input type="hidden" name="date" value="{{ $openingDate }}">@endif
    <input type="hidden" name="balance_version" value="{{ $balanceVersion }}">
    <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-widest text-amber-700">{{ \Carbon\Carbon::parse($openingDate)->format('d M Y') }} · {{ ucfirst(session('shop_mode', 'workshop')) }}</p><h2 id="cashier-opening-title" class="mt-2 text-2xl font-black text-slate-900">{{ $recording ? 'Record Opening Balances' : ($editing ? 'Edit Opening Balances' : 'Open Cashier') }}</h2><p class="mt-2 text-sm text-slate-500">{{ $editing ? 'Enter the cash held at the start of this date, before its collections. A reason is required. This does not reopen the cashier or change receipts and recorded cash counts.' : 'Enter cashier cash, confirm the separate account balances and choose today’s washing person. You can open and close again at any time today.' }}</p></div><button type="button" data-close-cashier-popup class="btn-secondary" aria-label="Close opening popup">✕</button></div>
    @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <label class="block rounded-xl border-2 border-blue-300 bg-blue-50 p-4"><span class="mb-2 block text-xs font-black uppercase tracking-wide text-blue-800">Physical cashier cash</span><span class="label">Opening cash in cashier · LKR</span><input name="opening_balance" type="number" step="0.01" min="0" max="999999999999.99" required value="{{ old('opening_balance', $suggestedOpening) }}" data-suggested="{{ $suggestedOpening }}" class="field text-xl font-bold" autofocus><span class="mt-2 block text-xs text-slate-600">Recorded cashier cash: Rs. {{ number_format($suggestedOpening, 2) }}. Enter the physical cash in the drawer. Account balances and Bank are separate.</span></label>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach(['main' => 'Main Account', 'spare_parts' => 'Spare Parts Account', 'bank' => 'Bank Account'] as $type => $label)
            <label class="block"><span class="label">{{ $label }} opening balance · LKR</span><input name="opening_balances[{{ $type }}]" data-opening-account data-suggested="{{ $openingBalances[$type] }}" type="number" min="-999999999999.99" max="999999999999.99" step="0.01" required value="{{ old('opening_balances.'.$type, $openingBalances[$type]) }}" class="field"><span class="mt-2 block text-xs text-slate-500">Automatic: Rs. {{ number_format($openingBalances[$type], 2) }}. Change if needed.@if($type === 'bank') Excluded from cash in the cashier.@endif</span></label>
        @endforeach
        @if($openingBalances['washing'] != 0)
            <label class="block"><span class="label">Washing Account opening balance · LKR</span><input name="opening_balances[washing]" data-opening-account data-suggested="{{ $openingBalances['washing'] }}" type="number" min="-999999999999.99" max="999999999999.99" step="0.01" required value="{{ old('opening_balances.washing', $openingBalances['washing']) }}" class="field"></label>
        @else
            <input type="hidden" name="opening_balances[washing]" value="0" data-opening-account data-suggested="0">
        @endif
    </div>
    <p class="text-sm font-semibold" aria-live="polite" data-opening-total></p>
    <p class="text-xs text-slate-500">Cash in the cashier and these account balances are entered independently. They do not need to match or add up. Cash receipts and cash payments update the drawer separately.</p>
    <label class="block"><span class="label">Reason for changed opening balances</span><input name="opening_reason" @required($editing) data-always-required="{{ $editing ? 1 : 0 }}" value="{{ old('opening_reason') }}" maxlength="1000" class="field"></label>
    @if($editing)
        <div class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">Cash received on this date: <strong>Rs. {{ number_format($summary['cash_received'], 2) }}</strong>. Bank received: Rs. {{ number_format($summary['bank_received'], 2) }} (excluded from cash in hand). Opening corrections are not collections.</div>
    @endif
    @if($canRecalculateToday)
        <label class="block rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950"><span class="flex items-start gap-2 font-bold"><input type="checkbox" name="recalculate_today" value="1" @checked(old('recalculate_today', true)) class="mt-1">Recalculate today from these opening balances</span><span class="mt-2 block">Use these amounts as today’s starting cash, then apply actual receipts and cash movements. Earlier counted amounts remain in history. A reason is required.</span></label>
    @elseif($editing)
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">A saved closing count or later opening stays as recorded. Any difference caused by this correction is recorded as a cash reconciliation, with your reason.</p>
    @endif
    @unless($editing)
    <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 space-y-4">
        <label class="block"><span class="label">Today’s washing person</span><select name="washing_person_id" class="field"><option value="">Assign later</option>@foreach($technicians as $person)<option value="{{ $person->id }}" @selected((string) old('washing_person_id', $washingPerson) === (string) $person->id)>{{ $person->name }}</option>@endforeach</select></label>
        <label class="block"><span class="label">Washing commission per job · LKR</span><input name="washing_commission_amount" type="number" min="0" max="999999999999.99" step="0.01" value="{{ old('washing_commission_amount', $defaultAmount) }}" required class="field"><span class="mt-2 block text-xs text-slate-600">Fixed amount for each job with washing. Current default: Rs. {{ number_format($defaultAmount, 2) }}.</span></label>
        <label class="flex items-center gap-2 text-sm font-semibold"><input name="save_washing_default" value="1" type="checkbox" @checked(old('save_washing_default'))>Use this amount as the default for future openings</label>
    </div>
    <p class="text-xs text-slate-500">New job cards and quick jobs inherit this assignment. You can change the washing person and rate for each job.</p>
    @endunless
    <div class="flex justify-end gap-3 border-t pt-4"><button type="button" data-close-cashier-popup class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">{{ $editing ? 'Save Opening Balances' : 'Open Cashier & Start Day' }}</button></div>
</form>
