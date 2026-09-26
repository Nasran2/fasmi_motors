<x-layouts::app title="Tax / Discount Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Tax & Discount Settings</h1>
        <p class="page-subtitle">Configure VAT rates, standard customer discounts, and POS override rules</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Taxes & Deductions:</strong> Set system tax percentages (e.g. VAT) and define max allowable discount parameters to prevent checkout overrides without authorization.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="tax">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Tax Settings --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏷️ Tax & VAT Settings</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Tax Label Name</label>
              <input type="text" name="tax_name" value="{{ $settings['tax_name'] ?? 'VAT' }}" class="w-full">
            </div>
            <div>
              <label class="label">Tax Rate Percentage (%)</label>
              <input type="number" step="0.01" name="tax_percentage" value="{{ $settings['tax_percentage'] ?? 0 }}" class="w-full">
            </div>
            
            <div class="sm:col-span-2 space-y-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="tax_enabled" value="0">
                <input type="checkbox" name="tax_enabled" value="1" @checked(($settings['tax_enabled'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Enable Tax / VAT charging</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="tax_after_discount" value="0">
                <input type="checkbox" name="tax_after_discount" value="1" @checked(($settings['tax_after_discount'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Calculate tax after discounts are subtracted</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Discount Limits --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📉 Discount Limits</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Maximum Allowable Discount (%)</label>
              <input type="number" name="max_discount_percent" value="{{ $settings['max_discount_percent'] ?? 15 }}" class="w-full">
            </div>
            <div class="space-y-2 pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="discount_require_approval" value="0">
                <input type="checkbox" name="discount_require_approval" value="1" @checked(($settings['discount_require_approval'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require supervisor approval to exceed max discount</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Tax Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Options --}}
      <div class="space-y-6">
        
        {{-- Section 3: Allowed Discount Areas --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Allowed Areas</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="allow_discount_pos" value="0">
              <input type="checkbox" name="allow_discount_pos" value="1" @checked(($settings['allow_discount_pos'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow discounts in POS Sales</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="allow_discount_jobcard" value="0">
              <input type="checkbox" name="allow_discount_jobcard" value="1" @checked(($settings['allow_discount_jobcard'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow discounts in Job Card Billing</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
