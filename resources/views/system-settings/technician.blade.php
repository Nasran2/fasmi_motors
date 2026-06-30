<x-layouts::app title="Technician Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Technician Settings</h1>
        <p class="page-subtitle">Configure technician job allocations, commission rates, and repair deductions</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Staff Parameters:</strong> This page regulates staff allocations, commission splitting structures, and options to deduct technician own bike repairs from monthly paysheets.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="technician">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Allocations --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Assignment Controls</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="tech_sms_assigned" value="0">
              <input type="checkbox" name="tech_sms_assigned" value="1" @checked(($settings['tech_sms_assigned'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Send automatic SMS to technician when assigned to job</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="tech_track_history" value="0">
              <input type="checkbox" name="tech_track_history" value="1" @checked(($settings['tech_track_history'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track logs of all past job assignment changes</span>
            </label>
          </div>
        </div>

        {{-- Section 2: Commission rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">💰 Commission Configurations</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Default Commission Percentage (%) <span class="required-star">*</span></label>
              <input type="number" step="0.01" name="default_commission" value="{{ $settings['default_commission'] ?? 10 }}" required class="w-full">
            </div>
            <div>
              <label class="label">Commission Calculation Type</label>
              <select name="commission_calc_type" class="w-full">
                <option value="Total Bill" @selected(($settings['commission_calc_type'] ?? 'Total Bill') === 'Total Bill')>Total Invoice Value</option>
                <option value="Service/Labor Only" @selected(($settings['commission_calc_type'] ?? '') === 'Service/Labor Only')>Service & Labor Charges Only</option>
                <option value="Spare Parts Profit Only" @selected(($settings['commission_calc_type'] ?? '') === 'Spare Parts Profit Only')>Spare Parts Profit Margins Only</option>
              </select>
            </div>
            
            <div class="sm:col-span-2 space-y-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="tech_commission_override" value="0">
                <input type="checkbox" name="tech_commission_override" value="1" @checked(($settings['tech_commission_override'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow manual commission percentages override per job card</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="tech_commission_split" value="0">
                <input type="checkbox" name="tech_commission_split" value="1" @checked(($settings['tech_commission_split'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow splitting commission among multiple technicians</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 3: Own Bike Deductions --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏍️ Own Bike Repairs</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="tech_own_bike_deduction" value="0">
              <input type="checkbox" name="tech_own_bike_deduction" value="1" @checked(($settings['tech_own_bike_deduction'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Automatically deduct technician own-bike costs from salary sheets</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="tech_own_bike_installment" value="0">
              <input type="checkbox" name="tech_own_bike_installment" value="1" @checked(($settings['tech_own_bike_installment'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow installment deductions across multiple months</span>
            </label>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Technician Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Extra Preferences --}}
      <div class="space-y-6">
        
        {{-- Section 4: Paysheet Integration --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Paysheet Integration</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="tech_commission_salary" value="0">
              <input type="checkbox" name="tech_commission_salary" value="1" @checked(($settings['tech_commission_salary'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Include earned commissions inside paysheets</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
