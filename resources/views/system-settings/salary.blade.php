<x-layouts::app title="Salary & Attendance Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Salary & Attendance Settings</h1>
        <p class="page-subtitle">Configure technician payroll schedules, overtime, early leaves, and half-day triggers</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Technician Attendance & OT Rules:</strong> This page establishes company workshop hours and deduction thresholds. Make sure to map correct cutoff hours to automate morning and evening half-day markers.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="salary">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Salary Cycle --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Salary Cycle & Advances</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Salary Cycle</label>
              <select name="salary_cycle" class="w-full">
                <option value="Monthly" @selected($salarySetting->salary_cycle === 'Monthly')>Monthly</option>
                <option value="Weekly" @selected($salarySetting->salary_cycle === 'Weekly')>Weekly</option>
                <option value="Daily" @selected($salarySetting->salary_cycle === 'Daily')>Daily</option>
              </select>
            </div>
            <div>
              <label class="label">Salary Start Day (Day of Month) <span class="required-star">*</span></label>
              <input type="number" name="salary_month_start_day" value="{{ $salarySetting->salary_month_start_day }}" min="1" max="31" required class="w-full">
            </div>
            <div class="sm:col-span-2 pt-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="allow_daily_advance" value="0">
                <input type="checkbox" name="allow_daily_advance" value="1" @checked($salarySetting->allow_daily_advance) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow daily salary advance payments during the month</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 1B: Salary Month Calculation Rule --}}
        <div class="panel bg-white border border-red-100 rounded-xl p-6 space-y-4 shadow-sm">
          <div class="flex items-start justify-between gap-4 border-b border-slate-100 pb-3">
            <div>
              <h2 class="text-base font-black text-slate-800">📅 Salary Month Calculation Rule</h2>
              <p class="mt-1 text-xs text-slate-500">Use calendar months by default, or enable the custom cut-off cycle for carried salary days.</p>
            </div>
            <span class="rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider {{ $salaryCycleSetting->isCustomCutoffActive() ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600' }}">
              {{ $salaryCycleSetting->isCustomCutoffActive() ? 'Custom ON' : 'Calendar Month' }}
            </span>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Salary Calculation Mode</label>
              <select name="salary_cycle_mode" class="w-full">
                <option value="normal_calendar" @selected($salaryCycleSetting->salary_cycle_mode === 'normal_calendar')>Normal Calendar Month</option>
                <option value="custom_cutoff" @selected($salaryCycleSetting->salary_cycle_mode === 'custom_cutoff')>Custom Cut-Off Cycle</option>
              </select>
            </div>
            <div class="pt-7">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="custom_cutoff_enabled" value="0">
                <input type="checkbox" name="custom_cutoff_enabled" value="1" @checked($salaryCycleSetting->custom_cutoff_enabled) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Custom Cut-Off Cycle</span>
              </label>
            </div>
            <div>
              <label class="label">Cycle Start Rule</label>
              <select name="cycle_start_rule" class="w-full">
                <option value="previous_month_balance_day" @selected(($salaryCycleSetting->cycle_start_rule ?: 'previous_month_balance_day') === 'previous_month_balance_day')>Start from previous month balance day</option>
              </select>
            </div>
            <div>
              <label class="label">Cycle End Day</label>
              <select name="cycle_end_day" class="w-full">
                @foreach(range(1, 31) as $day)
                <option value="{{ $day }}" @selected((int) ($salaryCycleSetting->cycle_end_day ?: 30) === $day)>{{ $day }}</option>
                @endforeach
              </select>
              <p class="mt-1 text-[10px] text-slate-400">Example with day 30: July 2026 = 30 Jun to 30 Jul, August 2026 = 31 Jul to 29 Aug.</p>
            </div>
            <div class="sm:col-span-2 pt-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="carry_missing_days" value="0">
                <input type="checkbox" name="carry_missing_days" value="1" @checked($salaryCycleSetting->carry_missing_days) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Carry missing days to next salary month</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Workshop Time Settings --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🕒 Workshop Time Settings</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Workshop Opening Time <span class="required-star">*</span></label>
              <input type="text" name="opening_time" value="{{ $salarySetting->opening_time }}" placeholder="08:30 AM" required class="w-full">
              <p class="text-[9px] text-slate-400 mt-1">Example: 08:30 AM</p>
            </div>
            <div>
              <label class="label">Workshop Closing Time <span class="required-star">*</span></label>
              <input type="text" name="closing_time" value="{{ $salarySetting->closing_time }}" placeholder="05:00 PM" required class="w-full">
              <p class="text-[9px] text-slate-400 mt-1">Example: 05:00 PM</p>
            </div>
            <div>
              <label class="label">Working Hours Per Day <span class="required-star">*</span></label>
              <input type="number" step="0.1" name="working_hours_per_day" value="{{ $salarySetting->working_hours_per_day }}" required class="w-full">
            </div>
            <div>
              <label class="label">Lunch Break Duration (Minutes)</label>
              <input type="number" name="lunch_break_minutes" value="{{ $salarySetting->lunch_break_minutes }}" class="w-full">
            </div>
            <div class="sm:col-span-2">
              <label class="label">Weekly Work Days</label>
              <div class="grid grid-cols-4 gap-2 mt-2">
                @php
                  $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                  $savedDays = $salarySetting->work_days ?? array_values(array_diff($days, app(\App\Services\HolidayService::class)->getWeeklyLeaveDays()));
                @endphp
                @foreach($days as $day)
                <label class="flex items-center gap-1.5 cursor-pointer text-xs font-bold text-slate-700">
                  <input type="checkbox" name="work_days[]" value="{{ $day }}" @checked(in_array($day, $savedDays)) class="rounded text-red-600 focus:ring-red-500">
                  {{ $day }}
                </label>
                @endforeach
              </div>
            </div>
          </div>
        </div>

        {{-- Section 3: Late Coming Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏃‍♂️ Late Coming Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="enable_late_deduction" value="0">
                <input type="checkbox" name="enable_late_deduction" value="1" @checked($salarySetting->enable_late_deduction) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Late coming salary deductions</span>
              </label>
            </div>
            <div>
              <label class="label">Late Grace Period (Minutes)</label>
              <input type="number" name="late_grace_minutes" value="{{ $salarySetting->late_grace_minutes }}" class="w-full">
            </div>
            <div>
              <label class="label">Late Deduction Type</label>
              <select name="late_deduction_type" class="w-full">
                <option value="Fixed amount" @selected($salarySetting->late_deduction_type === 'Fixed amount')>Fixed amount per day</option>
                <option value="Per minute amount" @selected($salarySetting->late_deduction_type === 'Per minute amount')>Per minute amount</option>
              </select>
            </div>
            <div>
              <label class="label">Late Deduction Amount (Rs.)</label>
              <input type="number" step="0.01" name="late_deduction_amount" value="{{ $salarySetting->late_deduction_amount }}" class="w-full">
            </div>
            <div class="pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="require_reason_late_edit" value="0">
                <input type="checkbox" name="require_reason_late_edit" value="1" @checked($salarySetting->require_reason_late_edit) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require reason when overriding late minutes</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 4: Early Leave Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🚶‍♂️ Early Leave Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="enable_early_leave_deduction" value="0">
                <input type="checkbox" name="enable_early_leave_deduction" value="1" @checked($salarySetting->enable_early_leave_deduction) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Early leave salary deductions</span>
              </label>
            </div>
            <div>
              <label class="label">Early Leave Grace Period (Minutes)</label>
              <input type="number" name="early_leave_grace_minutes" value="{{ $salarySetting->early_leave_grace_minutes }}" class="w-full">
            </div>
            <div>
              <label class="label">Early Leave Deduction Type</label>
              <select name="early_leave_deduction_type" class="w-full">
                <option value="Fixed amount" @selected($salarySetting->early_leave_deduction_type === 'Fixed amount')>Fixed amount per day</option>
                <option value="Per minute amount" @selected($salarySetting->early_leave_deduction_type === 'Per minute amount')>Per minute amount</option>
                <option value="Half-day deduction" @selected($salarySetting->early_leave_deduction_type === 'Half-day deduction')>Half-day deduction</option>
              </select>
            </div>
            <div>
              <label class="label">Deduction Amount (Rs.)</label>
              <input type="number" step="0.01" name="early_leave_deduction_amount" value="{{ $salarySetting->early_leave_deduction_amount }}" class="w-full">
            </div>
            <div class="pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="require_reason_early_leave" value="0">
                <input type="checkbox" name="require_reason_early_leave" value="1" @checked($salarySetting->require_reason_early_leave) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require reason when leaving early</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 5: Overtime Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔥 Overtime (OT) Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="enable_ot" value="0">
                <input type="checkbox" name="enable_ot" value="1" @checked($salarySetting->enable_ot) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Overtime (OT) calculations after closing time</span>
              </label>
              <p class="mt-2 text-xs text-slate-500">OT is eligible only for check-in at or before workshop opening time (8:00 AM when opening is 8:00). Check-in at 8:01 AM is ineligible. Closing-time grace still applies. Eligible OT is paid to the nearest 30 minutes: 5:16 → 5:30, 5:44 → 5:30, 5:45 → 6:00 when closing is 5:00 PM. Actual punch times are retained.</p>
            </div>
            <div>
              <label class="label">OT Grace Period after Closing (Minutes)</label>
              <input type="number" name="ot_grace_minutes_after_closing" value="{{ $salarySetting->ot_grace_minutes_after_closing }}" class="w-full">
            </div>
            <div>
              <label class="label">Fallback slab amount rounding</label>
              <select name="ot_round_method" class="w-full">
                <option value="Ceiling slab" @selected($salarySetting->ot_round_method === 'Ceiling slab')>Ceiling slab (round up)</option>
                <option value="Floor slab" @selected($salarySetting->ot_round_method === 'Floor slab')>Floor slab (round down)</option>
                <option value="Nearest slab" @selected($salarySetting->ot_round_method === 'Nearest slab')>Nearest slab</option>
                <option value="Exact minutes" @selected($salarySetting->ot_round_method === 'Exact minutes')>Exact minutes</option>
              </select>
            </div>
            <div>
              <label class="label">OT Calculation Type</label>
              <select name="ot_calculation_type" class="w-full">
                <option value="Fixed amount per slab" @selected($salarySetting->ot_calculation_type === 'Fixed amount per slab')>Fixed amount per slab interval</option>
                <option value="Fixed amount per hour" @selected($salarySetting->ot_calculation_type === 'Fixed amount per hour')>Fixed amount per hour</option>
                <option value="Salary-based hourly rate" @selected($salarySetting->ot_calculation_type === 'Salary-based hourly rate')>Salary-based hourly rate</option>
              </select>
            </div>
            <div>
              <label class="label">Fallback OT Amount per Slab (Rs.)</label>
              <input type="number" step="0.01" name="ot_amount_per_slab" value="{{ $salarySetting->ot_amount_per_slab }}" class="w-full">
            </div>
            <div>
              <label class="label">Fallback OT Slab Duration (Minutes)</label>
              <input type="number" name="ot_slab_minutes" value="{{ $salarySetting->ot_slab_minutes }}" class="w-full">
            </div>
            <div class="grid grid-cols-2 gap-2 sm:col-span-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="require_ot_approval" value="0">
                <input type="checkbox" name="require_ot_approval" value="1" @checked($salarySetting->require_ot_approval) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require supervisor approval for OT payouts</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="allow_manual_ot_override" value="0">
                <input type="checkbox" name="allow_manual_ot_override" value="1" @checked($salarySetting->allow_manual_ot_override) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow manual OT overrides per day</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 6: Custom OT Slab Amount Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-800">📊 OT Slab Amount Rules</h2>
            <button type="button" onclick="addSlabRow()" class="btn-secondary !min-h-8 !py-1 !px-2.5 text-xs text-red-600 font-black">＋ Add Slab Row</button>
          </div>
          
          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                <tr>
                  <th class="px-2 py-2 font-bold w-20">From Min</th>
                  <th class="px-2 py-2 font-bold w-20">To Min</th>
                  <th class="px-2 py-2 font-bold w-28">OT Amount (Rs.)</th>
                  <th class="px-2 py-2 font-bold">Calculation Rule</th>
                  <th class="px-2 py-2 font-bold w-24">Status</th>
                  <th class="px-2 py-2 font-bold text-right w-16">Action</th>
                </tr>
              </thead>
              <tbody id="slabsTableBody" class="divide-y divide-slate-100">
                @foreach($otSlabs as $slab)
                <tr data-slab-id="{{ $slab->id }}">
                  <td class="py-2 pr-2">
                    <input type="number" name="slabs[{{ $slab->id }}][from_minutes]" value="{{ $slab->from_minutes }}" required class="w-full !h-8 text-xs">
                  </td>
                  <td class="py-2 pr-2">
                    <input type="number" name="slabs[{{ $slab->id }}][to_minutes]" value="{{ $slab->to_minutes }}" required class="w-full !h-8 text-xs">
                  </td>
                  <td class="py-2 pr-2">
                    <input type="number" step="0.01" name="slabs[{{ $slab->id }}][amount]" value="{{ $slab->amount }}" required class="w-full !h-8 text-xs">
                  </td>
                  <td class="py-2 pr-2">
                    <select name="slabs[{{ $slab->id }}][calculation_rule]" class="w-full !h-8 text-xs">
                      <option value="Fixed for this range" @selected($slab->calculation_rule === 'Fixed for this range')>Fixed for this range</option>
                      <option value="Add previous slab amount" @selected($slab->calculation_rule === 'Add previous slab amount')>Add previous slab amount</option>
                    </select>
                  </td>
                  <td class="py-2 pr-2">
                    <select name="slabs[{{ $slab->id }}][status]" class="w-full !h-8 text-xs">
                      <option value="Active" @selected($slab->status === 'Active')>Active</option>
                      <option value="Inactive" @selected($slab->status === 'Inactive')>Inactive</option>
                    </select>
                  </td>
                  <td class="py-2 text-right">
                    <button type="button" onclick="this.closest('tr').remove()" class="text-red-600 font-bold hover:underline">Delete</button>
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>

        {{-- Section 7: Half Day Rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🌓 Half Day Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="enable_half_day" value="0">
                <input type="checkbox" name="enable_half_day" value="1" @checked($salarySetting->enable_half_day) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Half Day rules for late arrival / early leave</span>
              </label>
            </div>
            <div>
              <label class="label">Morning Half Day Cutoff Time</label>
              <input type="text" name="morning_half_day_cutoff" value="{{ $salarySetting->morning_half_day_cutoff }}" placeholder="12:30 PM" class="w-full">
            </div>
            <div>
              <label class="label">Evening Half Day Cutoff Time</label>
              <input type="text" name="evening_half_day_cutoff" value="{{ $salarySetting->evening_half_day_cutoff }}" placeholder="01:30 PM" class="w-full">
            </div>
            <div>
              <label class="label">Half Day Salary Deduction Type</label>
              <select name="half_day_deduction_type" class="w-full">
                <option value="Half of daily salary" @selected($salarySetting->half_day_deduction_type === 'Half of daily salary')>Half of daily rate (50%)</option>
                <option value="Fixed amount" @selected($salarySetting->half_day_deduction_type === 'Fixed amount')>Fixed amount</option>
              </select>
            </div>
            <div>
              <label class="label">Deduction Amount (Rs.)</label>
              <input type="number" step="0.01" name="half_day_deduction_amount" value="{{ $salarySetting->half_day_deduction_amount }}" class="w-full">
            </div>
          </div>
        </div>

        {{-- Action Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Salary Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Paysheet Lock Controls --}}
      <div class="space-y-6">
        
        {{-- Section 8: Finalization --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Paysheet Controls</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="paysheet_finalize_lock" value="0">
              <input type="checkbox" name="paysheet_finalize_lock" value="1" @checked($salarySetting->paysheet_finalize_lock) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Finalization locks paysheet editing completely</span>
            </label>
            
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="require_reason_reopen_paysheet" value="0">
              <input type="checkbox" name="require_reason_reopen_paysheet" value="1" @checked($salarySetting->require_reason_reopen_paysheet) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require justification logs if reopening finalized paysheets</span>
            </label>
          </div>
        </div>

        {{-- Section 9: Payslip Printing --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Payslip Printing</h3>
          
          <div class="space-y-4">
            <div>
              <label class="label">Slip Paper Size <span class="required-star">*</span></label>
              <select name="slip_paper_size" class="w-full">
                <option value="A4" @selected($salarySetting->slip_paper_size === 'A4')>A4 Size</option>
                <option value="A5" @selected($salarySetting->slip_paper_size === 'A5')>A5 Size</option>
                <option value="POS" @selected($salarySetting->slip_paper_size === 'POS')>80mm POS Thermal</option>
              </select>
            </div>

            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="slip_show_attendance" value="0">
              <input type="checkbox" name="slip_show_attendance" value="1" @checked($salarySetting->slip_show_attendance) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Show daily attendance breakdown on printed slip</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>

  <script>
    let slabCount = 1000;
    function addSlabRow() {
      const tbody = document.getElementById('slabsTableBody');
      const newId = 'new_' + (++slabCount);
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="py-2 pr-2">
          <input type="number" name="slabs[${newId}][from_minutes]" required class="w-full !h-8 text-xs">
        </td>
        <td class="py-2 pr-2">
          <input type="number" name="slabs[${newId}][to_minutes]" required class="w-full !h-8 text-xs">
        </td>
        <td class="py-2 pr-2">
          <input type="number" step="0.01" name="slabs[${newId}][amount]" required class="w-full !h-8 text-xs">
        </td>
        <td class="py-2 pr-2">
          <select name="slabs[${newId}][calculation_rule]" class="w-full !h-8 text-xs">
            <option value="Fixed for this range">Fixed for this range</option>
            <option value="Add previous slab amount">Add previous slab amount</option>
          </select>
        </td>
        <td class="py-2 pr-2">
          <select name="slabs[${newId}][status]" class="w-full !h-8 text-xs">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </td>
        <td class="py-2 text-right">
          <button type="button" onclick="this.closest('tr').remove()" class="text-red-600 font-bold hover:underline">Delete</button>
        </td>
      `;
      tbody.appendChild(tr);
    }
  </script>
</x-layouts::app>
