<section class="space-y-3 rounded-xl border border-slate-200 p-3 sm:col-span-2 sm:p-4" aria-labelledby="job-main-account-heading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h3 id="job-main-account-heading" class="text-sm font-bold text-slate-900">Main Account income</h3>
            <p class="mt-1 text-xs text-slate-600">With automatic payment allocation, payments for these items are credited to Main Account as income.</p>
            <p class="mt-1 text-xs text-slate-600">
                @if($isLocked)
                    Use “Request Changes / Edit Job” to add or edit charges.
                @else
                    Uncheck “Show on bill” to include the amount under Service on the customer’s bill.
                @endif
            </p>
        </div>
        @unless($isLocked)
            <button type="button" class="btn-secondary shrink-0" @click="mainAccountCharges.push({ key: nextChargeKey++, name: '', amount: '', show_on_bill: true }); $nextTick(() => $el.closest('section').querySelectorAll('[data-charge-name]')[mainAccountCharges.length - 1]?.focus())" :disabled="mainAccountCharges.length >= 25">+ Add income</button>
        @endunless
    </div>

    @unless($isLocked)
        <input type="hidden" name="main_account_charges_present" value="1">
    @endunless

    @foreach($errors->get('main_account_charges*') as $messages)
        @foreach($messages as $message)
            <p class="text-xs font-semibold text-red-700">{{ $message }}</p>
        @endforeach
    @endforeach

    <template x-for="(charge, index) in mainAccountCharges" :key="charge.key">
        <div class="grid items-end gap-2 rounded-lg bg-slate-50 p-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <label>
                <span class="label">Name</span>
                <input type="text" class="field" data-charge-name :name="`main_account_charges[${index}][name]`" x-model="charge.name" maxlength="80" required @disabled($isLocked)>
            </label>
            <label>
                <span class="label">Amount (LKR)</span>
                <input type="number" class="field" :name="`main_account_charges[${index}][amount]`" x-model.number="charge.amount" min="0.01" max="999999999.99" step="0.01" required @disabled($isLocked)>
            </label>
            <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2">
                <label class="flex min-h-10 items-center gap-2 text-sm font-semibold text-slate-700">
                    @unless($isLocked)
                        <input type="hidden" :name="`main_account_charges[${index}][show_on_bill]`" value="0">
                    @endunless
                    <input type="checkbox" class="size-4 accent-red-600" :name="`main_account_charges[${index}][show_on_bill]`" value="1" x-model="charge.show_on_bill" @disabled($isLocked)>
                    <span>Show on bill</span>
                </label>
                @unless($isLocked)
                    <button type="button" class="min-h-10 rounded-lg px-3 text-sm font-bold text-red-700 hover:bg-red-50" @click="mainAccountCharges.splice(index, 1)">Remove</button>
                @endunless
            </div>
        </div>
    </template>
</section>
