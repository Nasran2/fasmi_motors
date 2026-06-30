<x-layouts::app title="Invoice Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Invoice Settings</h1>
        <p class="page-subtitle">Manage invoice formats, numbering, PDF layouts, and public bill links</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Invoice Configurations:</strong> This page controls print paper sizes, invoice number prefixes, public bill access, QR codes, and default footer signatures.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="invoice">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Numbering Prefixes --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔢 Invoice Numbering</h2>
          
          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="label">Standard Invoice Prefix</label>
              <input type="text" name="invoice_prefix" value="{{ $settings['invoice_prefix'] ?? 'INV' }}" class="w-full">
            </div>
            <div>
              <label class="label">POS Sale Prefix</label>
              <input type="text" name="pos_prefix" value="{{ $settings['pos_prefix'] ?? 'POS' }}" class="w-full">
            </div>
            <div>
              <label class="label">Job Card Bill Prefix</label>
              <input type="text" name="job_card_bill_prefix" value="{{ $settings['job_card_bill_prefix'] ?? 'JCB' }}" class="w-full">
            </div>
            <div>
              <label class="label">Invoice Start Number Offset</label>
              <input type="number" name="invoice_start_no" value="{{ $settings['invoice_start_no'] ?? 1000 }}" class="w-full">
            </div>
            <div class="sm:col-span-2 pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="reset_invoice_yearly" value="0">
                <input type="checkbox" name="reset_invoice_yearly" value="1" @checked(($settings['reset_invoice_yearly'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Reset invoice serial counter yearly</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Invoice Template & Print --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🖨️ PDF & Print Layout</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Invoice Template Style</label>
              <select name="invoice_template" class="w-full">
                <option value="Detailed" @selected(($settings['invoice_template'] ?? 'Detailed') === 'Detailed')>Detailed (Recommended)</option>
                <option value="Simple" @selected(($settings['invoice_template'] ?? '') === 'Simple')>Simple Summary</option>
                <option value="Thermal" @selected(($settings['invoice_template'] ?? '') === 'Thermal')>POS Receipt (80mm / 58mm)</option>
              </select>
            </div>
            <div>
              <label class="label">Print Paper Size</label>
              <select name="invoice_paper_size" class="w-full">
                <option value="A4" @selected(($settings['invoice_paper_size'] ?? 'A4') === 'A4')>A4 Standard</option>
                <option value="A5" @selected(($settings['invoice_paper_size'] ?? '') === 'A5')>A5 Page</option>
                <option value="80mm" @selected(($settings['invoice_paper_size'] ?? '') === '80mm')>80mm Roll</option>
                <option value="58mm" @selected(($settings['invoice_paper_size'] ?? '') === '58mm')>58mm Roll</option>
              </select>
            </div>
            
            <div class="sm:col-span-2 grid gap-2 sm:grid-cols-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="show_logo_invoice" value="0">
                <input type="checkbox" name="show_logo_invoice" value="1" @checked(($settings['show_logo_invoice'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Show Brand Logo</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="show_bike_invoice" value="0">
                <input type="checkbox" name="show_bike_invoice" value="1" @checked(($settings['show_bike_invoice'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Show Bike Details (No, Engine, Model)</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="show_technician_invoice" value="0">
                <input type="checkbox" name="show_technician_invoice" value="1" @checked(($settings['show_technician_invoice'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Show assigned Technician Name</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="show_qr_invoice" value="0">
                <input type="checkbox" name="show_qr_invoice" value="1" @checked(($settings['show_qr_invoice'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Include QR code link for online payments</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 3: Public Bill Links --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔗 Customer Public Links</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Public Bill Link Expiry</label>
              <select name="public_link_expiry" class="w-full">
                <option value="No Expiry" @selected(($settings['public_link_expiry'] ?? 'No Expiry') === 'No Expiry')>No Expiry</option>
                <option value="7 Days" @selected(($settings['public_link_expiry'] ?? '') === '7 Days')>7 Days</option>
                <option value="30 Days" @selected(($settings['public_link_expiry'] ?? '') === '30 Days')>30 Days</option>
              </select>
            </div>
            <div class="pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="public_pdf_download" value="0">
                <input type="checkbox" name="public_pdf_download" value="1" @checked(($settings['public_pdf_download'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow PDF download without login</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Invoice Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Signatures & Previews --}}
      <div class="space-y-6">
        
        {{-- Section 4: Authorized Signatures --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Invoice Authorizations</h3>
          
          <div>
            <label class="label">Signature Title Block</label>
            <input type="text" name="invoice_signature_text" value="{{ $settings['invoice_signature_text'] ?? 'Authorized Cashier Signature' }}" class="w-full">
            <p class="text-[10px] text-slate-500 mt-1">This text appears under the line in A4/A5 printouts.</p>
          </div>
        </div>

        {{-- Invoice Sample Preview --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-3">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Template Layout Options</h3>
          <p class="text-xs text-slate-500 leading-relaxed">Thermal printer templates are optimized for standard 80mm rollers, automatically stripping logo margins to maximize invoice print speed.</p>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
