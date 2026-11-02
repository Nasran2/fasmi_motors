<x-layouts::app title="Technician Commission">
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase text-red-600">Staff / Separate Commission</p><h1 class="page-title">Technician Commission</h1><p class="page-subtitle">View commission by person, deduct with a reason and pay separately from salary.</p></div><a href="#commission-form" class="btn-primary">Add Manual Commission</a></div>
    <x-flash />
    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Earned commission, including washing commission, does not deduct cash or account balances automatically. Use Pay to record money paid manually. Deduct only reduces the commission owed to the staff member; it does not record money paid out.</p>
    <form method="GET" class="panel grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4" x-data="{ period: @js(request('period', 'month')) }">
        <label><span class="label">Report period</span><select name="period" x-model="period" class="field"><option value="day">Daily</option><option value="month">Monthly</option><option value="custom">Custom dates</option><option value="all">All dates</option></select></label>
        <label x-show="period === 'day'"><span class="label">Date</span><input name="date" type="date" value="{{ request('date', today()->toDateString()) }}" class="field"></label>
        <label x-show="period === 'month'"><span class="label">Month</span><input name="month" type="month" value="{{ request('month', today()->format('Y-m')) }}" class="field"></label>
        <label x-show="period === 'custom'"><span class="label">From</span><input name="from" type="date" value="{{ request('from') }}" class="field" :required="period === 'custom'"></label>
        <label x-show="period === 'custom'"><span class="label">To</span><input name="to" type="date" value="{{ request('to') }}" class="field" :required="period === 'custom'"></label>
        <label><span class="label">Person</span><select name="technician" class="field"><option value="">All staff</option>@foreach($technicians as $person)<option value="{{ $person->id }}" @selected(request('technician') == $person->id)>{{ $person->name }}</option>@endforeach</select></label>
        <div class="flex items-end"><button class="btn-secondary w-full">Apply Filters</button></div>
    </form>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach(['earned'=>'Commission earned','deducted'=>'Commission deductions','paid'=>'Paid separately','balance'=>'Balance to pay'] as $key => $label)<article class="panel p-4"><p class="text-xs font-bold text-slate-500">{{ $label }} · {{ $period['label'] }}</p><p class="mt-2 text-xl font-black">Rs. {{ number_format($totals[$key],2) }}</p></article>@endforeach</div>
    <section class="panel overflow-hidden p-0"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Person</th><th class="p-3">Earned</th><th class="p-3">Deductions</th><th class="p-3">Paid separately</th><th class="p-3">Earlier salary / settlement</th><th class="p-3">Balance</th><th class="p-3">Actions</th></tr></thead><tbody>
    @forelse($groups as $group)@php($person = $group['technician'])<tr class="border-t border-slate-100"><td class="p-3 font-black">{{ $person?->name ?: 'Former staff' }}</td>@foreach(['earned','deducted','paid','covered','balance'] as $key)<td class="p-3">Rs. {{ number_format($group['totals'][$key],2) }}</td>@endforeach<td class="p-3">@if($person)<a href="{{ route('technicians.commissions.show', ['technician'=>$person->id] + request()->only(['period','date','month','from','to'])) }}" data-commission-view="{{ $person->id }}" class="btn-primary">View / Deduct / Pay</a>@endif</td></tr>@empty<tr><td colspan="7" class="p-8 text-center text-slate-500">No commission for this period.</td></tr>@endforelse
    </tbody></table></div></section>
    <form id="commission-form" x-data="{ job: '', type: 'Service / labor', base: '', bases: @js($jobs->pluck('service_labor_commission_base', 'id')), get usesJobBase() { return Boolean(this.job &amp;&amp; /service|labou?r|bill/i.test(this.type)); }, updateBase() { if (this.usesJobBase) this.base = this.bases[this.job] ?? 0; } }" method="POST" action="{{ route('technicians.commissions.store') }}" class="panel">@csrf<x-submission-token/><h2 class="text-lg font-extrabold">Commission Entry</h2><p class="mt-2 text-sm text-slate-500">For a linked job, Service / labor commission uses service + labor and excludes washing. Select Manual for a separate manual entry.</p><div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"><label><span class="label">Technician *</span><select name="technician_id" class="field" required><option value="">Select technician</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}">{{ $technician->name }}</option>@endforeach</select></label><label><span class="label">Job Card</span><select name="job_card_id" class="field" x-model="job" @change="updateBase()"><option value="">Manual commission</option>@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->number }} · Rs. {{ number_format($job->total,2) }}</option>@endforeach</select></label><label><span class="label">Commission Type *</span><select name="commission_type" class="field" x-model="type" @change="updateBase()">@foreach(['Service / labor','Spare parts profit','Manual'] as $type)<option>{{ $type }}</option>@endforeach</select></label><label><span class="label">Earned Date *</span><input type="date" name="earned_date" value="{{ today()->toDateString() }}" class="field" required></label><label><span class="label">Base Amount *</span><input type="number" step=".01" min="0" name="base_amount" class="field" required x-model="base" :readonly="usesJobBase"></label><label><span class="label">Commission % *</span><input type="number" step=".01" min="0" max="100" name="percentage" class="field" required></label><label><span class="label">Manual Amount</span><input type="number" step=".01" min="0" name="amount" class="field" placeholder="Auto when empty" :disabled="usesJobBase"></label><label><span class="label">Note</span><input name="note" class="field"></label></div><div class="mt-4 text-right"><button class="btn-primary">Add Commission</button></div></form>
</div>
<dialog id="commission-popup" class="w-full max-w-6xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl"><div class="mb-3 flex justify-end"><button type="button" onclick="document.getElementById('commission-popup').close()" class="btn-secondary" aria-label="Close commission details">Close ✕</button></div><div id="commission-popup-content" role="status"></div></dialog>
<script>
(() => {
    const popup = document.getElementById('commission-popup');
    const content = document.getElementById('commission-popup-content');
    let requestNumber = 0;
    async function showCommission(link) {
        const number = ++requestNumber;
        content.textContent = 'Loading commission details…';
        if (!popup.open) popup.showModal();
        try {
            const response = await fetch(link.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok || response.redirected) throw new Error('Commission details could not be loaded. Reload this page and try again.');
            const html = await response.text();
            if (number === requestNumber) content.innerHTML = html;
        } catch (error) { if (number === requestNumber) content.textContent = error.message; }
    }
    document.querySelectorAll('[data-commission-view]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); showCommission(link); }));
    const reopen = @js(session('commission_open'));
    if (reopen) { const link = document.querySelector(`[data-commission-view="${reopen}"]`); if (link) showCommission(link); }
})();
</script>
</x-layouts::app>
