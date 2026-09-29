<x-layouts::app title="Bike Ownership Change"><div class="space-y-5"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Customers & Bikes / Ownership</p><h1 class="page-title">Bike Ownership Change</h1><p class="page-subtitle">Transfer bike ownership from old customer to new customer</p></div><x-flash/><form method="POST" action="{{ route('customers.ownership.store') }}" class="grid gap-5 xl:grid-cols-2" id="ownership-form">@csrf<x-submission-token/><section class="panel"><h2 class="text-lg font-extrabold">Search Bike & Current Owner</h2>@php($chosenBike = $bikes->firstWhere('id', (int) old('bike_id', request('bike'))))<label class="mt-4 block"><span class="label">Search / Select Bike *</span><input type="search" list="ownership-bike-options" data-bike-search class="field" value="{{ $chosenBike ? ($chosenBike->number.' — '.$chosenBike->model.' — '.($chosenBike->customer->name ?: $chosenBike->customer->phone)) : '' }}" placeholder="Type bike number, model or owner" required autocomplete="off"><input type="hidden" name="bike_id" value="{{ old('bike_id', request('bike')) }}" data-bike-id><datalist id="ownership-bike-options">@foreach($bikes as $bike)<option value="{{ $bike->number }} — {{ $bike->model }} — {{ $bike->customer->name ?: $bike->customer->phone }}"></option>@endforeach</datalist></label><div class="mt-4 grid gap-3 sm:grid-cols-2"><div class="rounded-2xl bg-slate-50 p-4"><p class="text-xs font-bold text-slate-400">Current Owner</p><p class="mt-1 font-extrabold" data-current-owner>Select a bike</p><p class="text-xs text-slate-500" data-current-phone></p></div><div class="rounded-2xl bg-slate-50 p-4"><p class="text-xs font-bold text-slate-400">Bike Model</p><p class="mt-1 font-extrabold" data-current-model>—</p></div></div><div class="mt-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-700">Previous repair history remains permanently attached to the bike. Only future jobs use the new owner.</div></section>
<section class="panel"><h2 class="text-lg font-extrabold">New Owner Details</h2><div class="mt-4 space-y-4"><fieldset style="margin-bottom:18px;">
    <legend class="label">New owner</legend>
    <div class="ownership-owner-modes">
        <label><input type="radio" name="owner_mode" value="existing" @checked(old('owner_mode', 'existing') === 'existing')><span>Existing customer</span></label>
        <label><input type="radio" name="owner_mode" value="new" @checked(old('owner_mode') === 'new')><span><flux:icon.plus/>Add new customer</span></label>
    </div>
</fieldset>
<div data-existing-owner>
    <label><span class="label">Search Existing Customer *</span><input type="search" list="ownership-customer-options" data-owner-search class="field" placeholder="Type customer name or phone" required autocomplete="off"><input type="hidden" name="new_customer_id" value="{{ old('new_customer_id') }}" data-owner-id><datalist id="ownership-customer-options">@foreach($customers as $customer)<option value="{{ ($customer->name ?: 'Walk-in').' — '.$customer->phone }}"></option>@endforeach</datalist></label>
</div>
<div data-new-owner hidden class="ownership-new-fields">
    <label><span class="label">Customer name *</span><input name="new_name" value="{{ old('new_name') }}" class="field" maxlength="150" autocomplete="name" disabled required></label>
    <label><span class="label">Phone number *</span><input name="new_phone" type="tel" value="{{ old('new_phone') }}" class="field" maxlength="30" autocomplete="tel" disabled required></label>
    <label style="grid-column:1/-1;"><span class="label">Address (optional)</span><input name="new_address" value="{{ old('new_address') }}" class="field" maxlength="500" autocomplete="street-address" disabled></label>
