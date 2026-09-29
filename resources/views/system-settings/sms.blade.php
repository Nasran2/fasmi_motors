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
                <input type="password" name="textit_password" autocomplete="new-password" placeholder="Leave blank to keep existing password" class="w-full">
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
                  <input type="checkbox" name="sms_gateway_enabled" value="1" @checked(old('sms_gateway_enabled', $settings['sms_gateway_enabled'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                  <span class="text-xs font-bold text-slate-700">Enable SMS sending globally</span>
                </label>
              </div>
            </div>
          </div>

          {{-- Section 2: Automated SMS Event Triggers --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">🔔 Event triggers</h2>
            
            <label class="flex items-center justify-between gap-4 border-b border-slate-200 pb-4 cursor-pointer">
              <span class="font-bold text-sm text-slate-800">Automatic workshop messages</span>
              <input type="hidden" name="sms_auto_enabled" value="0">
              <input type="checkbox" role="switch" name="sms_auto_enabled" value="1" @checked(old('sms_auto_enabled', $settings['sms_auto_enabled'] ?? 0) == 1) class="sms-event-switch">
            </label>
            <div class="divide-y divide-slate-100">
              @foreach($events as $event => $definition)
                <label class="flex items-center justify-between gap-4 py-3 cursor-pointer">
                  <span class="min-w-0">
                    <span class="block text-sm font-semibold text-slate-800">{{ $definition['label'] }}</span>
                    <span class="block text-xs text-slate-500 mt-1">{{ $definition['recipient'] }}</span>
                  </span>
                  <input type="hidden" name="sms_event_{{ $event }}" value="0">
                  <input type="checkbox" role="switch" aria-label="{{ $definition['label'] }} SMS" name="sms_event_{{ $event }}" value="1" @checked(old('sms_event_'.$event, $settings['sms_event_'.$event] ?? $definition['default']) == 1) class="sms-event-switch">
                </label>
              @endforeach
              <label class="flex items-center justify-between gap-4 py-3 cursor-pointer">
                <span class="min-w-0"><span class="block text-sm font-semibold text-slate-800">Scheduled service reminders</span><span class="block text-xs text-slate-500 mt-1">Customer</span></span>
                <input type="hidden" name="sms_reminders_enabled" value="0">
                <input type="checkbox" role="switch" name="sms_reminders_enabled" value="1" @checked(old('sms_reminders_enabled', $settings['reminder_auto_send'] ?? $settings['reminder_auto_sms'] ?? 0) == 1) class="sms-event-switch">
              </label>
            </div>
            <div class="border-t border-slate-200 pt-4 text-sm">
              <div class="font-semibold text-slate-700">Customer invoice address</div>
              <div class="mt-1 break-all text-slate-500">{{ \App\Services\Sms\WorkshopSmsService::publicBaseUrl() }}</div>
              @unless($publicUrlReady)
                <div role="status" class="mt-2 text-amber-700">Invoice SMS is blocked until APP_URL in .env points to your public HTTPS address.</div>
              @endunless
            </div>
          </div>

          {{-- Section 3: Templates --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">📝 Default Message Templates</h2>
            
            <div class="space-y-4">
              @foreach($events as $event => $definition)
                <details class="border-b border-slate-100 pb-3" @if(in_array($event, ['received', 'billed'])) open @endif>
                  <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ $definition['label'] }} <span class="font-normal text-slate-400">({{ $definition['recipient'] }})</span></summary>
                  <label class="sr-only" for="sms-tmpl-{{ $event }}">{{ $definition['label'] }} template</label>
                  <textarea id="sms-tmpl-{{ $event }}" name="sms_tmpl_{{ $event }}" rows="3" required maxlength="1000" class="w-full text-xs font-mono mt-3">{{ old('sms_tmpl_'.$event, $settings['sms_tmpl_'.$event] ?? $definition['template']) }}</textarea>
                  @php preg_match_all('/\{[a-z_]+\}/', $definition['template'], $markers); @endphp
                  <div class="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-xs text-slate-500">@foreach(array_unique($markers[0]) as $marker)<code>{{ $marker }}</code>@endforeach</div>
                </details>
              @endforeach
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
        <a href="{{ route('sms.failed') }}" class="text-sm font-semibold text-red-600">Failed messages</a>
      </div>
      
      <div class="overflow-hidden rounded-xl border border-slate-200">
        <div class="overflow-x-auto">
          <table class="data-table w-full text-left text-sm">
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>Phone Number</th>
                <th>Event / Recipient</th>
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
                  <td class="text-xs text-slate-600"><span class="block">{{ $events[explode(':', $log->message_type ?? '')[1] ?? '']['label'] ?? $log->message_type }}</span><span class="block text-slate-400">{{ $log->recipient_name ?: ucfirst($log->recipient_type ?? '') }}</span></td>
                  <td class="max-w-[300px] truncate" title="{{ $log->message }}">{{ $log->message }}</td>
                  <td class="whitespace-nowrap">
                    @if(strtolower($log->status) === 'success' || strtolower($log->status) === 'sent')
                      <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700">Provider accepted</span>
                    @elseif(strtolower($log->status) === 'failed')
                      <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">Failed</span>
                    @else
                      <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">{{ $log->status }}</span>
                    @endif
                  </td>
                  <td class="max-w-[200px] truncate text-xs text-slate-500" title="{{ $log->failure_reason ?: $log->api_response }}">
                    {{ $log->failure_reason ?: $log->api_response ?: 'No response data' }}
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="6" class="py-8 text-center text-slate-500">No SMS logs recorded yet.</td>
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
  <style>
    .sms-event-switch { appearance: none; flex: 0 0 42px; width: 42px; height: 24px; padding: 0; border: 0; border-radius: 999px; background: #94a3b8; cursor: pointer; position: relative; transition: background .15s; }
    .sms-event-switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: white; transition: transform .15s; }
    .sms-event-switch:checked { background: #059669; }
    .sms-event-switch:checked::after { transform: translateX(18px); }
    .sms-event-switch:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
  </style>
</x-layouts::app>
