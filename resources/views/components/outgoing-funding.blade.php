@props(['default' => 'main_cashier', 'initial' => null, 'amountName' => 'amount', 'quantityName' => '', 'accountId' => null, 'date' => null, 'cashierClosing' => false])
@php
    $fundingOptions = app(\App\Services\OutgoingFundingService::class)->options($date, $cashierClosing);
    $fundingRows = old('funding', $initial ?? [['source' => $default, 'amount' => '']]);
@endphp
<div {{ $attributes->class(['space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4']) }} data-outgoing-funding data-amount-name="{{ $amountName }}" data-quantity-name="{{ $quantityName }}" data-default-source="{{ $default }}">
    <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Pay from</h3><button type="button" class="btn-secondary text-xs" data-add-funding>＋ Split across accounts</button></div>
    <p class="text-xs text-slate-600">Account = opening funds. Account Cashier = today’s cash only. Grey cashier choices are unavailable while closed.</p>
    <input type="hidden" name="account_id" @if($accountId) id="{{ $accountId }}" @endif value="{{ collect($fundingOptions)->firstWhere('value', $fundingRows[0]['source'] ?? $default)['account_id'] ?? '' }}" data-funding-account>
    <div data-funding-rows>
        @foreach($fundingRows as $index => $row)
        <div class="mb-2 grid gap-2 sm:grid-cols-[1fr_150px_auto]" data-funding-row>
            <label><span class="label">Source</span><select name="funding[{{ $index }}][source]" class="field" data-funding-source required><option value="">Select payment source</option>
                @foreach($fundingOptions as $option)<option value="{{ $option['value'] }}" data-account="{{ $option['account_id'] }}" data-cashier="{{ str_ends_with($option['value'], '_cashier') ? '1' : '0' }}" data-unavailable="{{ $option['disabled'] ? '1' : '0' }}" @disabled($option['disabled']) @selected(($row['source'] ?? $default) === $option['value']) @class(['bg-slate-200 text-slate-400' => $option['disabled']])>{{ $option['label'] }} · Rs. {{ number_format($option['balance'], 2) }}{{ $option['disabled'] ? ' · Cashier closed' : '' }}</option>@endforeach
            </select></label>
            <label><span class="label">Amount · LKR</span><input name="funding[{{ $index }}][amount]" type="number" step="0.01" min="0" max="999999999999.99" value="{{ $row['amount'] ?? '' }}" class="field" data-funding-amount required></label>
            <button type="button" class="self-end rounded-lg px-2 py-3 text-sm font-bold text-red-600" data-remove-funding aria-label="Remove payment source">✕</button>
        </div>
        @endforeach
    </div>
    <p class="text-sm font-bold" data-funding-total aria-live="polite"></p>
</div>
