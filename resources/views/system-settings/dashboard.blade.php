<x-layouts::app title="Settings Dashboard">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div>
      <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
      <h1 class="page-title">Settings Dashboard</h1>
      <p class="page-subtitle">Configure business rules, integrations, and preferences</p>
    </div>

    {{-- Search Settings --}}
    <div class="relative max-w-xl">
      <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>
      </svg>
      <input type="text" id="settingsSearch" onkeyup="filterSettings()" placeholder="Search settings (e.g. SMS, Invoice, EPF, Store)..." class="w-full !pl-12 !h-12 border-slate-300">
    </div>

    <x-flash />

    {{-- Settings Cards Grid --}}
    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" id="settingsGrid">
      
      {{-- Card 1: General Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="general name language currency timezone date time">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">⚙️</div>
          <h3 class="text-sm font-bold text-slate-900">General Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Configure currency, language, timezone, date layouts, and dashboard rules.</p>
        </div>
        <a href="{{ route('system-settings.general-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 2: Business Profile --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="business profile address phone logo terms favicon title">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">🏢</div>
          <h3 class="text-sm font-bold text-slate-900">Business Profile</h3>
          <p class="text-xs text-slate-500 leading-normal">Update company headers, logo, contact information, website, and footer notes.</p>
        </div>
        <a href="{{ route('system-settings.business-profile') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 3: Branch / Store Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="branch store multiple default location inventory warehouse">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📍</div>
          <h3 class="text-sm font-bold text-slate-900">Branch / Store Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Create and manage inventory stores, codes, default stock points, and access.</p>
        </div>
        <a href="{{ route('system-settings.branch-store-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 4: Invoice Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="invoice numbering prefix paper template print public signature bill">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📄</div>
          <h3 class="text-sm font-bold text-slate-900">Invoice Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Define invoice numbering formats, prefix terms, layouts, and public bill rules.</p>
        </div>
        <a href="{{ route('system-settings.invoice-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 5: Job Card Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="job card numbering status technician KM service card assignment">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📋</div>
          <h3 class="text-sm font-bold text-slate-900">Job Card Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Configure repair statuses, colors, job workflow, assignments, and reminders.</p>
        </div>
        <a href="{{ route('system-settings.job-card-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 6: Account Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="account structure bank cash ledger closing rules splitting income cards">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">💰</div>
          <h3 class="text-sm font-bold text-slate-900">Account Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Manage financial accounts, income rules, ledger editing permissions, and closings.</p>
        </div>
        <a href="{{ route('system-settings.account-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 7: Stock Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="stock management negative batch pricing fifo lifo deduction low limit">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📦</div>
          <h3 class="text-sm font-bold text-slate-900">Stock Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Manage stock tracking methods (FIFO, LIFO), batch pricing, and negative levels.</p>
        </div>
        <a href="{{ route('system-settings.stock-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 8: Technician Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="technician assignment commission bike deduction EPF gross basic override">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">🔧</div>
          <h3 class="text-sm font-bold text-slate-900">Technician Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Control labor commission rates, technician assignments, and own-bike costs.</p>
        </div>
        <a href="{{ route('system-settings.technician-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 9: Salary Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="salary cycle month end daily OT late grace paysheet rules deductions">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">💸</div>
          <h3 class="text-sm font-bold text-slate-900">Salary Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Define payroll periods, attendance salary calculations, OT, advances, and paysheets.</p>
        </div>
        <a href="{{ route('system-settings.salary-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 10: EPF / ETF Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="epf etf percentage contribution employee company basic gross">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📈</div>
          <h3 class="text-sm font-bold text-slate-900">EPF / ETF Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Configure default EPF/ETF employee and employer percentage structures.</p>
        </div>
        <a href="{{ route('system-settings.epf-etf-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 11: SMS Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="sms settings gateway textit url templates auto phone events">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">💬</div>
          <h3 class="text-sm font-bold text-slate-900">SMS Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Set up SMS Gateway credentials, templates, and automate trigger events.</p>
        </div>
        <a href="{{ route('system-settings.sms-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 12: Service Reminder Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="service reminder auto KM gap repeat templates overdue upcoming priority">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">⏰</div>
          <h3 class="text-sm font-bold text-slate-900">Service Reminder Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Manage recurring follow-ups, date ranges, and SMS templates for reminders.</p>
        </div>
        <a href="{{ route('system-settings.service-reminder-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 13: Payment Method Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="payment method cash card cheque bank QR credit default account reference font">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">💳</div>
          <h3 class="text-sm font-bold text-slate-900">Payment Method Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Manage store payment options (cheque, card, QR, cash) and match bank links.</p>
        </div>
        <a href="{{ route('system-settings.payment-method-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 14: Tax / Discount Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="tax discount VAT percentage percentage fixed limit overrides POS billing">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">🏷️</div>
          <h3 class="text-sm font-bold text-slate-900">Tax / Discount Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Define sales tax (VAT) structures, fixed discounts, and maximum limits.</p>
        </div>
        <a href="{{ route('system-settings.tax-discount-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 15: Backup Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="backup database files storage frequency restore time gdrive local">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">💾</div>
          <h3 class="text-sm font-bold text-slate-900">Backup Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Set automated schedule backups, store in Google Drive, or run restore points.</p>
        </div>
        <a href="{{ route('system-settings.backup-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 16: System Security --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="system security password strength lock lockout duration session timeout IP restriction">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">🔒</div>
          <h3 class="text-sm font-bold text-slate-900">System Security</h3>
          <p class="text-xs text-slate-500 leading-normal">Enforce password policy, lockout limits, session durations, and IP restrictions.</p>
        </div>
        <a href="{{ route('system-settings.system-security') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

      {{-- Card 17: Audit Log Settings --}}
      <div class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4 hover:border-red-500/30 transition flex flex-col justify-between" data-search-keys="audit log enable retention duration export delete activity tracking">
        <div class="space-y-2">
          <div class="size-11 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-xl">📜</div>
          <h3 class="text-sm font-bold text-slate-900">Audit Log Settings</h3>
          <p class="text-xs text-slate-500 leading-normal">Decide which system operations to track, log data retention policies, and exports.</p>
        </div>
        <a href="{{ route('system-settings.audit-log-settings') }}" class="btn-secondary !min-h-9 !py-1 text-xs w-full text-center">Manage</a>
      </div>

    </div>

    <div id="noResults" class="hidden panel text-center py-10 text-slate-500">
      🔍 No matching settings sections found.
    </div>

  </div>

  <script>
    function filterSettings() {
      const search = document.getElementById('settingsSearch').value.toLowerCase().trim();
      const cards = document.querySelectorAll('#settingsGrid > div');
      let found = 0;

      cards.forEach(card => {
        const searchKeys = card.getAttribute('data-search-keys').toLowerCase();
        const title = card.querySelector('h3').textContent.toLowerCase();
        
        if (title.includes(search) || searchKeys.includes(search)) {
          card.classList.remove('hidden');
          found++;
        } else {
          card.classList.add('hidden');
        }
      });

      document.getElementById('noResults').classList.toggle('hidden', found > 0);
    }
  </script>
</x-layouts::app>
