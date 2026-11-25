<x-layouts::app title="Payment Method Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Payment Method Settings</h1>
        <p class="page-subtitle">Configure allowed store payment modes and map them to ledgers</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed space-y-1">
      <p>ℹ️ <strong>Payment Routing:</strong> Map payment types to corresponding accounts (e.g. Cards and QR transactions going to Bank Account while Cash transactions route to Cash Book).</p>
      <p>💳 <strong>Payment Charges:</strong> Configure the bank/card fee percentage per method (Card defaults to <strong>2.5%</strong>). When a receipt is recorded, the charge is saved as an <strong>Expense</strong> deducted from the selected <strong>Charge Account (Expense)</strong> source (default <strong>Bank Account</strong>; opening-balance and Bank sources stay outside the cashier drawer, while <strong>Spare Parts Account Cashier — today’s cash</strong> deducts from the open cashier drawer) while preserving the existing Bank-to-Main transfer concept.</p>
    </div>

    <x-flash />
    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('system-settings.save') }}" class="space-y-6">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="payment">

      {{-- Payment Options Panel --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-0 overflow-hidden shadow-sm">
        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/50">
          <h2 class="text-sm font-black text-slate-800">Allowed Payment Methods</h2>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm whitespace-nowrap">
            <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
              <tr>
                <th class="px-4 py-3 font-bold">Payment Method</th>
                <th class="px-4 py-3 font-bold">Mapping Ledger Account</th>
                <th class="px-4 py-3 font-bold">Charge (%)</th>
                <th class="px-4 py-3 font-bold">Charge Account (Expense)</th>
                <th class="px-4 py-3 font-bold">Requires Reference</th>
                <th class="px-4 py-3 font-bold">Show in POS</th>
                <th class="px-4 py-3 font-bold">Enabled</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @php
                $methods = [
                  'Cash' => ['key' => 'pm_cash', 'account' => 'Cash Book', 'ref' => false, 'supports_charge' => true, 'default_charge' => '0', 'default_charge_account' => 'bank'],
                  'Card' => ['key' => 'pm_card', 'account' => 'Bank Account', 'ref' => true, 'supports_charge' => true, 'default_charge' => '2.5', 'default_charge_account' => 'bank'],
                  'Bank Transfer' => ['key' => 'pm_bank', 'account' => 'Bank Account', 'ref' => true, 'supports_charge' => true, 'default_charge' => '0', 'default_charge_account' => 'bank'],
                  'Cheque' => ['key' => 'pm_cheque', 'account' => 'Main Account', 'ref' => true, 'supports_charge' => true, 'default_charge' => '0', 'default_charge_account' => 'bank'],
                  'QR Code' => ['key' => 'pm_qr', 'account' => 'Bank Account', 'ref' => true, 'supports_charge' => true, 'default_charge' => '0', 'default_charge_account' => 'bank'],
                  'Credit / Due' => ['key' => 'pm_credit', 'account' => 'Main Account', 'ref' => false, 'supports_charge' => false, 'default_charge' => '0', 'default_charge_account' => 'bank'],
                ];
              @endphp
              @foreach($methods as $name => $meta)
              @php
                $chargeValue = old($meta['key'].'_charge_percent', $settings[$meta['key'].'_charge_percent'] ?? $meta['default_charge']);
                $chargeAccount = old($meta['key'].'_charge_account', $settings[$meta['key'].'_charge_account'] ?? $meta['default_charge_account']);
              @endphp
              <tr class="hover:bg-slate-50/50 transition">
                <td class="px-4 py-3 font-bold text-slate-900">{{ $name }}</td>
                <td class="px-4 py-3">
                  <select name="{{ $meta['key'] }}_account" class="!py-1 !px-2 text-xs">
                    <option value="Cash Book" @selected(($settings[$meta['key'].'_account'] ?? $meta['account']) === 'Cash Book')>Cash Book</option>
                    <option value="Bank Account" @selected(($settings[$meta['key'].'_account'] ?? '') === 'Bank Account')>Bank Account</option>
                    <option value="Main Account" @selected(($settings[$meta['key'].'_account'] ?? '') === 'Main Account')>Main Account</option>
                    <option value="Spare Parts Account" @selected(($settings[$meta['key'].'_account'] ?? '') === 'Spare Parts Account')>Spare Parts Account</option>
                  </select>
                </td>
                <td class="px-4 py-3">
                  @if($meta['supports_charge'])
                  <div class="inline-flex items-center gap-1">
                    <input type="number" name="{{ $meta['key'] }}_charge_percent" min="0" max="100" step="0.01" value="{{ $chargeValue === '' ? $meta['default_charge'] : $chargeValue }}" class="w-24 rounded-lg border border-slate-300 bg-white !py-1 !px-2 text-xs font-semibold text-slate-800" required>
                    <span class="text-xs font-bold text-slate-500">%</span>
                  </div>
                  @else
                  <span class="text-xs text-slate-400">—</span>
                  @endif
                </td>
                <td class="px-4 py-3">
                  @if($meta['supports_charge'])
                  <select name="{{ $meta['key'] }}_charge_account" class="!py-1 !px-2 text-xs">
                    @foreach(\App\Support\PaymentMethodCharges::CHARGE_ACCOUNTS as $accountCode => $accountLabel)
                    <option value="{{ $accountCode }}" @selected($chargeAccount === $accountCode)>{{ $accountLabel }}</option>
                    @endforeach
                  </select>
                  @else
                  <span class="text-xs text-slate-400">—</span>
                  @endif
                </td>
                <td class="px-4 py-3">
                  <input type="hidden" name="{{ $meta['key'] }}_requires_ref" value="0">
                  <input type="checkbox" name="{{ $meta['key'] }}_requires_ref" value="1" @checked(($settings[$meta['key'].'_requires_ref'] ?? ($meta['ref'] ? 1 : 0)) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                </td>
                <td class="px-4 py-3">
                  <input type="hidden" name="{{ $meta['key'] }}_pos" value="0">
                  <input type="checkbox" name="{{ $meta['key'] }}_pos" value="1" @checked(($settings[$meta['key'].'_pos'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                </td>
                <td class="px-4 py-3">
                  <input type="hidden" name="{{ $meta['key'] }}_active" value="0">
                  <input type="checkbox" name="{{ $meta['key'] }}_active" value="1" @checked(($settings[$meta['key'].'_active'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      {{-- Buttons --}}
      <div class="flex items-center gap-3">
        <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Payment Methods</button>
        <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
      </div>

    </form>
  </div>
</x-layouts::app>
