<x-layouts::app title="System Security Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">System Security</h1>
        <p class="page-subtitle">Manage password strength, login lockouts, session timeouts, and credentials policies</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Access Control:</strong> Secure store access by enforcing password guidelines, session lifetimes, and account lockout conditions for failed password checks.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="security">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Password rules --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Password Policy</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Minimum Password Length</label>
              <input type="number" name="security_min_password_len" value="{{ $settings['security_min_password_len'] ?? 8 }}" min="6" max="32" class="w-full">
            </div>
            <div>
              <label class="label">Force Password Renewal (Days)</label>
              <input type="number" name="security_password_rotation" value="{{ $settings['security_password_rotation'] ?? 90 }}" class="w-full">
              <p class="text-[9px] text-slate-500 mt-1">Enter 0 to disable automatic rotation rules.</p>
            </div>
            
            <div class="sm:col-span-2 grid gap-2 sm:grid-cols-2 border-t border-slate-100 pt-3">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="security_require_caps" value="0">
                <input type="checkbox" name="security_require_caps" value="1" @checked(($settings['security_require_caps'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require uppercase letter (A-Z)</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="security_require_numeric" value="0">
                <input type="checkbox" name="security_require_numeric" value="1" @checked(($settings['security_require_numeric'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require numerical digits (0-9)</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="security_require_symbols" value="0">
                <input type="checkbox" name="security_require_symbols" value="1" @checked(($settings['security_require_symbols'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Require special symbols (e.g. @, #, $)</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Login Lockout --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔒 Bruteforce Login Lockouts</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Max Consecutive Failed Attempts</label>
              <input type="number" name="security_max_failed_attempts" value="{{ $settings['security_max_failed_attempts'] ?? 5 }}" min="3" max="10" class="w-full">
            </div>
            <div>
              <label class="label">Lockout Duration (Minutes)</label>
              <input type="number" name="security_lockout_duration" value="{{ $settings['security_lockout_duration'] ?? 15 }}" min="5" max="120" class="w-full">
            </div>
            <div class="sm:col-span-2 pt-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="security_failed_notify" value="0">
                <input type="checkbox" name="security_failed_notify" value="1" @checked(($settings['security_failed_notify'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Send warning notification to administrator on failed logins</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 3: Sessions --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🕒 Session Lifetime</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Inactivity Session Timeout (Minutes)</label>
              <input type="number" name="security_session_timeout" value="{{ $settings['security_session_timeout'] ?? 120 }}" class="w-full">
            </div>
            <div class="space-y-2 pt-6">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="security_allow_multiple_sessions" value="0">
                <input type="checkbox" name="security_allow_multiple_sessions" value="1" @checked(($settings['security_allow_multiple_sessions'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Allow multiple simultaneous logins per user</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Security Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Options --}}
      <div class="space-y-6">
        
        {{-- Section 4: Public Link Security --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Public Link Security</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="security_track_public_clicks" value="0">
              <input type="checkbox" name="security_track_public_clicks" value="1" @checked(($settings['security_track_public_clicks'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Record customer IP address when public bill links are opened</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="security_disable_expired_links" value="0">
              <input type="checkbox" name="security_disable_expired_links" value="1" @checked(($settings['security_disable_expired_links'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Instantly block expired public bill links</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
