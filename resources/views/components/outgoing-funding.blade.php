@props(['default' => 'main', 'initial' => null, 'amountName' => 'amount', 'quantityName' => '', 'accountId' => null, 'date' => null, 'cashierClosing' => false, 'dateName' => '', 'optionsUrl' => '', 'options' => null])
@php
    $date ??= today()->toDateString();
    $optionsUrl = $optionsUrl ?: route('funding.options');
    $fundingService = app(\App\Services\OutgoingFundingService::class);
    $default = $fundingService->canonicalSource($default);
    $fundingOptions = $options ?? $fundingService->options($date, $cashierClosing);
    $fundingRows = old('funding', $initial ?? [['source' => $default, 'amount' => '']]);
    $fundingRows = array_map(fn ($row) => array_replace($row, [
        'source' => $fundingService->canonicalSource($row['source'] ?? $default),
    ]), $fundingRows);
@endphp
<div {{ $attributes->class(['space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4']) }} data-outgoing-funding data-amount-name="{{ $amountName }}" data-quantity-name="{{ $quantityName }}" data-default-source="{{ $default }}" data-funding-date="{{ $date }}" data-date-name="{{ $dateName }}" data-options-url="{{ $optionsUrl }}">
    <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Pay from</h3><button type="button" class="btn-secondary text-xs" data-add-funding>＋ Split across accounts</button></div>
    <p class="text-xs text-slate-600">Main Account payments always go from the opening balance outside the cashier. For Spare Parts Account, you can select whether payment goes from the opening balance or today’s cashier cash. Grey cashier choices are unavailable when that date’s cashier is closed.</p>
    <div class="flex flex-wrap items-center gap-2"><p class="text-xs text-slate-600" data-funding-date-status role="status">Cashier availability and balances follow the payment date.</p><button type="button" class="text-xs font-bold text-blue-700" data-refresh-funding>Refresh balances</button></div>
    <input type="hidden" name="account_id" @if($accountId) id="{{ $accountId }}" @endif value="{{ collect($fundingOptions)->firstWhere('value', $fundingRows[0]['source'] ?? $default)['account_id'] ?? '' }}" data-funding-account>
    <div data-funding-rows>
        @foreach($fundingRows as $index => $row)
        <div class="mb-2 grid gap-2 sm:grid-cols-[1fr_150px_auto]" data-funding-row>
            <label><span class="label">Source</span><select name="funding[{{ $index }}][source]" class="field" data-funding-source required><option value="">Select payment source</option>
                @foreach($fundingOptions as $option)<option value="{{ $option['value'] }}" data-account="{{ $option['account_id'] }}" data-cashier="{{ str_ends_with($option['value'], '_cashier') ? '1' : '0' }}" data-unavailable="{{ $option['disabled'] ? '1' : '0' }}" @disabled($option['disabled']) @selected(($row['source'] ?? $default) === $option['value']) @class(['bg-slate-200 text-slate-400' => $option['disabled']])>{{ $option['label'] }} · Rs. {{ number_format($option['balance'], 2) }}{{ $option['disabled'] ? ' · '.($option['disabled_reason'] ?? 'Cashier closed') : '' }}</option>@endforeach
            </select></label>
            <label><span class="label">Amount · LKR</span><input name="funding[{{ $index }}][amount]" type="number" step="0.01" min="0" max="999999999999.99" value="{{ $row['amount'] ?? '' }}" class="field" data-funding-amount required></label>
            <button type="button" class="self-end rounded-lg px-2 py-3 text-sm font-bold text-red-600" data-remove-funding aria-label="Remove payment source">✕</button>
        </div>
        @endforeach
    </div>
    <p class="text-sm font-bold" data-funding-total aria-live="polite"></p>
</div>
