<x-layouts::app title="Account Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Account Settings</h1>
        <p class="page-subtitle">Manage financial account structures, income routing, and daily closing parameters</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Ledger Control:</strong> This page determines money flows. Be careful modifying these rules as they immediately affect daily closing balance-forwards, account summaries, and ledger entries.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="account">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Account Structure --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Account Structures</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_main_account" value="0">
              <input type="checkbox" name="enable_main_account" value="1" @checked(($settings['enable_main_account'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Main Account</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_spare_parts_account" value="0">
              <input type="checkbox" name="enable_spare_parts_account" value="1" @checked(($settings['enable_spare_parts_account'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Spare Parts Account</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_bank_account" value="0">
              <input type="checkbox" name="enable_bank_account" value="1" @checked(($settings['enable_bank_account'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Bank Account</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_cash_book" value="0">
              <input type="checkbox" name="enable_cash_book" value="1" @checked(($settings['enable_cash_book'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Cash Book</span>
            </label>
          </div>
        </div>

        {{-- Section 2: Income Split Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔀 Revenue Split Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Service / Labor Income Goes To</label>
              <select name="service_income_account" class="w-full">
                <option value="Main Account" @selected(($settings['service_income_account'] ?? 'Main Account') === 'Main Account')>Main Account</option>
                <option value="Spare Parts Account" @selected(($settings['service_income_account'] ?? '') === 'Spare Parts Account')>Spare Parts Account</option>
              </select>
            </div>
            <div>
              <label class="label">Washing Income Goes To</label>
              <select name="washing_income_account" class="w-full">
                <option value="Main Account" @selected(($settings['washing_income_account'] ?? 'Main Account') === 'Main Account')>Main Account</option>
                <option value="Spare Parts Account" @selected(($settings['washing_income_account'] ?? '') === 'Spare Parts Account')>Spare Parts Account</option>
              </select>
            </div>
            <div>
              <label class="label">Spare Parts Retail Sale Goes To</label>
              <select name="parts_sales_account" class="w-full">
                <option value="Spare Parts Account" @selected(($settings['parts_sales_account'] ?? 'Spare Parts Account') === 'Spare Parts Account')>Spare Parts Account</option>
                <option value="Main Account" @selected(($settings['parts_sales_account'] ?? '') === 'Main Account')>Main Account</option>
              </select>
            </div>
            <div>
              <label class="label">Job Card Parts Income Goes To</label>
              <select name="job_parts_account" class="w-full">
                <option value="Spare Parts Account" @selected(($settings['job_parts_account'] ?? 'Spare Parts Account') === 'Spare Parts Account')>Spare Parts Account</option>
                <option value="Main Account" @selected(($settings['job_parts_account'] ?? '') === 'Main Account')>Main Account</option>
              </select>
            </div>
          </div>
        </div>

        {{-- Section 3: Daily Closing Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🕒 Daily closing controls</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_daily_closing" value="0">
              <input type="checkbox" name="enable_daily_closing" value="1" @checked(($settings['enable_daily_closing'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require Daily Closing to be completed</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="lock_billing_until_closed" value="0">
              <input type="checkbox" name="lock_billing_until_closed" value="1" @checked(($settings['lock_billing_until_closed'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Block next day billing before closing previous day</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="allow_closing_diff_edit" value="0">
              <input type="checkbox" name="allow_closing_diff_edit" value="1" @checked(($settings['allow_closing_diff_edit'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow editing closing differences</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="reopen_closing_admin_approval" value="0">
              <input type="checkbox" name="reopen_closing_admin_approval" value="1" @checked(($settings['reopen_closing_admin_approval'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require Admin credentials to reopen past closing</span>
            </label>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Account Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Ledger Rules --}}
      <div class="space-y-6">
        
        {{-- Section 4: Ledger Locks --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Ledger Modification Rules</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="allow_manual_ledger_edit" value="0">
              <input type="checkbox" name="allow_manual_ledger_edit" value="1" @checked(($settings['allow_manual_ledger_edit'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow manual ledger entries</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="require_ledger_reason" value="0">
              <input type="checkbox" name="require_ledger_reason" value="1" @checked(($settings['require_ledger_reason'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require justification note for adjustments</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="disable_permanent_financial_delete" value="0">
              <input type="checkbox" name="disable_permanent_financial_delete" value="1" @checked(($settings['disable_permanent_financial_delete'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Block deletion of financial logs (audit safe)</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
