@props(['accounts', 'purchase' => false, 'partyCheques' => [], 'changeTotal' => null, 'totalFromForm' => false, 'partsTotal' => 0, 'previousBalance' => 0, 'duePreview' => false, 'allowCashChange' => true, 'fundingDateName' => 'payment_date'])
@php
    $methods = $purchase ? ['Cash','Bank Transfer','Card','Cheque','Party Cheque'] : ['Cash','Card','Bank Transfer','Cheque','QR'];
    $cashAccountId = $accounts->firstWhere('code', App\Models\Account::systemCode($purchase ? 'spare_parts' : 'main'))?->id ?? $accounts->firstWhere('type', 'main')?->id ?? $accounts->first()?->id;
    $bankAccountId = $accounts->firstWhere('code', App\Models\Account::systemCode('bank'))?->id ?? $accounts->firstWhere('type', 'bank')?->id ?? $cashAccountId;
    $fundingOptions = $purchase ? app(\App\Services\OutgoingFundingService::class)->options() : [];
    $initial = old('payments', [['method'=>'Cash','source'=>($purchase ? 'spare_parts' : null),'amount'=>0,'account_id'=>$cashAccountId,'cheque_date'=>'','cheque_number'=>'','bank_name'=>'','party_cheque_id'=>'']]);
    $chequeOptions = collect($partyCheques)->map(fn($c)=>['id'=>$c->id,'amount'=>(float)$c->amount])->values();
