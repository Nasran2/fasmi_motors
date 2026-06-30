<x-layouts::app title="Salary Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Salary Settings</h1>
        <p class="page-subtitle">Manage salary cycles, OT rates, attendance deduction rules, and advance limits</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Payroll Cycles:</strong> This page controls technician salary sheets calculations, OT multipliers, late attendance penalties, and advance payout approvals.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="salary">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Cycle Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Salary Cycle</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Salary Cycle</label>
              <select name="salary_cycle" class="w-full">
                <option value="Monthly" @selected(($settings['salary_cycle'] ?? 'Monthly') === 'Monthly')>Monthly</option>
                <option value="Weekly" @selected(($settings['salary_cycle'] ?? '') === 'Weekly')>Weekly</option>
                <option value="Daily" @selected(($settings['salary_cycle'] ?? '') === 'Daily')>Daily</option>
              </select>
            </div>
            <div>
              <label class="label">Salary Month Start Date (Day of Month) <span class="required-star">*</span></label>
              <input type="number" name="salary_month_start" value="{{ $settings['salary_month_start'] ?? 1 }}" min="1" max="31" required class="w-full">
            </div>
            <div class="sm:col-span-2 space-y-2 pt-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="salary_allow_daily_pay" value="0">
                <input type="checkbox" name="salary_allow_daily_pay" value="1" @checked(($settings['salary_allow_daily_pay'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow daily salary advance payments during the month</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Attendance Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📅 Attendance & OT Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Working Hours Per Day</label>
              <input type="number" name="salary_working_hours" value="{{ $settings['salary_working_hours'] ?? 8 }}" min="4" max="16" class="w-full">
            </div>
            <div>
              <label class="label">OT Rate Calculation Type</label>
              <select name="salary_ot_rate_type" class="w-full">
                <option value="Fixed amount per hour" @selected(($settings['salary_ot_rate_type'] ?? 'Fixed amount per hour') === 'Fixed amount per hour')>Fixed Amount Per Hour</option>
                <option value="Salary-based calculation" @selected(($settings['salary_ot_rate_type'] ?? '') === 'Salary-based calculation')>Salary-Based Multiplier</option>
              </select>
            </div>
            <div>
              <label class="label">Late Grace Period (Minutes)</label>
              <input type="number" name="salary_late_grace" value="{{ $settings['salary_late_grace'] ?? 15 }}" class="w-full">
            </div>
            <div>
              <label class="label">Late Deduction Amount (Per Min / Fixed)</label>
              <input type="number" name="salary_late_deduction" value="{{ $settings['salary_late_deduction'] ?? 50 }}" class="w-full">
            </div>
            
            <div class="sm:col-span-2 space-y-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="salary_ot_enabled" value="0">
                <input type="checkbox" name="salary_ot_enabled" value="1" @checked(($settings['salary_ot_enabled'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Overtime (OT) calculations</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="salary_late_enabled" value="0">
                <input type="checkbox" name="salary_late_enabled" value="1" @checked(($settings['salary_late_enabled'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Late deductions</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 3: Advance Payouts --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">💸 Advance Payment limits</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Maximum Salary Advance Limit (Rs.)</label>
              <input type="number" name="salary_max_advance" value="{{ $settings['salary_max_advance'] ?? 15000 }}" class="w-full">
            </div>
            <div class="space-y-2 pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="salary_auto_deduct_advance" value="0">
                <input type="checkbox" name="salary_auto_deduct_advance" value="1" @checked(($settings['salary_auto_deduct_advance'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Deduct advances automatically from monthly paysheets</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Salary Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Extra Preferences --}}
      <div class="space-y-6">
        
        {{-- Section 4: Paysheet Controls --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Paysheet Controls</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="salary_finalize_lock" value="0">
              <input type="checkbox" name="salary_finalize_lock" value="1" @checked(($settings['salary_finalize_lock'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Finalization locks paysheet editing completely</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="salary_reopen_reason" value="0">
              <input type="checkbox" name="salary_reopen_reason" value="1" @checked(($settings['salary_reopen_reason'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require justification logs if reopening finalized paysheets</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
