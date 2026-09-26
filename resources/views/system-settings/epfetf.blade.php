<x-layouts::app title="EPF / ETF Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">EPF / ETF Settings</h1>
        <p class="page-subtitle">Manage government statutory contributions percentages and basis</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Statutory Deductions:</strong> These percentages are automatically loaded during monthly salary paysheet calculations and appear on technician EPF/ETF monthly reports.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="general">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Contribution Rates --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Contribution Rates</h2>
          
          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="label">Employee EPF (%) <span class="required-star">*</span></label>
              <input type="number" step="0.01" name="epf_employee" value="{{ $settings['epf_employee'] ?? 8 }}" required class="w-full">
            </div>
            <div>
              <label class="label">Company EPF (%) <span class="required-star">*</span></label>
              <input type="number" step="0.01" name="epf_company" value="{{ $settings['epf_company'] ?? 12 }}" required class="w-full">
            </div>
            <div>
              <label class="label">Company ETF (%) <span class="required-star">*</span></label>
              <input type="number" step="0.01" name="etf_company" value="{{ $settings['etf_company'] ?? 3 }}" required class="w-full">
            </div>
          </div>
        </div>

        {{-- Section 2: Calculation basis --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📈 Calculation Basis</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">EPF Basis</label>
              <select name="epf_basis" class="w-full">
                <option value="Basic Salary" @selected(($settings['epf_basis'] ?? 'Basic Salary') === 'Basic Salary')>Basic Salary</option>
                <option value="Gross Salary" @selected(($settings['epf_basis'] ?? '') === 'Gross Salary')>Gross Salary</option>
              </select>
            </div>
            <div>
              <label class="label">ETF Basis</label>
              <select name="etf_basis" class="w-full">
                <option value="Basic Salary" @selected(($settings['etf_basis'] ?? 'Basic Salary') === 'Basic Salary')>Basic Salary</option>
                <option value="Gross Salary" @selected(($settings['etf_basis'] ?? '') === 'Gross Salary')>Gross Salary</option>
              </select>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save EPF / ETF Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Extra Preferences --}}
      <div class="space-y-6">
        
        {{-- Section 3: Visibility --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Paysheet Visibility</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="epf_show_paysheet" value="0">
              <input type="checkbox" name="epf_show_paysheet" value="1" @checked(($settings['epf_show_paysheet'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Show EPF/ETF details in PDF paysheets</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="epf_export_reports" value="0">
              <input type="checkbox" name="epf_export_reports" value="1" @checked(($settings['epf_export_reports'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow exporting statutory CSV reports</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