@endphp
<div class="space-y-3 text-slate-800" @if($purchase) data-purchase-funding data-options-url="{{ route('funding.options') }}" data-date-name="{{ $fundingDateName }}" data-funding-date="{{ today()->toDateString() }}" @funding-options-loaded="applyFundingOptions($event.detail.options)" @funding-date-state="fundingState=$event.detail.state" @endif x-data="{
    rows: {{ Illuminate\Support\Js::from($initial) }}, cheques: {{ Illuminate\Support\Js::from($chequeOptions) }},
    fundingOptions: {{ Illuminate\Support\Js::from($fundingOptions) }}, fundingState: 'loading',
    applyFundingOptions(options) {
        this.fundingOptions = options;
        this.rows.forEach(row => {
            if (row.method === 'Party Cheque') return;
            const option = options.find(option => option.value === row.source);
            if (!option || option.disabled || (row.method !== 'Cash' && option.value !== 'bank')) {
                row.source = '';
                row.account_id = '';
            } else row.account_id = option.account_id;
        });
    },
    fundingLabel(option) {
        return option.label + ' · Rs. ' + Number(option.balance).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + (option.disabled ? ' · ' + option.disabled_reason : '');
    },
    invoiceTotal: {{ Illuminate\Support\Js::from($changeTotal === null ? null : (float) $changeTotal) }},
    existingPaid: 0, existingHold: 0,
    allowCashChange: @js($allowCashChange),
    exceedsDue() { return (this.allowCashChange ? this.nonCash() : this.total()) > this.invoiceTotal; },
    remainingDue() { return Math.max(0, Math.round((this.invoiceTotal - this.total()) * 100) / 100); },
    paymentStatus() {
        if (this.existingHold + this.hold() > 0) return this.remainingDue() > 0 ? 'Partially Paid / Hold' : 'Hold';
        if (this.remainingDue() <= 0) return 'Completed';
        return this.existingPaid + this.total() > 0 ? 'Partially Paid' : 'Unpaid';
    },
    refreshTotal() {
        const form = this.$el.closest('form');
        const amount = name => Number(form.elements.namedItem(name)?.value || 0);
        const previous = form.querySelector('input[type=checkbox][name=include_previous_balance]')?.checked ? {{ (float) $previousBalance }} : 0;
        this.invoiceTotal = Math.max(0, Math.round(({{ (float) $partsTotal }} + amount('service_total') + amount('labor_total') + amount('washing_total') + amount('other_total') - amount('discount') + amount('tax')) * 100) / 100) + previous;
    },
    defaultAccount(method) { return ['Cheque','Card','Bank Transfer','QR'].includes(method) ? {{ Illuminate\Support\Js::from($bankAccountId) }} : {{ Illuminate\Support\Js::from($cashAccountId) }}; },
    add() { this.rows.push({method:'Cash',source:{{ $purchase ? "'spare_parts'" : 'null' }},amount:0,account_id:this.defaultAccount('Cash'),cheque_date:'',cheque_number:'',bank_name:'',party_cheque_id:''}); },
    total() { return Math.round(this.rows.reduce((n,r)=>n+Number(r.amount||0),0)*100)/100; },
    hold() { return Math.round(this.rows.filter(r=>['Cheque','Party Cheque'].includes(r.method)).reduce((n,r)=>n+Number(r.amount||0),0)*100)/100; },
    nonCash() { return Math.round(this.rows.filter(r=>r.method!=='Cash').reduce((n,r)=>n+Number(r.amount||0),0)*100)/100; },
    change() { return this.allowCashChange && this.invoiceTotal !== null && this.nonCash() <= this.invoiceTotal ? Math.max(0, Math.round((this.total()-this.invoiceTotal)*100)/100) : 0; }
}" @if($totalFromForm) x-init="refreshTotal()" @input.window="if ($el.closest('form').contains($event.target)) refreshTotal()" @endif
    @if($changeTotal !== null) @payment-due-selected.window="if ($el.closest('form') === $event.target) {
        invoiceTotal = Math.max(0, Number($event.detail.due) || 0);
        if ($event.detail.reset || $event.detail.paid !== undefined) existingPaid = Number($event.detail.paid) || 0;
        if ($event.detail.reset || $event.detail.hold !== undefined) existingHold = Number($event.detail.hold) || 0;
        if ($event.detail.reset) {
            rows = [{method:'Cash',source:{{ $purchase ? "'spare_parts'" : 'null' }},amount:0,account_id:defaultAccount('Cash'),cheque_date:'',cheque_number:'',bank_name:'',party_cheque_id:''}];
        }
    }" @endif>
    <div class="flex items-center justify-between gap-3">
        <div><h3 class="font-extrabold">Payments</h3><p class="mt-1 text-xs text-slate-500">{{ $purchase ? 'Split payment across account funds, the selected date’s cashier cash or Bank. Cashier sources require that date to be open.' : 'Add cash, bank payments or multiple cheques.' }}</p></div>
        <button type="button" class="btn-secondary text-xs shrink-0" @click="add()">＋ Add Payment</button>
    </div>
    @if($purchase)<div class="flex flex-wrap items-center gap-2"><p class="text-xs text-slate-600" data-funding-date-status role="status">Cashier availability and balances follow the payment date.</p><button type="button" class="text-xs font-bold text-blue-700" data-refresh-funding>Refresh balances</button></div>@endif
    <template x-for="(row,index) in rows" :key="index">
        <div class="payment-entry rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-3" @input="$dispatch('split-payment-change')" @change="$dispatch('split-payment-change')">
            <div class="flex items-center justify-between gap-2 text-xs">
                <span class="font-bold text-slate-500" x-text="'Payment ' + (index + 1)"></span>
                <button type="button" class="font-bold text-red-600 hover:text-red-700" x-show="rows.length>1" @click="rows.splice(index,1); $dispatch('split-payment-change')">Remove</button>
            </div>
            <div class="payment-fields">
                <label><span class="label">Method</span><select class="field" x-model="row.method" @change="row.account_id=defaultAccount(row.method); row.source=row.method==='Cash' ? 'spare_parts' : 'bank'" :name="`payments[${index}][method]`">@foreach($methods as $method)<option>{{ $method }}</option>@endforeach</select></label>
                <label><span class="label" @if($changeTotal !== null && $allowCashChange) x-text="row.method === 'Cash' ? 'Cash received' : 'Amount'" @endif>Amount</span><input class="field" type="number" min="0" step=".01" x-model.number="row.amount" :readonly="row.method==='Party Cheque'" :name="`payments[${index}][amount]`" required></label>
                @if($purchase)
                <label x-show="row.method !== 'Party Cheque'"><span class="label">Paid from source</span><select class="field" x-model="row.source" :disabled="fundingState!=='ready'" :name="`payments[${index}][source]`" :required="Number(row.amount)>0 && row.method!=='Party Cheque'" @change="row.account_id=$event.target.selectedOptions[0].dataset.account; if (row.source==='bank' && row.method==='Cash') row.method='Bank Transfer'">
                    <option value="">Select payment source</option>
                    <template x-for="option in fundingOptions" :key="option.value"><option :value="option.value" :data-account="option.account_id" :disabled="option.disabled || (row.method !== 'Cash' && option.value !== 'bank')" :class="option.disabled ? 'bg-slate-200 text-slate-400' : ''" x-text="fundingLabel(option)"></option></template>
                </select><input type="hidden" x-model="row.account_id" :name="`payments[${index}][account_id]`"></label>
                @else
                <label x-show="row.method !== 'Party Cheque'"><span class="label">{{ $purchase ? 'Paid From Account' : 'Receiving Account' }}</span><select class="field" x-model="row.account_id" :name="`payments[${index}][account_id]`" :required="Number(row.amount)>0 && row.method!=='Party Cheque'"><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                @endif
            </div>
            <template x-if="row.method==='Cheque'">
                <div class="border-t border-slate-200 pt-3 space-y-2">
                    <div class="payment-fields">
                        <label><span class="label">Cheque Date *</span><input class="field" type="date" x-model="row.cheque_date" :name="`payments[${index}][cheque_date]`" :required="Number(row.amount)>0"></label>
                        <label><span class="label">Cheque Number *</span><input class="field" x-model="row.cheque_number" maxlength="80" :name="`payments[${index}][cheque_number]`" :required="Number(row.amount)>0"></label>
                        <label><span class="label">Bank *</span><input class="field" x-model="row.bank_name" maxlength="120" :name="`payments[${index}][bank_name]`" :required="Number(row.amount)>0"></label>
                    </div>
                    <p class="text-xs text-amber-700">On hold until the cheque is marked passed.</p>
                </div>
            </template>
            @if($purchase)
            <template x-if="row.method==='Party Cheque'"><label class="block"><span class="label">Customer Cheque *</span><select class="field" x-model="row.party_cheque_id" :name="`payments[${index}][party_cheque_id]`" required @change="row.amount=cheques.find(c=>c.id==row.party_cheque_id)?.amount||0; $dispatch('split-payment-change')"><option value="">Select available customer cheque</option>@foreach($partyCheques as $cheque)<option value="{{ $cheque->id }}">{{ $cheque->party_name }} · {{ $cheque->cheque_number }} · {{ $cheque->cheque_date->format('d M Y') }} · Rs. {{ number_format($cheque->amount,2) }}</option>@endforeach</select><small class="text-slate-500">The full cheque is passed from the customer to this supplier.</small></label></template>
            @endif
        </div>
    </template>
    <div class="flex flex-wrap gap-3 text-xs font-bold">
        @if($changeTotal !== null)
        <span class="rounded-lg bg-blue-50 px-3 py-2 text-blue-700">Amount received: Rs. <span x-text="total().toFixed(2)"></span></span>
        @endif
        <span class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700">Paid: Rs. <span x-text="(total()-hold()-change()).toFixed(2)"></span></span>
        <span class="rounded-lg bg-amber-50 px-3 py-2 text-amber-700">Cheque Hold: Rs. <span x-text="hold().toFixed(2)"></span></span>
        @if($changeTotal !== null && $allowCashChange)
        <span class="rounded-lg bg-violet-50 px-3 py-2 text-violet-700">Change to return: Rs. <span x-text="change().toFixed(2)"></span></span>
        @endif
    </div>
    @if($changeTotal !== null)
    <p x-show="exceedsDue()" x-cloak class="text-xs font-semibold text-red-600">{{ $allowCashChange ? 'Non-cash payments exceed the bill total. Change can only be returned from cash received.' : 'Payment amounts cannot exceed the outstanding due.' }}</p>
    @endif
    @if($duePreview)
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-2" aria-live="polite">
        <div class="flex justify-between gap-3 font-bold"><span>Remaining Due</span><span id="due-payment-remaining" x-text="'Rs. ' + remainingDue().toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span></div>
        <div class="flex justify-between gap-3 text-sm"><span>Payment status after saving</span><span id="due-payment-status" class="font-semibold" x-text="exceedsDue() ? 'Check payment amounts' : paymentStatus()"></span></div>
    </div>
    @endif
</div>
