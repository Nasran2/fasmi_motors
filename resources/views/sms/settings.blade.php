<x-layouts::app title="SMS Provider Settings"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">SMS Provider Settings</h1><p class="page-subtitle">Configure Textit.biz SMS gateway and sender details</p></div>
  <a href="{{ route('sms.dashboard') }}" class="btn-secondary">← Dashboard</a>
</div>

<x-flash/>

<div class="grid gap-6 xl:grid-cols-[1fr_320px]">

<div class="space-y-5">
<form method="POST" action="{{ route('sms.settings.update') }}">
@csrf<x-submission-token/>

  {{-- Provider Details --}}
  <div class="panel">
    <h2 class="mb-5 text-base font-extrabold">🌐 Provider Details</h2>

    <label for="enabled" class="mb-4 flex items-center gap-3 rounded-xl border-2 border-slate-200 p-4 hover:border-red-300 transition cursor-pointer">
      <input type="hidden" name="enabled" value="0">
      <input type="checkbox" name="enabled" id="enabled" value="1" class="noinput size-5 rounded border-2 border-slate-300" @checked($settings->enabled)>
      <div>
        <span class="block text-sm font-extrabold text-slate-800">Enable SMS Gateway</span>
        <p class="text-xs text-slate-500 mt-0.5">When enabled, the system will send real SMS via the configured provider</p>
      </div>
    </label>

    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="label" for="provider_name">Provider Name <span class="required-star">*</span></label>
        <input type="text" name="provider_name" id="provider_name" value="{{ old('provider_name',$settings->provider_name) }}" placeholder="Textit.biz">
      </div>
      <div>
        <label class="label" for="gateway_url">Gateway URL <span class="required-star">*</span></label>
        <input type="text" name="gateway_url" id="gateway_url" value="{{ old('gateway_url',$settings->gateway_url) }}" placeholder="https://textit.biz/sendmsg">
      </div>
      <div>
        <label class="label" for="textit_id">Textit ID (Phone) <span class="required-star">*</span></label>
        <input type="text" name="textit_id" id="textit_id" value="{{ old('textit_id',$settings->textit_id) }}" placeholder="94771234567">
        <p class="form-help">Your registered Textit.biz phone number in international format</p>
      </div>
      <div>
        <label class="label" for="textit_password">Textit Password</label>
        <div class="relative">
          <input type="password" name="textit_password" id="textit_password" placeholder="{{ $settings->textit_id ? '••••••••  (leave blank to keep current)' : 'Enter Textit password' }}" autocomplete="new-password" style="padding-right:48px">
          <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-sm noinput" style="border:none;background:none;min-height:auto;width:auto;padding:4px">👁</button>
        </div>
        <p class="form-help">Password is stored encrypted and never shown</p>
      </div>
      <div>
        <label class="label" for="api_method">API Method <span class="required-star">*</span></label>
        <select name="api_method" id="api_method">
          <option value="GET" @selected(old('api_method',$settings->api_method)==='GET')>GET</option>
          <option value="POST" @selected(old('api_method',$settings->api_method)==='POST')>POST</option>
        </select>
      </div>
      <div>
        <label class="label" for="default_country_code">Default Country Code <span class="required-star">*</span></label>
        <input type="text" name="default_country_code" id="default_country_code" value="{{ old('default_country_code',$settings->default_country_code) }}" placeholder="94" maxlength="5">
        <p class="form-help">Sri Lanka = 94</p>
      </div>
      <div>
        <label class="label" for="sender_id">Sender ID (optional)</label>
        <input type="text" name="sender_id" id="sender_id" value="{{ old('sender_id',$settings->sender_id) }}" placeholder="FasmirMot" maxlength="11">
        <p class="form-help">Max 11 chars. If supported by provider</p>
      </div>
      <div>
        <label class="label" for="success_keyword">Success Response Keyword <span class="required-star">*</span></label>
        <input type="text" name="success_keyword" id="success_keyword" value="{{ old('success_keyword',$settings->success_keyword??'OK') }}" placeholder="OK">
        <p class="form-help">Textit.biz returns "OK" on success</p>
      </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <label for="auto_sms_enabled" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 cursor-pointer hover:bg-slate-50 transition">
        <input type="hidden" name="auto_sms_enabled" value="0">
        <input type="checkbox" name="auto_sms_enabled" id="auto_sms_enabled" value="1" class="noinput size-5 rounded border-2 border-slate-300" @checked($settings->auto_sms_enabled)>
        <span class="text-sm font-bold">Enable Auto SMS</span>
      </label>
      <label for="manual_sms_enabled" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 cursor-pointer hover:bg-slate-50 transition">
        <input type="hidden" name="manual_sms_enabled" value="0">
        <input type="checkbox" name="manual_sms_enabled" id="manual_sms_enabled" value="1" class="noinput size-5 rounded border-2 border-slate-300" @checked($settings->manual_sms_enabled)>
        <span class="text-sm font-bold">Enable Manual SMS</span>
      </label>
    </div>
  </div>

  {{-- API Parameter Mapping --}}
  <div class="panel">
    <h2 class="mb-5 text-base font-extrabold">🔌 API Parameter Mapping</h2>
    <p class="text-sm text-slate-500 mb-4">Map Textit.biz parameter names. Default values work for Textit.biz — only change if using a different provider.</p>
    <div class="grid gap-4 sm:grid-cols-3">
      @foreach(['id_param'=>['ID Parameter','id'],'password_param'=>['Password Parameter','pw'],'to_param'=>['Recipient Parameter','to'],'text_param'=>['Message Parameter','text'],'ref_param'=>['Reference Parameter','ref'],'schedule_param'=>['Schedule Parameter','schd']] as $field=>[$label,$default])
      <div>
        <label class="label" for="{{ $field }}">{{ $label }}</label>
        <input type="text" name="{{ $field }}" id="{{ $field }}" value="{{ old($field,$settings->$field??$default) }}" placeholder="{{ $default }}">
      </div>
      @endforeach
    </div>
  </div>

  {{-- Save --}}
  <div class="flex gap-3">
    <button type="submit" class="btn-primary">💾 Save Settings</button>
    <a href="{{ route('sms.settings') }}" class="btn-secondary">Reset</a>
  </div>

