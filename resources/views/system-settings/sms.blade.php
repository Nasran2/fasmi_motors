<x-layouts::app title="SMS Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">SMS Settings</h1>
        <p class="page-subtitle">Configure Textit SMS Gateway, automation triggers, and default text templates</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>SMS Gateway:</strong> This page links the workshop database to the Textit.biz SMS gateway, enabling automated bike intake receipts, ready alerts, and reminders to be dispatched.
    </div>

    <x-flash />

    {{-- Forms & Actions --}}
    <div class="grid gap-6 lg:grid-cols-3">
      
      {{-- Left Columns: Settings Forms --}}
      <div class="lg:col-span-2 space-y-6">
        <form method="POST" action="{{ route('system-settings.save') }}" class="space-y-6">
          @csrf<x-submission-token/>
          <input type="hidden" name="group" value="sms">

          {{-- Section 1: Gateway Credentials --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Gateway Credentials</h2>
            
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="label">SMS Provider</label>
                <input type="text" name="sms_provider" value="{{ $settings['sms_provider'] ?? 'Textit.biz' }}" class="w-full">
              </div>
              <div>
                <label class="label">Gateway URL</label>
                <input type="text" name="sms_gateway_url" value="{{ $settings['sms_gateway_url'] ?? 'https://textit.biz/sendmsg' }}" class="w-full">
              </div>
              <div>
                <label class="label">Textit Username / ID <span class="required-star">*</span></label>
                <input type="text" name="textit_id" value="{{ $settings['textit_id'] ?? '' }}" required class="w-full">
              </div>
              <div>
                <label class="label">Textit Password</label>
                <input type="password" name="textit_password" value="{{ $settings['textit_password'] ?? '' }}" placeholder="Enter password to change" class="w-full">
              </div>
              <div>
                <label class="label">API HTTP Method</label>
                <select name="sms_api_method" class="w-full">
                  <option value="GET" @selected(($settings['sms_api_method'] ?? 'GET') === 'GET')>GET Request</option>
                  <option value="POST" @selected(($settings['sms_api_method'] ?? '') === 'POST')>POST Request</option>
                </select>
              </div>
              <div>
                <label class="label">Default Country Code</label>
                <input type="text" name="sms_country_code" value="{{ $settings['sms_country_code'] ?? '94' }}" class="w-full">
              </div>
              <div>
                <label class="label">Sender ID</label>
                <input type="text" name="sms_sender_id" value="{{ $settings['sms_sender_id'] ?? '' }}" placeholder="e.g. FasmirMot" class="w-full">
              </div>
              <div class="pt-6">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="hidden" name="sms_gateway_enabled" value="0">
                  <input type="checkbox" name="sms_gateway_enabled" value="1" @checked(($settings['sms_gateway_enabled'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                  <span class="text-xs font-bold text-slate-700">Enable SMS sending globally</span>
                </label>
              </div>
            </div>
          </div>

          {{-- Section 2: Automated SMS Event Triggers --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔔 Event triggers</h2>
            
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="sms_event_received" value="0">
                <input type="checkbox" name="sms_event_received" value="1" @checked(($settings['sms_event_received'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Bike Received Receipt SMS</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="sms_event_assigned" value="0">
                <input type="checkbox" name="sms_event_assigned" value="1" @checked(($settings['sms_event_assigned'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Technician Assigned SMS</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="sms_event_completed" value="0">
                <input type="checkbox" name="sms_event_completed" value="1" @checked(($settings['sms_event_completed'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Repair Job Completed SMS</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="sms_event_billed" value="0">
                <input type="checkbox" name="sms_event_billed" value="1" @checked(($settings['sms_event_billed'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Bill & Invoice Generated SMS</span>
              </label>
            </div>
          </div>

          {{-- Section 3: Templates --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📝 Default Message Templates</h2>
            
            <div class="space-y-4">
              <div>
                <label class="label">Bike Received Template</label>
                <textarea name="sms_tmpl_received" rows="2" class="w-full text-xs font-mono">{{ $settings['sms_tmpl_received'] ?? "Dear Customer, we received your bike {bike_no} for service. Job Card: {job_no}. - Fasmir Motors" }}</textarea>
                <p class="text-[9px] text-slate-400 mt-0.5">Use markers: {bike_no}, {job_no}, {customer_name}</p>
              </div>
              <div>
                <label class="label">Job Completed Template</label>
                <textarea name="sms_tmpl_completed" rows="2" class="w-full text-xs font-mono">{{ $settings['sms_tmpl_completed'] ?? "Dear Customer, your bike {bike_no} service is completed and ready for pickup. Total Bill: {bill_amount}. - Fasmir Motors" }}</textarea>
                <p class="text-[9px] text-slate-400 mt-0.5">Use markers: {bike_no}, {bill_amount}, {customer_name}</p>
              </div>
            </div>
          </div>

          {{-- Buttons --}}
          <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save SMS Settings</button>
            <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
          </div>

        </form>
      </div>

      {{-- Right Columns: Test Gateway Actions --}}
      <div>
        
        {{-- Section 4: Test SMS Utility --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Test SMS Gateway</h3>
          
          <form method="POST" action="{{ route('system-settings.test-sms') }}" class="space-y-4">
            @csrf<x-submission-token/>
            <div>
              <label class="label">Recipient Phone Number</label>
              <input type="text" name="phone" required placeholder="e.g. 0771234567" class="w-full">
            </div>
            <div>
              <label class="label">Test Message Body</label>
              <textarea name="message" required rows="3" placeholder="Type a test text..." class="w-full"></textarea>
            </div>
            <button type="submit" class="btn-secondary w-full text-blue-600 hover:bg-blue-50">Send Test SMS</button>
          </form>
        </div>

      </div>

    </div>

    {{-- SMS Logs Section --}}
    <div class="panel mt-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-black text-slate-800">📋 SMS Delivery Logs</h2>
        <span class="text-xs text-slate-500">Showing most recent messages</span>
      </div>
      
      <div class="overflow-hidden rounded-xl border border-slate-200">
        <div class="overflow-x-auto">
          <table class="data-table w-full text-left text-sm">
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>Phone Number</th>
                <th>Message Snippet</th>
                <th>Status</th>
                <th>Response / Reason</th>
              </tr>
            </thead>
            <tbody>
              @forelse($smsLogs as $log)
                <tr>
                  <td class="whitespace-nowrap font-medium">{{ $log->created_at->format('d M Y, h:i A') }}</td>
                  <td class="whitespace-nowrap text-slate-600">{{ $log->phone }}</td>
                  <td class="max-w-[300px] truncate" title="{{ $log->message }}">{{ $log->message }}</td>
                  <td class="whitespace-nowrap">
                    @if(strtolower($log->status) === 'success' || strtolower($log->status) === 'sent')
                      <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700">Success</span>
                    @elseif(strtolower($log->status) === 'failed')
                      <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">Failed</span>
                    @else
                      <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">{{ $log->status }}</span>
                    @endif
                  </td>
                  <td class="max-w-[200px] truncate text-xs text-slate-500" title="{{ $log->api_response }}">
                    {{ $log->api_response ?: 'No response data' }}
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="py-8 text-center text-slate-500">No SMS logs recorded yet.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @if($smsLogs->hasPages())
          <div class="border-t border-slate-100 p-4">
            {{ $smsLogs->links() }}
          </div>
        @endif
      </div>
    </div>

  </div>
</x-layouts::app>
