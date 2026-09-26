<x-layouts::app title="Audit Log Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Audit Log Settings</h1>
        <p class="page-subtitle">Configure system operations logging and audit retention durations</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Security Auditing:</strong> Choose which database transactions and login attempts are logged, and define retention limits before logs are purged.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="audit">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Tracked Events --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Logging Scopes</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_logins" value="0">
              <input type="checkbox" name="audit_track_logins" value="1" @checked(($settings['audit_track_logins'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track user logins / logouts</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_users" value="0">
              <input type="checkbox" name="audit_track_users" value="1" @checked(($settings['audit_track_users'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track user credentials modifications</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_permissions" value="0">
              <input type="checkbox" name="audit_track_permissions" value="1" @checked(($settings['audit_track_permissions'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track role and permission checklist modifications</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_finance" value="0">
              <input type="checkbox" name="audit_track_finance" value="1" @checked(($settings['audit_track_finance'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track financial ledgers and daily closings</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_stock" value="0">
              <input type="checkbox" name="audit_track_stock" value="1" @checked(($settings['audit_track_stock'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track stock adjustment transactions</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="audit_track_invoices" value="0">
              <input type="checkbox" name="audit_track_invoices" value="1" @checked(($settings['audit_track_invoices'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Track invoice voiding and cancellations</span>
            </label>
          </div>
        </div>

        {{-- Section 2: Retention --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📅 Log Retention Policies</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Retention Duration</label>
              <select name="audit_retention" class="w-full">
                <option value="Forever" @selected(($settings['audit_retention'] ?? 'Forever') === 'Forever')>Keep Logs Forever</option>
                <option value="6 Months" @selected(($settings['audit_retention'] ?? '') === '6 Months')>6 Months</option>
                <option value="1 Year" @selected(($settings['audit_retention'] ?? '') === '1 Year')>1 Year</option>
                <option value="2 Years" @selected(($settings['audit_retention'] ?? '') === '2 Years')>2 Years</option>
              </select>
            </div>
            <div class="pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="audit_superadmin_delete" value="0">
                <input type="checkbox" name="audit_superadmin_delete" value="1" @checked(($settings['audit_superadmin_delete'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700 text-red-600">Only Super Admins can delete old logs</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Audit Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Log Extraction --}}
      <div>
        
        {{-- Section 3: Extra Actions --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Log Operations</h3>
          
          <div class="space-y-2">
            <a href="{{ route('user-management.activity-logs.index') }}" class="btn-secondary w-full text-center">
              📜 View Current Activity Logs
            </a>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
