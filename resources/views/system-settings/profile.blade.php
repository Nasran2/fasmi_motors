<x-layouts::app title="Business Profile">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Business Profile</h1>
        <p class="page-subtitle">Manage business details shown on invoices, reports, and SMS</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Business Profile:</strong> This information will appear on invoices, job cards, reports, and customer messages.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="business">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Business Identification --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏢 Identity Details</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Business Name <span class="required-star">*</span></label>
              <input type="text" name="business_name" id="profileName" onkeyup="updatePreview()" value="{{ $settings['business_name'] ?? 'Fasmir Motors' }}" required class="w-full">
            </div>
            <div>
              <label class="label">Business Subtitle</label>
              <input type="text" name="business_subtitle" id="profileSubtitle" onkeyup="updatePreview()" value="{{ $settings['business_subtitle'] ?? 'Bike Repair Center' }}" class="w-full">
            </div>
            <div>
              <label class="label">Business Registration No (BRN)</label>
              <input type="text" name="business_registration_no" value="{{ $settings['business_registration_no'] ?? 'PV-99999' }}" class="w-full">
            </div>
            <div>
              <label class="label">Tax Registration No (TIN)</label>
              <input type="text" name="tax_registration_no" value="{{ $settings['tax_registration_no'] ?? 'TIN-12345678' }}" class="w-full">
            </div>
          </div>
        </div>

        {{-- Section 2: Contact Info --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📞 Contact Details</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Phone Number <span class="required-star">*</span></label>
              <input type="text" name="business_phone" id="profilePhone" onkeyup="updatePreview()" value="{{ $settings['business_phone'] ?? '+94 77 000 0000' }}" required class="w-full">
            </div>
            <div>
              <label class="label">WhatsApp Number</label>
              <input type="text" name="business_whatsapp" value="{{ $settings['business_whatsapp'] ?? '+94 77 000 0000' }}" class="w-full">
            </div>
            <div>
              <label class="label">Email Address</label>
              <input type="email" name="business_email" value="{{ $settings['business_email'] ?? 'info@fasmirmotors.com' }}" class="w-full">
            </div>
            <div>
              <label class="label">Website Url</label>
              <input type="text" name="business_website" value="{{ $settings['business_website'] ?? 'www.fasmirmotors.com' }}" class="w-full">
            </div>
            <div class="sm:col-span-2">
              <label class="label">Address <span class="required-star">*</span></label>
              <textarea name="business_address" id="profileAddress" onkeyup="updatePreview()" required rows="2" class="w-full">{{ $settings['business_address'] ?? 'No. 12, Workshop Road, Badulla' }}</textarea>
            </div>
          </div>
        </div>

        {{-- Section 3: Legal & Footer Remarks --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📜 Invoice Terms & Footers</h2>
          
          <div class="space-y-4">
            <div>
              <label class="label">Invoice Footer Note</label>
              <input type="text" name="invoice_footer_note" id="profileFooter" onkeyup="updatePreview()" value="{{ $settings['invoice_footer_note'] ?? 'Thank you for choosing Fasmir Motors! Drive safe.' }}" class="w-full">
            </div>
            <div>
              <label class="label">Terms and Conditions</label>
              <textarea name="terms_and_conditions" rows="3" class="w-full">{{ $settings['terms_and_conditions'] ?? "1. All repairs are guaranteed for 30 days.\n2. Spare parts returned must be in original packings.\n3. Late collections are subject to storage charges." }}</textarea>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Business Profile</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Preview & Image Upload --}}
      <div class="space-y-6">
        
        {{-- Logo Upload --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Business Assets</h3>
          
          <div class="space-y-4">
            <div>
              <label class="label">Upload Logo</label>
              <input type="file" name="logo_file" accept="image/*" class="w-full text-xs">
              @if($settings['logo_url'] ?? '')
              <div class="mt-2 p-2 border border-slate-100 rounded-lg bg-slate-50 flex items-center justify-center">
                <img src="{{ $settings['logo_url'] }}" alt="Logo" class="max-h-16 object-contain">
              </div>
              @endif
            </div>

            <div>
              <label class="label">Upload Favicon</label>
              <input type="file" name="favicon_file" accept="image/*" class="w-full text-xs">
              @if($settings['favicon_url'] ?? '')
              <div class="mt-2 p-2 border border-slate-100 rounded-lg bg-slate-50 flex items-center justify-center">
                <img src="{{ $settings['favicon_url'] }}" alt="Favicon" class="max-h-8 object-contain">
              </div>
              @endif
            </div>
          </div>
        </div>

        {{-- Live Invoice Header Preview --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Live Header Preview</h3>
          
          <div class="border border-slate-200 rounded-xl p-4 bg-slate-50 text-slate-800 text-[10px] space-y-3 leading-tight font-mono">
            <div class="text-center border-b border-slate-200 pb-2.5">
              <h4 class="text-xs font-black uppercase tracking-wide text-slate-900" id="prevTitle">Fasmir Motors</h4>
              <p class="text-[9px] text-slate-500 font-bold" id="prevSubtitle">Bike Repair Center</p>
              <p class="text-slate-500 mt-1" id="prevAddress">No. 12, Workshop Road, Badulla</p>
              <p class="text-slate-500 font-bold" id="prevPhone">Phone: +94 77 000 0000</p>
            </div>
            
            <div class="border-b border-dashed border-slate-300 py-2 flex justify-between font-bold">
              <span>ITEM DETAILS</span>
              <span>TOTAL (Rs.)</span>
            </div>
            <div class="h-6"></div>
            
            <div class="text-center text-[9px] text-slate-400 border-t border-slate-200 pt-2" id="prevFooter">
              Thank you for choosing Fasmir Motors! Drive safe.
            </div>
          </div>
        </div>

      </div>

    </form>
  </div>

  <script>
    function updatePreview() {
      document.getElementById('prevTitle').textContent = document.getElementById('profileName').value || 'Fasmir Motors';
      document.getElementById('prevSubtitle').textContent = document.getElementById('profileSubtitle').value || 'Bike Repair Center';
      document.getElementById('prevPhone').textContent = 'Phone: ' + (document.getElementById('profilePhone').value || '+94 77 000 0000');
      document.getElementById('prevAddress').textContent = document.getElementById('profileAddress').value || 'No. 12, Workshop Road, Badulla';
      document.getElementById('prevFooter').textContent = document.getElementById('profileFooter').value || 'Thank you for choosing Fasmir Motors! Drive safe.';
    }
    
    // Initial call
    window.addEventListener('load', updatePreview);
  </script>
</x-layouts::app>
