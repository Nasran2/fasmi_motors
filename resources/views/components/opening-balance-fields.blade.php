<div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4" x-data="{ enabled: @js((float) old('opening_balance', 0) > 0) }">
    <label class="flex items-center gap-2 font-bold text-slate-800"><input type="checkbox" x-model="enabled" class="size-4 accent-red-600">Add opening balance (previous / old balance)</label>
    <p class="mt-2 text-xs text-slate-500">Enter only a balance that has not already been recorded. Payments against this balance go to the Main Account.</p>
    <div x-show="enabled" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
        <label><span class="label">Opening balance amount (LKR)</span><input type="number" name="opening_balance" step="0.01" min="0.01" max="999999999999.99" value="{{ old('opening_balance') }}" :disabled="!enabled" :required="enabled" class="field" placeholder="0.00"></label>
        <label><span class="label">Opening balance date</span><input type="date" name="opening_balance_date" value="{{ old('opening_balance_date', today()->toDateString()) }}" max="{{ today()->toDateString() }}" :disabled="!enabled" :required="enabled" class="field"></label>
    </div>
</div>
