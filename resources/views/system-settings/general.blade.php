<x-layouts::app title="General Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">General Settings</h1>
        <p class="page-subtitle">Manage basic system preferences and default behavior</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>General System Options:</strong> This page controls general system preferences such as currency, timezone, date format, language, and default sidebar options.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="general">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: System Preferences --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ System Preferences</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">System Name <span class="required-star">*</span></label>
              <input type="text" name="system_name" value="{{ $settings['system_name'] ?? 'Fasmir Motors Bike Repair Center System' }}" required class="w-full">
            </div>
            <div>
              <label class="label">Default Language</label>
              <select name="default_language" class="w-full">
                <option value="English" @selected(($settings['default_language'] ?? 'English') === 'English')>English</option>
                <option value="Sinhala" @selected(($settings['default_language'] ?? '') === 'Sinhala')>Sinhala</option>
                <option value="Tamil" @selected(($settings['default_language'] ?? '') === 'Tamil')>Tamil</option>
              </select>
            </div>
            <div>
              <label class="label">Default Currency</label>
              <input type="text" name="default_currency" value="{{ $settings['default_currency'] ?? 'LKR / Rs.' }}" class="w-full">
            </div>
            <div>
              <label class="label">Currency Symbol</label>
              <input type="text" name="currency_symbol" value="{{ $settings['currency_symbol'] ?? 'Rs.' }}" class="w-full">
            </div>
          </div>
        </div>

        {{-- Section 2: Time and Date Formats --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📅 Date & Time Rules</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Timezone</label>
              <select name="timezone" class="w-full">
                <option value="Asia/Colombo" @selected(($settings['timezone'] ?? 'Asia/Colombo') === 'Asia/Colombo')>Asia/Colombo (Sri Lanka)</option>
                <option value="UTC" @selected(($settings['timezone'] ?? '') === 'UTC')>UTC (Greenwich)</option>
              </select>
            </div>
            <div>
              <label class="label">Date Format</label>
              <select name="date_format" class="w-full">
                <option value="DD/MM/YYYY" @selected(($settings['date_format'] ?? 'DD/MM/YYYY') === 'DD/MM/YYYY')>DD/MM/YYYY</option>
                <option value="YYYY-MM-DD" @selected(($settings['date_format'] ?? '') === 'YYYY-MM-DD')>YYYY-MM-DD</option>
              </select>
            </div>
            <div>
              <label class="label">Time Format</label>
              <select name="time_format" class="w-full">
                <option value="12 Hour" @selected(($settings['time_format'] ?? '12 Hour') === '12 Hour')>12 Hour (e.g. 05:30 PM)</option>
                <option value="24 Hour" @selected(($settings['time_format'] ?? '') === '24 Hour')>24 Hour (e.g. 17:30)</option>
              </select>
            </div>
            <div>
              <label class="label">Financial Year Start Month</label>
              <select name="financial_year_start" class="w-full">
                <option value="January" @selected(($settings['financial_year_start'] ?? 'January') === 'January')>January</option>
                <option value="April" @selected(($settings['financial_year_start'] ?? '') === 'April')>April</option>
              </select>
            </div>
          </div>
        </div>

        {{-- Section 3: Support & Developer Details --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-800">💻 Support & Developer Details</h2>
            <button type="button" id="unlockDevBtn" onclick="openDevPinModal()" class="btn-secondary !min-h-8 !py-1 !px-2.5 text-xs text-red-600 font-black">🔓 Unlock</button>
          </div>
          
          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="label">Developer Name</label>
              <input type="text" name="developer_name" id="devName" value="{{ $settings['developer_name'] ?? 'Antigravity' }}" readonly onclick="handleDevInputClick(this)" class="w-full bg-slate-50 cursor-not-allowed">
            </div>
            <div>
              <label class="label">Support Phone</label>
              <input type="text" name="developer_phone" id="devPhone" value="{{ $settings['developer_phone'] ?? '+94 77 123 4567' }}" readonly onclick="handleDevInputClick(this)" class="w-full bg-slate-50 cursor-not-allowed">
            </div>
            <div>
              <label class="label">Website</label>
              <input type="text" name="developer_website" id="devWebsite" value="{{ $settings['developer_website'] ?? 'https://fasmirmotors.com' }}" readonly onclick="handleDevInputClick(this)" class="w-full bg-slate-50 cursor-not-allowed">
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save General Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Extra Preferences --}}
      <div class="space-y-6">
        
        {{-- Layout preferences --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">UI Layout Preferences</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="dark_mode" value="0">
              <input type="checkbox" name="dark_mode" value="1" @checked(($settings['dark_mode'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Dark Mode</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="compact_sidebar" value="0">
              <input type="checkbox" name="compact_sidebar" value="1" @checked(($settings['compact_sidebar'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Compact Sidebar</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="sidebar_search" value="0">
              <input type="checkbox" name="sidebar_search" value="1" @checked(($settings['sidebar_search'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Show Sidebar Search Box</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="show_powered_by" value="0">
              <input type="checkbox" name="show_powered_by" value="1" @checked(($settings['show_powered_by'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Show "Powered by" Footer</span>
            </label>
          </div>
        </div>

        {{-- Meta log --}}
        <div class="panel bg-[#06162f] text-white rounded-xl p-6 space-y-3">
          <h3 class="text-xs font-extrabold uppercase tracking-wider text-red-400">🛡️ System Identity</h3>
          <p class="text-xs text-slate-300 leading-relaxed">Changes to timezone and financial year configurations affect ledger daily closings and monthly sales totals calculations. Ensure changes align with national accounting schedules.</p>
        </div>

      </div>

    </form>
  </div>
  {{-- Developer Pin Modal --}}
  <dialog id="devPinModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-sm">
    <h3 class="text-base font-black text-slate-800 mb-2">🔑 Unlock Developer Details</h3>
    <p class="text-xs text-slate-500 mb-4">Please enter the 4-digit security PIN to edit Support & Developer details.</p>
    
    <div class="space-y-4">
      <div>
        <label class="label">4-Digit PIN <span class="required-star">*</span></label>
        <input type="password" id="devPinInput" maxlength="4" placeholder="••••" class="w-full text-center tracking-widest text-lg font-mono" required>
        <p id="devPinError" class="text-xs text-red-600 mt-1 hidden">Incorrect PIN. Access Denied.</p>
      </div>
      
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" onclick="closeDevPinModal()" class="btn-secondary">Cancel</button>
        <button type="button" onclick="verifyDevPin()" class="btn-primary">Verify PIN</button>
      </div>
    </div>
  </dialog>

  <script>
    let devUnlocked = false;

    function handleDevInputClick(input) {
      if (!devUnlocked) {
        openDevPinModal();
      }
    }

    function openDevPinModal() {
      if (devUnlocked) return;
      document.getElementById('devPinInput').value = '';
      document.getElementById('devPinError').classList.add('hidden');
      document.getElementById('devPinModal').showModal();
    }

    function closeDevPinModal() {
      document.getElementById('devPinModal').close();
    }

    function verifyDevPin() {
      const pin = document.getElementById('devPinInput').value;
      const errorEl = document.getElementById('devPinError');
      
      fetch("{{ route('system-settings.verify-developer-pin') }}", {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': "{{ csrf_token() }}"
        },
        body: JSON.stringify({ pin: pin })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          devUnlocked = true;
          
          // Unlock inputs
          const fields = ['devName', 'devPhone', 'devWebsite'];
          fields.forEach(fid => {
            const el = document.getElementById(fid);
            el.removeAttribute('readonly');
            el.classList.remove('bg-slate-50', 'cursor-not-allowed');
            el.classList.add('bg-white');
          });

          // Disable lock button
          const btn = document.getElementById('unlockDevBtn');
          btn.textContent = '🔓 Unlocked';
          btn.disabled = true;
          btn.classList.add('opacity-50', 'cursor-not-allowed');

          closeDevPinModal();
        } else {
          errorEl.textContent = data.message || 'Incorrect PIN. Access Denied.';
          errorEl.classList.remove('hidden');
        }
      })
      .catch(err => {
        console.error(err);
        errorEl.textContent = 'Error verifying PIN. Please try again.';
        errorEl.classList.remove('hidden');
      });
    }
  </script>
</x-layouts::app>
