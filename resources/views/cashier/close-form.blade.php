        <form method="POST" action="{{ route('cashier.close', $session) }}" data-cashier-closing-form class="panel space-y-5 border-2 border-emerald-300" x-data="cashierClosing(@js($expectedBalances), @js(old('closing_balance', '')), @js(old('owner_withdrawal', 0)), @js($errors->has('balance_version') ? [] : old('balances', [])), @js($summary['cash_in_hand']), @js(!$errors->has('balance_version')), @js(old('funding', [['source' => 'main', 'amount' => old('owner_withdrawal', 0)]])), @js($summary['cashier_funds']), @js(['accounts' => $summary['allocation_accounts'], 'additions' => $errors->has('balance_version') ? [] : old('account_additions', [])]))" @outgoing-funding-change="updateWithdrawalFunding($event.detail)" @cashier-account-total-updated="applyAccountTotal($event.detail)">
            @csrf<x-submission-token /><input type="hidden" name="balance_version" value="{{ $balanceVersion }}"><input type="hidden" name="separate_cash_balance" value="1">
            <h2 class="text-xl font-black">Close Cashier</h2>
            <p class="text-sm text-slate-500">Enter the cash left in the cashier after owner withdrawal. Any remaining legacy washing funds are transferred to Main when closing. Bank funds stay separate from cashier cash.</p>
            @if($session->owner_withdrawal > 0)<p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Previously withdrawn: Rs. {{ number_format($session->owner_withdrawal, 2) }}. Enter only an additional withdrawal.</p>@endif
            <div class="grid gap-4 rounded-xl bg-slate-50 p-4 md:grid-cols-3"><label><span class="label">Owner withdrawal · LKR</span><input name="owner_withdrawal" type="number" min="0" step="0.01" required class="field" :value="withdrawal" @input="updateWithdrawal($event.target.value)"></label><label><span class="label">Owner name (optional)</span><input name="owner_name" value="{{ old('owner_name') }}" maxlength="100" class="field" placeholder="Owner"></label>
                <div aria-live="polite">
                    <p class="label">Available for today’s Main and Spare Parts additions</p>
                    <p class="mt-2 text-2xl font-black text-emerald-700">Rs. <span x-text="money(separationCashTotal)"></span></p>
                    <p class="mt-2 text-xs text-slate-600">After cashier withdrawals.@if($summary['cash_accounts']['washing']['closing'] > 0) Any legacy washing cash of Rs. {{ number_format($summary['cash_accounts']['washing']['closing'], 2) }} is already included in the Main Account total below.@endif</p>
                </div>
            </div>
            <x-outgoing-funding default="main" amount-name="owner_withdrawal" :date="$date" :cashier-closing="true" />
            <section class="rounded-2xl border-2 border-emerald-400 bg-emerald-50 p-5 sm:p-6" aria-live="polite" aria-labelledby="cashier-current-cash-title">
                <h3 id="cashier-current-cash-title" class="text-lg font-black text-emerald-950">{{ $isToday ? 'Today’s cash in hand now' : 'Cash in hand for this date' }}</h3>
                <p class="mt-2 text-4xl font-black text-emerald-800">Rs. <span x-text="money(currentCashInHand)">{{ number_format($summary['cash_in_hand'], 2) }}</span></p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => 'opening']) }}" class="rounded-xl border border-emerald-200 bg-white p-3 text-left hover:bg-blue-50"><span class="block text-xs font-bold text-slate-600">Opening cashier cash for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</span><span class="mt-1 block text-lg font-black">Rs. {{ number_format($summary['cash_opening'], 2) }}</span><span class="mt-2 block text-xs font-bold text-blue-700">View breakdown →</span></button>
                    <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => 'received']) }}" class="rounded-xl border border-emerald-200 bg-white p-3 text-left hover:bg-blue-50"><span class="block text-xs font-bold text-slate-600">+ Cash collected, including washing</span><span class="mt-1 block text-lg font-black">Rs. {{ number_format($summary['cash_received'], 2) }}</span><span class="mt-2 block text-xs font-bold text-blue-700">View transactions →</span></button>
                    <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => 'paid']) }}" class="rounded-xl border border-emerald-200 bg-white p-3 text-left hover:bg-blue-50"><span class="block text-xs font-bold text-slate-600">− Cash paid out, including this withdrawal</span><span class="mt-1 block text-lg font-black">Rs. <span x-text="money({{ $summary['cash_paid'] }} + cashWithdrawal)">{{ number_format($summary['cash_paid'], 2) }}</span></span><span class="mt-2 block text-xs font-bold text-blue-700">View saved transactions →</span><span x-show="cashWithdrawal > 0" x-cloak class="mt-1 block text-xs text-slate-500">This withdrawal appears in the breakdown after closing is saved.</span></button>
                </div>
                @if($summary['cash_other_in'] != 0)<p class="mt-3 text-sm text-emerald-950">Other cash in: Rs. {{ number_format($summary['cash_other_in'], 2) }}.</p>@endif
                @if($summary['drawer_adjustment'] != 0)<p class="mt-3 text-sm text-emerald-950">Recorded cash count adjustment: Rs. {{ number_format($summary['drawer_adjustment'], 2) }}.</p>@endif
                <p class="mt-3 text-sm text-emerald-950">Opening cashier cash for this date + cash collected + other cash in − cash paid out, with recorded cash count adjustments. Only cash that moved through the drawer affects this total.</p>
            </section>
            <p x-show="withdrawalTooHigh" x-cloak role="alert" class="text-sm font-bold text-red-600">Withdrawal exceeds a selected account balance or the available cashier cash.</p>
            <label class="block rounded-xl border border-blue-200 bg-blue-50 p-5"><span class="mb-2 block text-xs font-black uppercase tracking-wide text-blue-800">Physical cashier cash</span><span class="label">Closing balance in cashier · LKR</span><input name="closing_balance" type="number" min="0" max="999999999999.99" step="0.01" required class="field text-xl font-bold" x-model="closingBalance" @input="countEntered = $event.target.value.trim() !== ''" placeholder="Enter the cash you counted"><span class="mt-2 block text-xs text-slate-600">Cash left = available cashier cash − drawer cash sent to Main and Spare Parts. Spare Parts defaults to include any carried opening cashier cash so Closing balance starts at 0.00. Reduce Main or Spare Parts below to leave cash in the cashier, or enter the counted cash directly.</span></label>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach(['main' => 'Main Account', 'spare_parts' => 'Spare Parts Account'] as $type => $accountLabel)
                @php
                    $accountMeta = $summary['allocation_accounts'][$type];
                    $otherMovement = round($accountMeta['other_movements'] ?? 0, 2);
                    $nonCashAmount = round($accountMeta['non_cash_income'] ?? 0, 2);
                    $convertedCash = round($accountMeta['bank_converted_cash'] ?? 0, 2);
                    $conversionDeduction = round($accountMeta['bank_conversion_deduction'] ?? 0, 2);
                    $openingCashPart = round($accountMeta['opening_cash'] ?? 0, 2);
                    $expenseAmount = round($accountMeta['expenses'] ?? 0, 2);
                    $suggestedCash = round($accountMeta['suggested_addition'] ?? 0, 2);
                    $directCash = $suggestedCash;
                    $todayPartsCash = max(0.0, round($suggestedCash - $openingCashPart, 2));
                    $ssrNote = match (true) {
                        $openingCashPart > 0 && $nonCashAmount == 0 => '(Rs. ' . number_format($todayPartsCash, 2) . ' today’s cash + Rs. ' . number_format($openingCashPart, 2) . ' opening cashier cash)',
                        $openingCashPart > 0 && $nonCashAmount > 0 => '(Rs. ' . number_format($todayPartsCash, 2) . ' today’s cash + Rs. ' . number_format($openingCashPart, 2) . ' opening cashier cash + Rs. ' . number_format($nonCashAmount, 2) . ' bank transfer)',
                        $convertedCash > 0 && $nonCashAmount == 0 => '(Rs. ' . number_format($directCash, 2) . ' cash + Rs. ' . number_format($convertedCash, 2) . ' bank transfer from Spare Parts opening)',
                        $nonCashAmount > 0 && $convertedCash == 0 => '(Rs. ' . number_format($suggestedCash, 2) . ' cash + Rs. ' . number_format($nonCashAmount, 2) . ' bank transfer)',
                        $convertedCash > 0 && $nonCashAmount > 0 => '(Rs. ' . number_format($directCash, 2) . ' cash + Rs. ' . number_format($convertedCash, 2) . ' from bank transfer + Rs. ' . number_format($nonCashAmount, 2) . ' bank transfer)',
                        default => '',
                    };
                @endphp
                <label class="rounded-xl border p-4"><span class="label">Drawer cash to add to {{ $accountLabel }} · LKR</span><input name="account_additions[{{ $type }}]" type="number" step="0.01" min="0" max="999999999999.99" required class="field" x-model="additions.{{ $type }}"><input type="hidden" name="balances[{{ $type }}]" :value="closingAccountBalance('{{ $type }}').toFixed(2)">
                    <span class="mt-3 block text-sm text-slate-600">Opening balance: <b>Rs. <span x-text="money(allocationConfig.accounts['{{ $type }}'].opening)">{{ number_format($accountMeta['opening'], 2) }}</span></b></span>
                    <span class="mt-1 block text-sm text-red-600" x-show="cents(allocationConfig.accounts['{{ $type }}'].expenses || 0) > 0" @if($expenseAmount <= 0) x-cloak @endif>
                        − Expenses / debits: <b>Rs. <span x-text="money(allocationConfig.accounts['{{ $type }}'].expenses || 0)">{{ number_format($expenseAmount, 2) }}</span></b>
                        @if(($accountMeta['reversed_debits'] ?? 0) > 0)
                            <span class="block text-xs font-normal text-slate-500">(Rs. {{ number_format($accountMeta['reversed_debits'], 2) }} reversed)</span>
                        @endif
                    </span>
                    <span class="mt-1 block text-sm text-red-600" x-show="cents(allocationConfig.accounts['{{ $type }}'].bank_conversion_deduction || 0) > 0" @if($conversionDeduction <= 0) x-cloak @endif>
                        − Bank transfer to Main (opening): <b>Rs. <span x-text="money(allocationConfig.accounts['{{ $type }}'].bank_conversion_deduction || 0)">{{ number_format($conversionDeduction, 2) }}</span></b>
                    </span>
                    <span class="mt-1 block text-sm" :class="(allocationConfig.accounts['{{ $type }}'].other_movements || 0) >= 0 ? 'text-emerald-700' : 'text-red-600'" x-show="cents(allocationConfig.accounts['{{ $type }}'].other_movements || 0) !== 0" @if($otherMovement == 0) x-cloak @endif>
                        <span x-text="(allocationConfig.accounts['{{ $type }}'].other_movements || 0) >= 0 ? '+ Transfers / adjustments:' : '− Transfers / adjustments:'">{{ $otherMovement != 0 ? ($otherMovement > 0 ? '+ Transfers / adjustments:' : '− Transfers / adjustments:') : '' }}</span>
                        <b x-show="cents(allocationConfig.accounts['{{ $type }}'].other_movements || 0) !== 0"><span x-text="'Rs. ' + money(Math.abs(allocationConfig.accounts['{{ $type }}'].other_movements || 0))">{{ $otherMovement != 0 ? 'Rs. ' . number_format(abs($otherMovement), 2) : '' }}</span></b>
                    </span>
                    <span class="mt-1 block text-sm text-slate-600">
                        + Added today: <b>Rs. <span x-text="money(totalAddition('{{ $type }}'))">{{ number_format($suggestedCash + $nonCashAmount + $convertedCash, 2) }}</span></b>
                        <span class="mt-0.5 block text-xs text-slate-500" x-show="hasAdditionNote('{{ $type }}')" x-text="additionNote('{{ $type }}')" @if($ssrNote === '') x-cloak @endif>{{ $ssrNote }}</span>
                    </span>
                    <span class="mt-3 block rounded-lg bg-emerald-50 p-3 text-base font-black text-emerald-800" aria-live="polite"><span class="flex items-start justify-between gap-2"><span>Account balance after addition: Rs. <span x-text="money(closingAccountBalance('{{ $type }}'))">{{ number_format($accountMeta['base'] + $suggestedCash, 2) }}</span></span>@if(auth()->user()->canAccess('account.daily_closing'))<button type="button" class="rounded-lg border border-emerald-300 bg-white px-3 py-1 text-sm font-bold text-emerald-900 underline" data-account-total-url="{{ route('cashier.account-total-popup', $session) }}" @click.prevent.stop="editAccountTotal('{{ $type }}', $event.currentTarget)">Edit total</button>@endif</span><span x-show="savedAccountCorrections.{{ $type }}" x-cloak class="mt-2 block text-xs font-semibold">Correction saved with its reason. Carries forward.</span></span>
                    <span class="mt-2 block text-xs text-slate-500">Enter only the cash you are taking out of the drawer for this account now. Posted account movements are included in the account total.</span>
                </label>
                @endforeach
                <label class="rounded-xl border border-blue-200 bg-blue-50 p-4"><span class="label">Bank Account closing balance · LKR</span><input name="balances[bank]" type="number" step="0.01" min="-999999999999.99" max="999999999999.99" required class="field" x-model="balances.bank"><span class="mt-2 block text-xs text-slate-500">Automatic bank balance: Rs. <span x-text="money(expectedBalance('bank'))"></span>. Change if needed. Excluded from cash in the drawer.</span></label>
            </div>
            <p class="text-sm font-bold" aria-live="polite">Total cash to take from the drawer: Rs. <span x-text="money(allocatedCashTotal)"></span><span class="mt-1 block text-xs font-normal text-slate-500">Only these daily additions are separated into Main and Spare Parts. The amount left stays in the cashier and becomes the next opening cashier balance. Bank is excluded.</span></p>
            <p x-show="additionsTooHigh" x-cloak role="alert" class="text-sm font-bold text-red-600">Today’s account additions exceed the available cashier cash. Reduce the daily additions.</p>
            <div x-show="hasCount" x-cloak class="rounded-xl bg-emerald-50 p-4 font-black" aria-live="polite">Counted closing balance: Rs. <span x-text="money(countedCashTotal)"></span><span x-show="hasDifference" class="ml-3 text-sm text-amber-800">Difference from expected cash left: Rs. <span x-text="money(cashDifference)"></span></span></div>
            <label class="block"><span class="label">Reason for changed closing balances <span x-show="hasDifference" class="text-red-600">(required)</span></span><textarea name="difference_reason" maxlength="1000" class="field" :required="hasDifference" rows="2">{{ old('difference_reason') }}</textarea></label>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-5">
                <p class="text-xs text-slate-500">Closing blocks new job cards, quick jobs and parts sales.</p><button type="submit" :disabled="withdrawalTooHigh || additionsTooHigh || (hasCount && !cashMatchesAccounts)" class="rounded-xl bg-red-600 px-7 py-3 font-black text-white disabled:opacity-50">Confirm & Close Cashier</button>
            </div>
        </form>