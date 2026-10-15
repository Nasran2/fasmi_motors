@php($prefix = $prefix ?? '')
@php($plan = $plan ?? null)
<div class="grid gap-3 sm:grid-cols-2" x-data="{ recoveryType: @js(old($prefix.'deduction_type', $plan?->deduction_type ?? 'Monthly installment')) }">
    <label><span class="label">Salary recovery</span><select name="{{ $prefix }}deduction_type" class="field" x-model="recoveryType">
        <option value="Full deduction">Full balance at once</option>
        <option value="Monthly installment">Monthly installments</option>
        <option value="Partial deduction">Custom amount each paysheet</option>
    </select></label>
    <label><span class="label">Start salary month</span><input type="month" name="{{ $prefix }}start_month" value="{{ old($prefix.'start_month', $plan?->next_deduction_month?->format('Y-m') ?? now()->format('Y-m')) }}" class="field" required></label>
    <label class="sm:col-span-2" x-show="recoveryType !== 'Full deduction'"><span class="label">Suggested deduction per month (LKR)</span><input type="number" min="0.01" step="0.01" name="{{ $prefix }}installment_amount" value="{{ old($prefix.'installment_amount', $plan?->installment_amount) }}" :disabled="recoveryType === 'Full deduction'" :required="recoveryType !== 'Full deduction'" class="field"></label>
    <p class="sm:col-span-2 text-xs text-slate-500">You can change the amount for each repair when finalizing salary. Enter 0 there to skip a month; the remaining balance carries forward.</p>
</div>