</form>

{{-- Test SMS --}}
<div class="panel">
  <h2 class="mb-5 text-base font-extrabold">🧪 Send Test SMS</h2>
  <form method="POST" action="{{ route('sms.settings.test') }}" class="space-y-4">
  @csrf<x-submission-token/>
    <div>
      <label class="label" for="test_phone">Test Phone Number <span class="required-star">*</span></label>
      <input type="text" name="test_phone" id="test_phone" placeholder="0771234567" required>
    </div>
    <div>
      <label class="label" for="test_message">Test Message <span class="required-star">*</span></label>
      <textarea name="test_message" id="test_message" rows="3" placeholder="This is a test message from Fasmir Motors SMS system." required>This is a test message from Fasmir Motors. If you received this, the SMS gateway is working correctly.</textarea>
    </div>
    <button type="submit" class="btn-primary">📤 Send Test SMS</button>
  </form>
</div>

</div>

{{-- Right Info Panel --}}
<aside class="space-y-5">

  <div class="panel">
    <h3 class="font-extrabold mb-3">📡 Gateway Status</h3>
    <div class="space-y-3 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Provider</span><b>{{ $settings->provider_name }}</b></div>
      <div class="flex justify-between"><span class="text-slate-500">Status</span>
        <span class="{{ $settings->enabled?'badge-sent':'badge-failed' }}">{{ $settings->enabled?'Active':'Disabled' }}</span>
      </div>
      <div class="flex justify-between"><span class="text-slate-500">Method</span><b>{{ $settings->api_method }}</b></div>
      <div class="flex justify-between"><span class="text-slate-500">Country Code</span><b>+{{ $settings->default_country_code }}</b></div>
      <div class="flex justify-between"><span class="text-slate-500">Textit ID</span><b>{{ $settings->textit_id ? substr($settings->textit_id,0,4).'*****' : 'Not set' }}</b></div>
      <div class="flex justify-between"><span class="text-slate-500">Auto SMS</span><b>{{ $settings->auto_sms_enabled?'Enabled':'Disabled' }}</b></div>
      <div class="flex justify-between"><span class="text-slate-500">Manual SMS</span><b>{{ $settings->manual_sms_enabled?'Enabled':'Disabled' }}</b></div>
    </div>
  </div>

  <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4">
    <h3 class="font-extrabold text-blue-900 mb-2">📖 Setup Instructions</h3>
    <ol class="text-xs text-blue-700 space-y-2 leading-relaxed">
      <li><b>1.</b> Register at <a href="https://textit.biz" target="_blank" class="underline">textit.biz</a></li>
      <li><b>2.</b> Get your Textit ID (phone no.) and password</li>
      <li><b>3.</b> Enter them in the form on the left</li>
      <li><b>4.</b> Keep API Method as GET and URL as:<br><code class="bg-blue-100 rounded px-1">https://textit.biz/sendmsg</code></li>
      <li><b>5.</b> Enable the gateway with the toggle</li>
      <li><b>6.</b> Send a test SMS to verify</li>
      <li><b>7.</b> Enable Auto SMS for automatic notifications</li>
    </ol>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <h3 class="font-extrabold mb-2 text-sm">🔗 Textit.biz API Format</h3>
    <p class="text-[10px] font-mono bg-slate-50 rounded-xl p-3 break-all text-slate-600">
      https://textit.biz/sendmsg/?id=YOUR_ID&pw=YOUR_PW&to=94771234567&text=URL+Encoded+Message
    </p>
    <p class="mt-2 text-xs text-slate-500">Response: <b>OK:message_id</b> or <b>ERROR:reason</b></p>
  </div>

</aside>

</div>

<script>
function togglePassword() {
  const f = document.getElementById('textit_password');
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>
</x-layouts::app>
