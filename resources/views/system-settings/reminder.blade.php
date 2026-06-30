<x-layouts::app title="Service Reminder Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Service Reminder Settings</h1>
        <p class="page-subtitle">Configure service schedules, follow-up cycles, and automated templates</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Service Reminders:</strong> This page schedules automated follow-up SMS triggers after customer checkouts, tracking odometer values and days past.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf
      <input type="hidden" name="group" value="reminder">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Reminder Intervals --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Reminder Intervals</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Default Days Interval (Days) <span class="required-star">*</span></label>
              <input type="number" name="default_reminder_days" value="{{ $settings['default_reminder_days'] ?? 60 }}" required class="w-full">
            </div>
            <div>
              <label class="label">Default Next Mileage (KM) <span class="required-star">*</span></label>
              <input type="number" name="default_reminder_km" value="{{ $settings['default_reminder_km'] ?? 3000 }}" required class="w-full">
            </div>
            <div>
              <label class="label">Upcoming Reminder Lead Time (Days Before)</label>
              <input type="number" name="reminder_send_before" value="{{ $settings['reminder_send_before'] ?? 3 }}" class="w-full">
            </div>
            <div>
              <label class="label">Overdue Reminder Delay (Days After)</label>
              <input type="number" name="reminder_send_after" value="{{ $settings['reminder_send_after'] ?? 7 }}" class="w-full">
            </div>
          </div>
        </div>

        {{-- Section 2: Templates --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📝 Reminder Message Templates</h2>
          
          <div class="space-y-4">
            <div>
              <label class="label">Upcoming Reminder Template</label>
              <textarea name="reminder_tmpl_upcoming" rows="2" class="w-full text-xs font-mono">{{ $settings['reminder_tmpl_upcoming'] ?? "Dear Customer, your bike {bike_no} next service is due on {due_date}. Please book your slot. - Fasmir Motors" }}</textarea>
              <p class="text-[9px] text-slate-400 mt-0.5">Use markers: {bike_no}, {due_date}, {customer_name}</p>
            </div>
            <div>
              <label class="label">Overdue Reminder Template</label>
              <textarea name="reminder_tmpl_overdue" rows="2" class="w-full text-xs font-mono">{{ $settings['reminder_tmpl_overdue'] ?? "Alert: Your bike {bike_no} service was due on {due_date} ({days_overdue} days ago). Please visit us soon. - Fasmir Motors" }}</textarea>
              <p class="text-[9px] text-slate-400 mt-0.5">Use markers: {bike_no}, {due_date}, {days_overdue}</p>
            </div>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Reminder Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Control switches --}}
      <div class="space-y-6">
        
        {{-- Controls --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Automation Settings</h3>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="reminder_enabled" value="0">
              <input type="checkbox" name="reminder_enabled" value="1" @checked(($settings['reminder_enabled'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Service Reminders</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="reminder_auto_sms" value="0">
              <input type="checkbox" name="reminder_auto_sms" value="1" @checked(($settings['reminder_auto_sms'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Send reminder SMS automatically</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="reminder_custom_date" value="0">
              <input type="checkbox" name="reminder_custom_date" value="1" @checked(($settings['reminder_custom_date'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow custom reminder date per job</span>
            </label>
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