</div><label><span class="label">Ownership Change Date *</span><input type="date" name="changed_at" value="{{ old('changed_at', today()->toDateString()) }}" class="field" required></label><label><span class="label">Due Handling *</span><select name="due_handling" class="field" required><option value="keep_old" @selected(old('due_handling', 'keep_old') === 'keep_old')>Keep old due with previous owner</option><option value="transfer_new" @selected(old('due_handling') === 'transfer_new')>Transfer existing due to new owner</option><option value="clear" @selected(old('due_handling') === 'clear')>Clear due manually with reason</option></select></label><label><span class="label">Reason / Note</span><textarea name="reason" class="field" rows="3" placeholder="Reason for ownership change">{{ old('reason') }}</textarea></label></div><div class="mt-5 flex justify-end gap-2"><a href="{{ route('customers.bikes.index') }}" class="btn-secondary">Cancel</a><button class="btn-primary">Save Ownership Change</button></div></section></form>
<div class="table-wrap"><div class="p-5"><h2 class="text-lg font-extrabold">Ownership Change History</h2></div><table class="data-table min-w-[900px]"><thead><tr><th>Bike Number</th><th>Old Owner</th><th>New Owner</th><th>Changed Date</th><th>Due Handling</th><th>Due Amount</th><th>Changed By</th></tr></thead><tbody>@forelse($changes as $change)<tr><td class="font-black text-red-600">{{ $change->bike->number }}</td><td>{{ $change->oldCustomer->name ?: $change->oldCustomer->phone }}</td><td>{{ $change->newCustomer->name ?: $change->newCustomer->phone }}</td><td>{{ $change->changed_at->format('d M Y') }}</td><td>{{ str($change->due_handling)->replace('_',' ')->title() }}</td><td>Rs. {{ number_format($change->due_amount,2) }}</td><td>{{ $change->changedBy->name }}</td></tr>@empty<tr><td colspan="7" class="py-14 text-center text-slate-400">Ownership transfers will be listed here with a permanent audit trail.</td></tr>@endforelse</tbody></table><div class="border-t p-4">{{ $changes->links() }}</div></div>
<style>
.ownership-owner-modes { display:flex; gap:4px; padding:4px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; }
.ownership-owner-modes label { flex:1; cursor:pointer; min-width:0; position:relative; }
.ownership-owner-modes input { position:absolute; opacity:0; width:1px; height:1px; }
.ownership-owner-modes span { display:flex; align-items:center; justify-content:center; gap:6px; min-height:38px; padding:8px; border-radius:4px; font-size:13px; font-weight:700; color:#64748b; }
.ownership-owner-modes svg { width:16px; height:16px; flex-shrink:0; }
.ownership-owner-modes input:checked+span { background:#fff; color:#dc2626; box-shadow:0 1px 3px #0f172a15; }
.ownership-owner-modes input:focus-visible+span { outline:2px solid #ef4444; }
.ownership-new-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
.ownership-new-fields[hidden], [data-existing-owner][hidden] { display:none; }
@media(max-width:600px) { .ownership-new-fields { grid-template-columns:minmax(0,1fr); } .ownership-owner-modes span { font-size:12px; } }
</style>
<script>
(() => {
    const form = document.getElementById('ownership-form');
    const bikes = {{ Illuminate\Support\Js::from($bikeSearchOptions) }};
    const customers = {{ Illuminate\Support\Js::from($customerSearchOptions) }};
    const bikeSearch = form.querySelector('[data-bike-search]');
    const bikeId = form.querySelector('[data-bike-id]');
    const ownerSearch = form.querySelector('[data-owner-search]');
    const ownerId = form.querySelector('[data-owner-id]');
    const existingOwner = form.querySelector('[data-existing-owner]');
    const newOwner = form.querySelector('[data-new-owner]');
    const isNewOwner = () => form.querySelector('[name="owner_mode"]:checked').value === 'new';
    const syncBike = () => {
        const match = bikes.find(bike => bike.label.toLowerCase() === bikeSearch.value.trim().toLowerCase());
        bikeId.value = match?.id || '';
        bikeSearch.setCustomValidity(bikeSearch.value && !match ? 'Choose a bike from the search results.' : '');
        form.querySelector('[data-current-owner]').textContent = match?.owner || 'Select a bike';
        form.querySelector('[data-current-phone]').textContent = match?.phone || '';
        form.querySelector('[data-current-model]').textContent = match?.model || '—';
    };
    const syncOwner = () => {
        if (isNewOwner()) {
            ownerSearch.setCustomValidity('');
            return;
        }
        const match = customers.find(customer => customer.label.toLowerCase() === ownerSearch.value.trim().toLowerCase());
        ownerId.value = match?.id || '';
        ownerSearch.setCustomValidity(ownerSearch.value && !match ? 'Choose a customer from the search results.' : '');
    };
    const setOwnerMode = () => {
        const createNew = isNewOwner();
        existingOwner.hidden = createNew;
        newOwner.hidden = !createNew;
        ownerSearch.disabled = createNew;
        ownerSearch.required = !createNew;
        ownerId.disabled = createNew;
        newOwner.querySelectorAll('input').forEach(input => input.disabled = !createNew);
        syncOwner();
    };
    const selectedOwner = customers.find(customer => String(customer.id) === ownerId.value);
    if (selectedOwner) ownerSearch.value = selectedOwner.label;
    form.querySelectorAll('[name="owner_mode"]').forEach(input => input.addEventListener('change', setOwnerMode));
    bikeSearch.addEventListener('input', syncBike);
    ownerSearch.addEventListener('input', syncOwner);
    form.addEventListener('submit', event => {
        syncBike();
        syncOwner();
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });
    syncBike();
    setOwnerMode();
})();
</script></div></x-layouts::app>
