<x-layouts::app title="Stock Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Stock Settings</h1>
        <p class="page-subtitle">Configure stock deduction, price batches, issue types, and low-level alerts</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Stock Controls:</strong> This page regulates item deductions, negative balances allowance, batch merges, pricing configurations, and low stock indicators.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="stock">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Stock Policy --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Stock Policies</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_stock_management" value="0">
              <input type="checkbox" name="enable_stock_management" value="1" @checked(($settings['enable_stock_management'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Stock Management tracking</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="negative_stock" value="0">
              <input type="checkbox" name="negative_stock" value="1" @checked(($settings['negative_stock'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700 text-red-600">Allow Negative Stock</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="show_out_of_stock_pos" value="0">
              <input type="checkbox" name="show_out_of_stock_pos" value="1" @checked(($settings['show_out_of_stock_pos'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Show Out of Stock items in POS search</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="show_low_stock_warning" value="0">
              <input type="checkbox" name="show_low_stock_warning" value="1" @checked(($settings['show_low_stock_warning'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Display low stock alerts on dashboard</span>
            </label>
          </div>
        </div>

        {{-- Section 2: Batch Pricing --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏷️ Batch & Price Merge Options</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="enable_batch_pricing" value="0">
              <input type="checkbox" name="enable_batch_pricing" value="1" @checked(($settings['enable_batch_pricing'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track cost/sell pricing per batch</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="create_batch_price_change" value="0">
              <input type="checkbox" name="create_batch_price_change" value="1" @checked(($settings['create_batch_price_change'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Create new stock batch when purchase price changes</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="merge_identical_batches" value="0">
              <input type="checkbox" name="merge_identical_batches" value="1" @checked(($settings['merge_identical_batches'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Automatically merge batches if cost and sell prices match</span>
            </label>
          </div>
        </div>

        {{-- Section 3: Stock Issue Strategy --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📦 Stock Issue Strategy</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Issue Policy / Method</label>
              <select name="stock_issue_method" class="w-full">
                <option value="Manual Batch Selection" @selected(($settings['stock_issue_method'] ?? 'Manual Batch Selection') === 'Manual Batch Selection')>Manual Selection (Recommended)</option>
                <option value="FIFO" @selected(($settings['stock_issue_method'] ?? '') === 'FIFO')>First In, First Out (FIFO)</option>
                <option value="LIFO" @selected(($settings['stock_issue_method'] ?? '') === 'LIFO')>Last In, First Out (LIFO)</option>
              </select>
            </div>
            <div>
              <label class="label">Default Low Stock Alert Threshold</label>
              <input type="number" name="default_min_stock_level" value="{{ $settings['default_min_stock_level'] ?? 5 }}" class="w-full">
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Stock Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Extra Preferences --}}
      <div class="space-y-6">
        
        {{-- Section 4: Deduction Triggers --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Deduction Triggers</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="deduct_on_invoice_confirm" value="0">
              <input type="checkbox" name="deduct_on_invoice_confirm" value="1" @checked(($settings['deduct_on_invoice_confirm'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Deduct stock immediately on invoice generation</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="restore_on_cancel" value="0">
              <input type="checkbox" name="restore_on_cancel" value="1" @checked(($settings['restore_on_cancel'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Restore stock counts when invoices are cancelled</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
