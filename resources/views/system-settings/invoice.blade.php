<x-layouts::app title="Invoice Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Invoice Settings</h1>
        <p class="page-subtitle">Manage document wording, PDF layout, and public bill links</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      Changes to the wording below appear on both the print preview and downloaded PDFs. Customer notes entered in POS take priority over the default note.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="invoice">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Document numbering --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">Invoice Numbering</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Quotation</p><p class="mt-1 font-mono text-sm font-bold text-slate-900">QTN-YYYYMM-0001</p><p class="mt-1 text-xs text-slate-500">The sequence starts at 0001 each month.</p></div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">POS and job card invoices</p><p class="mt-1 font-mono text-sm font-bold text-slate-900">INV-YYYYMMDD-0001</p><p class="mt-1 text-xs text-slate-500">The shared sequence starts at 0001 each day.</p></div>
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

        {{-- Document wording --}}
        <div class="panel space-y-5 rounded-xl border border-slate-200 bg-white p-6">
          <div><h2 class="text-base font-black text-slate-800">Document Wording</h2><p class="mt-1 text-xs text-slate-500">Edit the message shown on quotations and bills. Leave a message blank to hide its box.</p></div>
          <div class="grid gap-5 xl:grid-cols-2">
            <section class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <h3 class="font-extrabold text-slate-900">Quotation</h3>
              <label class="block"><span class="label">Message heading</span><input name="quotation_intro_heading" value="{{ $settings->has('quotation_intro_heading') ? $settings['quotation_intro_heading'] : \App\Support\InvoiceDocument::QUOTATION_INTRO_HEADING }}" class="field" maxlength="100"></label>
              <label class="block"><span class="label">Message text</span><textarea name="quotation_intro_text" rows="3" class="field" maxlength="2000">{{ $settings->has('quotation_intro_text') ? $settings['quotation_intro_text'] : \App\Support\InvoiceDocument::QUOTATION_INTRO_TEXT }}</textarea></label>
              <label class="block"><span class="label">Default customer note</span><textarea name="quotation_default_note" rows="2" class="field" maxlength="1000" placeholder="Optional note when none is entered in POS">{{ $settings['quotation_default_note'] ?? '' }}</textarea></label>
            </section>
            <section class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <h3 class="font-extrabold text-slate-900">Bill</h3>
              <label class="block"><span class="label">Message heading</span><input name="bill_intro_heading" value="{{ $settings->has('bill_intro_heading') ? $settings['bill_intro_heading'] : \App\Support\InvoiceDocument::BILL_INTRO_HEADING }}" class="field" maxlength="100"></label>
              <label class="block"><span class="label">Message text</span><textarea name="bill_intro_text" rows="3" class="field" maxlength="2000">{{ $settings->has('bill_intro_text') ? $settings['bill_intro_text'] : \App\Support\InvoiceDocument::BILL_INTRO_TEXT }}</textarea></label>
              <label class="block"><span class="label">Default customer note</span><textarea name="bill_default_note" rows="2" class="field" maxlength="1000" placeholder="Optional note when none is entered in POS">{{ $settings['bill_default_note'] ?? '' }}</textarea></label>
            </section>
          </div>
          <div class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
            <label class="block"><span class="label">Customer note heading</span><input name="customer_note_heading" value="{{ $settings->has('customer_note_heading') ? $settings['customer_note_heading'] : 'Note for customer' }}" class="field" maxlength="100"></label>
            <label class="block"><span class="label">Terms heading</span><input name="terms_heading" value="{{ $settings->has('terms_heading') ? $settings['terms_heading'] : 'Terms and conditions' }}" class="field" maxlength="100"></label>
            <label class="block sm:col-span-2"><span class="label">Terms shown on both documents</span><textarea name="terms_and_conditions" rows="4" class="field" maxlength="5000">{{ $settings['terms_and_conditions'] ?? '' }}</textarea></label>
            <label class="block sm:col-span-2"><span class="label">Footer message shown on both documents</span><input name="invoice_footer_note" value="{{ $settings->has('invoice_footer_note') ? $settings['invoice_footer_note'] : 'Thank you for choosing Fasmir Motors.' }}" class="field" maxlength="300"></label>
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
