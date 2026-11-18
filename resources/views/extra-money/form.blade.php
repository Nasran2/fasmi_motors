<x-layouts::app :title="$extraIncome->exists ? 'Edit Extra Money' : 'Add Extra Money'">
@php
    $selectedDestination = old('destination_account', $extraIncome->destination_account ?: 'main');
    $selectedMethod = old('payment_method', $extraIncome->payment_method ?: 'Cash');
    $selectedDate = old('income_date', $extraIncome->income_date?->toDateString() ?: today()->toDateString());
    $existingCheque = $extraIncome->cheque;
    $chequeDate = old('cheque_date', $existingCheque?->cheque_date?->toDateString() ?: $selectedDate);
    $chequeNumber = old('cheque_number', $existingCheque?->cheque_number);
    $bankName = old('bank_name', $existingCheque?->bank_name);
    $branch = old('branch', $existingCheque?->branch);
    $chequeStatus = old('cheque_status', in_array($existingCheque?->status, ['Pending', 'Deposited', 'Cleared'], true) ? $existingCheque->status : 'Pending');
    $realizedDate = old('realized_date', $existingCheque?->realized_date?->toDateString() ?: today()->toDateString());
    $isChequeSelected = $selectedMethod === 'Cheque';
@endphp
<div class="mx-auto max-w-4xl space-y-5">
    <header>
        <p class="text-xs font-bold uppercase text-red-600">Expenses &amp; Drawings</p>
        <h1 class="page-title">{{ $extraIncome->exists ? 'Edit Extra Money' : 'Add Extra Money' }}</h1>
        <p class="page-subtitle">Record extra money received as <strong>Income</strong> into Main Account, Spare Parts Account, Spare Parts Cashier (only when open), or Bank Account.</p>
    </header>

    @if(session('success'))
        <p class="rounded-xl bg-emerald-50 p-4 text-emerald-800" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <div class="rounded-xl bg-red-50 p-4 text-red-700" role="alert">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $extraIncome->exists ? route('extra-money.update', $extraIncome) : route('extra-money.store') }}" class="panel space-y-5 p-5" id="extra-money-form" data-options-url="{{ route('extra-money.options') }}" data-today="{{ today()->toDateString() }}">
        @csrf
        <x-submission-token />
        @if($extraIncome->exists)
            @method('PUT')
            <input type="hidden" name="record_version" value="{{ $extraIncome->version() }}">
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="income_date" class="label">Date <span aria-hidden="true">*</span></label>
                <input id="income_date" type="date" name="income_date" required max="{{ today()->toDateString() }}" value="{{ $selectedDate }}" class="field">
            </div>

            <div>
                <label for="amount" class="label">Amount · LKR <span aria-hidden="true">*</span></label>
                <input id="amount" type="number" name="amount" required min="0.01" max="999999999999.99" step="0.01" inputmode="decimal" value="{{ old('amount', $extraIncome->amount) }}" class="field">
            </div>

            <div>
                <label for="title" class="label">Description / Reason <span aria-hidden="true">*</span></label>
                <input id="title" type="text" name="title" required maxlength="150" placeholder="e.g., Scrap metal sale, Extra service fee, Commission" value="{{ old('title', $extraIncome->title) }}" class="field">
            </div>

            <div>
                <label for="source_name" class="label">Received From (Optional)</label>
                <input id="source_name" type="text" name="source_name" maxlength="150" placeholder="Person, customer, or source" value="{{ old('source_name', $extraIncome->source_name) }}" class="field">
            </div>

            <div class="sm:col-span-2">
                <label for="destination_account" class="label">Add to Account <span aria-hidden="true">*</span></label>
                <select id="destination_account" name="destination_account" required aria-describedby="cashier-status-note" class="field">
                    <option value="">Select account</option>
                    @foreach($optionState['options'] as $option)
                        <option
                            value="{{ $option['value'] }}"
                            data-cashier="{{ $option['value'] === 'spare_parts_cashier' ? '1' : '0' }}"
                            data-unavailable="{{ $option['disabled'] ? '1' : '0' }}"
                            @disabled($option['disabled'])
                            @selected($selectedDestination === $option['value'] && ! $option['disabled'])
                        >{{ $option['label'] }} · Rs. {{ number_format($option['balance'], 2) }}{{ $option['disabled'] ? ' · '.$option['disabled_reason'] : '' }}</option>
                    @endforeach
                </select>
                <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                    <p id="cashier-status-note" class="text-xs text-slate-600" role="status" aria-live="polite">{{ $optionState['message'] }}</p>
                    <button type="button" id="refresh-extra-money-options" class="text-xs font-bold text-blue-700 hover:underline">Refresh balances</button>
                </div>
            </div>

            <div>
                <label for="payment_method" class="label">Payment Method <span aria-hidden="true">*</span></label>
                <select id="payment_method" name="payment_method" required aria-describedby="method-hint" class="field">
                    @foreach($paymentMethods as $method)
                        <option value="{{ $method }}" @selected($selectedMethod === $method)>{{ $method }}</option>
                    @endforeach
                </select>
                <p id="method-hint" class="mt-1 text-xs text-slate-500">Main Account, Spare Parts Account, and Spare Parts Cashier receive Cash. Select Bank Account for Bank Transfer, Card, QR, or Cheque.</p>
            </div>

            <div>
                <label for="reference_number" class="label">Reference Number (Optional)</label>
                <input id="reference_number" type="text" name="reference_number" maxlength="100" autocorrect="off" autocapitalize="none" spellcheck="false" value="{{ old('reference_number', $extraIncome->reference_number) }}" class="field">
            </div>
        </div>

        <fieldset id="cheque-details-section" class="space-y-4 rounded-xl border border-amber-200 bg-amber-50/60 p-4" @if(!$isChequeSelected) hidden disabled @endif>
            <legend class="px-1 text-sm font-black text-amber-900">Cheque Details &amp; Conditions</legend>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="cheque_date" class="label">Cheque Date <span aria-hidden="true">*</span></label>
                    <input id="cheque_date" type="date" name="cheque_date" value="{{ $chequeDate }}" class="field" @if($isChequeSelected) required @endif>
                </div>

                <div>
                    <label for="cheque_number" class="label">Cheque Number <span aria-hidden="true">*</span></label>
                    <input id="cheque_number" type="text" name="cheque_number" maxlength="80" autocorrect="off" autocapitalize="none" spellcheck="false" placeholder="e.g., 458921" value="{{ $chequeNumber }}" class="field" @if($isChequeSelected) required @endif>
                </div>

                <div>
                    <label for="bank_name" class="label">Bank Name <span aria-hidden="true">*</span></label>
                    <input id="bank_name" type="text" name="bank_name" maxlength="120" placeholder="e.g., Commercial Bank" value="{{ $bankName }}" class="field" @if($isChequeSelected) required @endif>
                </div>

                <div>
                    <label for="branch" class="label">Branch (Optional)</label>
                    <input id="branch" type="text" name="branch" maxlength="120" placeholder="e.g., Colombo Fort" value="{{ $branch }}" class="field">
                </div>

                <div>
                    <label for="cheque_status" class="label">Initial Status <span aria-hidden="true">*</span></label>
                    <select id="cheque_status" name="cheque_status" class="field" aria-describedby="cheque-status-hint" @if($isChequeSelected) required @endif>
                        @foreach($chequeStatuses as $value => $label)
                            <option value="{{ $value }}" @selected($chequeStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p id="cheque-status-hint" class="mt-1 text-xs text-amber-900/80" role="status" aria-live="polite">Default is Pending / Hold until the cheque passes.</p>
                </div>

                <div id="realized-date-wrap" @if(!$isChequeSelected || $chequeStatus !== 'Cleared') hidden @endif>
                    <label for="realized_date" class="label">Passed Date <span aria-hidden="true">*</span></label>
                    <input id="realized_date" type="date" name="realized_date" value="{{ $realizedDate }}" min="{{ $chequeDate }}" max="{{ today()->toDateString() }}" class="field" @if($isChequeSelected && $chequeStatus === 'Cleared') required @endif>
                </div>
            </div>

            <div class="rounded-lg border border-amber-200/80 bg-white/80 p-3 text-xs text-slate-700 space-y-1">
                <p class="font-bold text-amber-900">Cheque Conditions:</p>
                <ul class="list-disc pl-4 space-y-1">
                    <li>A <strong>Received</strong> cheque entry is automatically recorded in <strong>Cheque Management</strong> linked to this Extra Money record.</li>
                    <li><strong>Pending / Hold</strong> and <strong>Deposited</strong> cheques remain on <strong>Hold</strong> — Bank Account balance and Profit &amp; Loss income are credited only when the cheque is marked <strong>Passed</strong>.</li>
                    <li>A cheque can only be marked <strong>Passed</strong> on or after its <strong>Cheque Date</strong> (post-dated cheques must stay Pending / Deposited until that date).</li>
                    <li>If a passed cheque is later marked <strong>Returned</strong> or <strong>Cancelled</strong> in Cheque Management, its income ledger credit is automatically reversed.</li>
                </ul>
            </div>
        </fieldset>

        <div>
            <label for="note" class="label">Notes (Optional)</label>
            <textarea id="note" name="note" maxlength="1000" rows="3" class="field">{{ old('note', $extraIncome->note) }}</textarea>
        </div>

        <p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
            <strong>Saved as Income (not Expense):</strong> This amount credits the selected account as <strong>Income</strong> and is included in Profit &amp; Loss revenue. Selecting <strong>Spare Parts Cashier</strong> adds the cash directly into the open cashier drawer.
        </p>

        @if($extraIncome->exists)
            <div>
                <label for="reason" class="label">Reason for Editing <span aria-hidden="true">*</span></label>
                <textarea id="reason" name="reason" required minlength="5" maxlength="500" rows="2" class="field">{{ old('reason') }}</textarea>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <button type="submit" name="action" value="save" class="btn-primary">{{ $extraIncome->exists ? 'Save Changes' : 'Save Extra Money (Income)' }}</button>
            @if(!$extraIncome->exists)
                <button type="submit" name="action" value="add_another" class="btn-secondary">Save &amp; Add Another</button>
            @endif
            <a href="{{ $extraIncome->exists ? route('extra-money.show', $extraIncome) : route('extra-money.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(() => {
    const form = document.getElementById('extra-money-form');
    if (!form || form.dataset.ready === '1') return;
    form.dataset.ready = '1';

    const dateInput = document.getElementById('income_date');
    const destinationSelect = document.getElementById('destination_account');
    const methodSelect = document.getElementById('payment_method');
    const statusNote = document.getElementById('cashier-status-note');
    const refreshBtn = document.getElementById('refresh-extra-money-options');
    const chequeSection = document.getElementById('cheque-details-section');
    const chequeDateInput = document.getElementById('cheque_date');
    const chequeNumberInput = document.getElementById('cheque_number');
    const bankNameInput = document.getElementById('bank_name');
    const chequeStatusSelect = document.getElementById('cheque_status');
    const chequeStatusHint = document.getElementById('cheque-status-hint');
    const realizedDateWrap = document.getElementById('realized-date-wrap');
    const realizedDateInput = document.getElementById('realized_date');
    const optionsUrl = form.dataset.optionsUrl;
    const todayStr = form.dataset.today;

    let fetchVersion = 0;
    let activeAbort = null;

    const money = (amount) => Number(amount || 0).toLocaleString('en-LK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const syncChequeSection = () => {
        const isCheque = methodSelect.value === 'Cheque' && destinationSelect.value === 'bank';
        chequeSection.hidden = !isCheque;
        chequeSection.disabled = !isCheque;
        chequeDateInput.required = isCheque;
        chequeNumberInput.required = isCheque;
        bankNameInput.required = isCheque;
        chequeStatusSelect.required = isCheque;

        if (!isCheque) {
            realizedDateInput.required = false;
            return;
        }

        if (!chequeDateInput.value) {
            chequeDateInput.value = dateInput.value || todayStr;
        }

        const isFutureCheque = Boolean(chequeDateInput.value && chequeDateInput.value > todayStr);
        const clearedOption = [...chequeStatusSelect.options].find((o) => o.value === 'Cleared');
        if (clearedOption) {
            clearedOption.disabled = isFutureCheque;
        }
        if (isFutureCheque && chequeStatusSelect.value === 'Cleared') {
            chequeStatusSelect.value = 'Pending';
        }

        const isCleared = chequeStatusSelect.value === 'Cleared';
        realizedDateWrap.hidden = !isCleared;
        realizedDateInput.required = isCleared;
        realizedDateInput.min = chequeDateInput.value || '';
        realizedDateInput.max = todayStr;
        if (isCleared && (!realizedDateInput.value || realizedDateInput.value < chequeDateInput.value)) {
            realizedDateInput.value = todayStr >= chequeDateInput.value ? todayStr : chequeDateInput.value;
        }

        if (isFutureCheque) {
            chequeStatusHint.textContent = 'Post-dated cheque: can only be marked Passed on or after ' + chequeDateInput.value + '.';
        } else if (isCleared) {
            chequeStatusHint.textContent = 'Will be marked Passed and credited to Bank Account as Income immediately.';
        } else if (chequeStatusSelect.value === 'Deposited') {
            chequeStatusHint.textContent = 'Saved as Deposited (on Hold) until marked Passed in Cheque Management.';
        } else {
            chequeStatusHint.textContent = 'Default is Pending / Hold until the cheque passes in Cheque Management.';
        }
    };

    const syncPaymentMethod = () => {
        const isBank = destinationSelect.value === 'bank';
        for (const option of methodSelect.options) {
            option.disabled = !isBank && option.value !== 'Cash';
        }
        if (!isBank && methodSelect.value !== 'Cash') {
            methodSelect.value = 'Cash';
        } else if (isBank && methodSelect.value === 'Cash' && methodSelect.dataset.userPicked !== '1') {
            methodSelect.value = 'Bank Transfer';
        }
        syncChequeSection();
    };

    const loadOptions = async () => {
        const date = (dateInput.value || '').slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return;
        }
        const version = ++fetchVersion;
        activeAbort?.abort();
        activeAbort = new AbortController();
        statusNote.textContent = 'Checking cashier status and balances for the selected date…';

        try {
            const url = new URL(optionsUrl, window.location.href);
            url.searchParams.set('date', date);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: activeAbort.signal,
            });
            const data = await response.json();
            if (version !== fetchVersion) return;
            if (!response.ok || !Array.isArray(data.options)) {
                throw new Error('Could not refresh account options.');
            }

            const previousValue = destinationSelect.value;
            const placeholder = new Option('Select account', '');
            const optionNodes = data.options.map((item) => {
                const suffix = item.disabled && item.disabled_reason ? ` · ${item.disabled_reason}` : '';
                const opt = new Option(`${item.label} · Rs. ${money(item.balance)}${suffix}`, item.value);
                opt.disabled = Boolean(item.disabled);
                opt.dataset.cashier = item.value === 'spare_parts_cashier' ? '1' : '0';
                opt.dataset.unavailable = item.disabled ? '1' : '0';
                return opt;
            });

            destinationSelect.replaceChildren(placeholder, ...optionNodes);

            const matching = optionNodes.find((opt) => opt.value === previousValue && !opt.disabled);
            destinationSelect.value = matching ? previousValue : '';
            statusNote.textContent = data.message || '';
            syncPaymentMethod();
        } catch (error) {
            if (version !== fetchVersion || error.name === 'AbortError') return;
            statusNote.textContent = 'Could not load latest balances. Click Refresh balances to try again.';
        }
    };

    destinationSelect.addEventListener('change', () => {
        methodSelect.dataset.userPicked = '0';
        syncPaymentMethod();
    });
    methodSelect.addEventListener('change', () => {
        methodSelect.dataset.userPicked = '1';
        syncChequeSection();
    });
    chequeDateInput.addEventListener('change', syncChequeSection);
    chequeStatusSelect.addEventListener('change', syncChequeSection);
    dateInput.addEventListener('change', loadOptions);
    refreshBtn.addEventListener('click', loadOptions);

    syncPaymentMethod();
})();
</script>
</x-layouts::app>
