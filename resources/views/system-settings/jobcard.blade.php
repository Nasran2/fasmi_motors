<x-layouts::app title="Job Card Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Job Card Settings</h1>
        <p class="page-subtitle">Configure repair job workflows, auto technician status, and reminder links</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Job Card Options:</strong> This page controls numbering prefixes, job status pathways (In Progress, Waiting for Parts, Completed), auto-SMS triggers, and service mileage rules.
    </div>

    <x-flash />

    <form method="POST" action="{{ route('system-settings.save') }}" class="grid gap-6 lg:grid-cols-3">
      @csrf<x-submission-token/>
      <input type="hidden" name="group" value="jobcard">

      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Section 1: Job Numbering --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔢 Job Card Numbering</h2>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Job Card Prefix</label>
              <input type="text" name="job_card_prefix" value="{{ $settings['job_card_prefix'] ?? 'JC' }}" class="w-full">
            </div>
            <div>
              <label class="label">Job Card Start Serial Offset</label>
              <input type="number" name="job_card_start_no" value="{{ $settings['job_card_start_no'] ?? 1000 }}" class="w-full">
            </div>
            <div class="sm:col-span-2 space-y-2 pt-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="reset_job_card_yearly" value="0">
                <input type="checkbox" name="reset_job_card_yearly" value="1" @checked(($settings['reset_job_card_yearly'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Reset serial counter yearly</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="show_date_jobcard_no" value="0">
                <input type="checkbox" name="show_date_jobcard_no" value="1" @checked(($settings['show_date_jobcard_no'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Include Year/Month in Job Card Number (e.g. JC-2606-1001)</span>
              </label>
            </div>
          </div>
        </div>

        {{-- Section 2: Technician Allocation Workflow --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Assignment Workflow</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_auto_assigned_status" value="0">
              <input type="checkbox" name="jc_auto_assigned_status" value="1" @checked(($settings['jc_auto_assigned_status'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Auto change status to "Assigned" when technician is chosen</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_multiple_technicians" value="0">
              <input type="checkbox" name="jc_multiple_technicians" value="1" @checked(($settings['jc_multiple_technicians'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow multiple technicians assigned to a single job card</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_allow_tech_change" value="0">
              <input type="checkbox" name="jc_allow_tech_change" value="1" @checked(($settings['jc_allow_tech_change'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Allow changing assigned technician during work</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_require_tech_reason" value="0">
              <input type="checkbox" name="jc_require_tech_reason" value="1" @checked(($settings['jc_require_tech_reason'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require justification note when changing technicians</span>
            </label>
          </div>
        </div>

        {{-- Section 3: Mileage Tracking --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🏍️ Service Mileage Preferences</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_ask_current_km" value="0">
              <input type="checkbox" name="jc_ask_current_km" value="1" @checked(($settings['jc_ask_current_km'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Require current KM reading during intake</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_ask_next_km" value="0">
              <input type="checkbox" name="jc_ask_next_km" value="1" @checked(($settings['jc_ask_next_km'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Prompt for next service KM reading on completion</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="jc_auto_create_reminder" value="0">
              <input type="checkbox" name="jc_auto_create_reminder" value="1" @checked(($settings['jc_auto_create_reminder'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Automatically set next service reminders on invoicing</span>
            </label>
          </div>
        </div>

        {{-- Buttons --}}
        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Job Card Settings</button>
          <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
        </div>

      </div>

      {{-- Right Columns: Job Statuses Config --}}
      <div class="space-y-6">
        
        {{-- Job Statuses List --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Active Work Statuses</h3>
          
          <div class="space-y-2">
            @php
              $statuses = ['Received', 'Assigned', 'In Progress', 'Waiting for Parts', 'On Hold', 'Repair Ready', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
            @endphp
            @foreach($statuses as $status)
            <div class="flex items-center justify-between border border-slate-100 rounded-lg p-2 bg-slate-50 text-xs">
              <span class="font-bold text-slate-800">{{ $status }}</span>
              <span class="text-[9px] uppercase tracking-wider bg-green-100 text-green-700 font-bold px-1.5 py-0.5 rounded border border-green-200">Default</span>
            </div>
            @endforeach
          </div>
        </div>

      </div>

    </form>
  </div>
</x-layouts::app>
