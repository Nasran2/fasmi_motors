@php
    $fieldValue = fn ($name, $default = null) => $retainInput ? old($name, $default) : $default;
@endphp
        <div class="mt-4 grid gap-4 md:grid-cols-2 {{ $gridColumns }}">
            <label>
                <span class="label">Staff *</span>
                <select name="technician_id" class="field" required>
                    <option value="">Select staff</option>
                    @foreach($technicians as $technician)
                        <option value="{{ $technician->id }}" @selected($fieldValue('technician_id', $formAdvance?->technician_id) == $technician->id)>{{ $technician->name }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="label">Payment Date *</span><input type="date" name="payment_date" value="{{ $fieldValue('payment_date', $formAdvance?->payment_date?->toDateString() ?? today()->toDateString()) }}" class="field" required></label>
            <label><span class="label">Amount *</span><input type="number" step=".01" min=".01" name="amount" value="{{ $fieldValue('amount', $formAdvance?->amount) }}" class="field" required></label>
            <label>
                <span class="label">Paid From Account *</span>
                <select name="account_id" class="field" required>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected($fieldValue('account_id', $formAdvance?->account_id ?? $accounts->firstWhere('code', \App\Models\Account::systemCode('main'))?->id) == $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="label">Payment Method *</span>
                <select name="payment_method" class="field" required>
                    @foreach(collect(['Cash','Bank Transfer','Cheque','Card'])->push($formAdvance?->payment_method)->filter()->unique() as $method)
                        <option value="{{ $method }}" @selected($fieldValue('payment_method', $formAdvance?->payment_method ?? 'Cash') === $method)>{{ $method }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="label">Deduct From Salary Month *</span><input type="month" name="deduct_month" value="{{ $fieldValue('deduct_month', $formAdvance?->deduct_month?->format('Y-m') ?? now()->format('Y-m')) }}" class="field" required></label>
            <label class="md:col-span-2"><span class="label">Note</span><input name="note" value="{{ $fieldValue('note', $formAdvance?->note) }}" maxlength="1000" class="field"></label>
        </div>
