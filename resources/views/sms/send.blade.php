<x-layouts::app title="Send SMS"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">Send SMS</h1><p class="page-subtitle">Send manual SMS to customers, technicians, or custom numbers</p></div>
  <a href="{{ route('sms.dashboard') }}" class="btn-secondary">← Back to Dashboard</a>
</div>

<x-flash/>

<div class="grid gap-6 xl:grid-cols-[1fr_340px]">

{{-- Main Form --}}
<form method="POST" action="{{ route('sms.send.post') }}" id="smsForm">
@csrf
<div class="space-y-5">

  {{-- Recipient Section --}}
  <div class="panel">
    <h2 class="mb-4 text-base font-extrabold text-slate-900">📱 Recipient Details</h2>
    <div class="grid gap-4 sm:grid-cols-2">

      <div>
        <label class="label" for="recipient_type">Recipient Type <span class="required-star">*</span></label>
        <select id="recipient_type" name="recipient_type" onchange="switchRecipientType(this.value)">
          <option value="custom">Custom Number</option>
          <option value="customer">Customer</option>
          <option value="technician">Technician</option>
        </select>
      </div>

      <div id="searchWrapper" class="hidden">
        <label class="label" for="recipientSearch">Search Recipient</label>
        <input type="text" id="recipientSearch" placeholder="Type name or phone..." autocomplete="off" class="noinput field">
        <div id="searchResults" class="mt-1 hidden rounded-xl border border-slate-200 bg-white shadow-lg"></div>
      </div>

      <div class="sm:col-span-2">
        <label class="label" for="phone">Phone Number <span class="required-star">*</span></label>
        <input type="text" name="phone" id="phone" placeholder="e.g. 0771234567 or 94771234567" required maxlength="20">
        <p class="form-help">Will be auto-converted to Sri Lankan international format (94xxxxxxxxx)</p>
      </div>

      <div>
        <label class="label" for="recipient_name">Recipient Name</label>
        <input type="text" name="recipient_name" id="recipient_name" placeholder="Customer or technician name">
      </div>

      <div>
        <label class="label" for="reference_no">Reference (optional)</label>
        <input type="text" name="reference_no" id="reference_no" placeholder="Job No, Invoice No..." maxlength="15">
        <p class="form-help">Max 15 characters — sent to provider as reference</p>
      </div>

      <input type="hidden" name="module" value="manual">
      <input type="hidden" name="message_type" id="message_type_hidden" value="Manual SMS">
    </div>
  </div>

  {{-- Message Section --}}
  <div class="panel">
    <h2 class="mb-4 text-base font-extrabold text-slate-900">💬 Message Details</h2>
    <div class="space-y-4">

      <div>
        <label class="label" for="template_select">Use SMS Template (optional)</label>
        <select id="template_select" onchange="loadTemplate(this.value)">
          <option value="">— Select a template —</option>
          @foreach($templates->groupBy('category') as $cat => $temps)
          <optgroup label="{{ $cat }}">
            @foreach($temps as $t)
            <option value="{{ $t->id }}" data-body="{{ $t->body }}">{{ $t->name }}</option>
            @endforeach
          </optgroup>
          @endforeach
        </select>
      </div>

      <div>
        <label class="label" for="message">Message Body <span class="required-star">*</span></label>
        <textarea name="message" id="message" rows="5" placeholder="Type your SMS message here..." oninput="updateCounter()" required></textarea>
        <div class="mt-2 flex items-center justify-between">
          <p class="form-help"><span id="charCount">0</span> / 918 characters &nbsp;|&nbsp; <span id="smsCount">0</span> SMS(s)</p>
          <div class="flex flex-wrap gap-1">
            @foreach(['{customer_name}','{bike_number}','{job_card_no}','{invoice_no}','{amount}','{bill_link}','{business_name}'] as $var)
            <button type="button" onclick="insertVar('{{ $var }}')" class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600 hover:bg-red-50 hover:text-red-600">{{ $var }}</button>
            @endforeach
          </div>
        </div>
      </div>

    </div>
  </div>

  {{-- Actions --}}
  <div class="flex flex-wrap gap-3">
    <button type="submit" class="btn-primary">✉ Send SMS Now</button>
    <button type="button" onclick="previewSms()" class="btn-secondary">👁 Preview</button>
    <button type="reset" onclick="resetForm()" class="btn-secondary">✕ Clear</button>
  </div>

</div>
</form>

{{-- Right Panel --}}
<aside class="space-y-5">

  {{-- Live Preview --}}
  <div class="panel">
    <h3 class="font-extrabold text-slate-900 mb-3">📱 SMS Preview</h3>
    <div class="rounded-2xl bg-slate-900 p-4 min-h-[120px]">
      <p id="smsPreview" class="text-sm text-slate-100 leading-relaxed whitespace-pre-wrap font-mono">Your message will appear here...</p>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
      <div class="rounded-xl bg-slate-50 p-3 text-center">
        <div class="text-lg font-black text-slate-900" id="previewChars">0</div>
        <div class="font-bold text-slate-500">Characters</div>
      </div>
      <div class="rounded-xl bg-slate-50 p-3 text-center">
        <div class="text-lg font-black text-slate-900" id="previewSms">0</div>
        <div class="font-bold text-slate-500">SMS Count</div>
      </div>
    </div>
  </div>

  {{-- Tips --}}
  <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
    <h3 class="font-extrabold text-blue-900 mb-2">💡 Sending Tips</h3>
    <ul class="text-xs text-blue-700 space-y-1.5 leading-relaxed">
      <li>• 1 SMS = 160 characters (standard)</li>
      <li>• 2 SMS = 153×2 = 306 chars</li>
      <li>• 3 SMS = 153×3 = 459 chars</li>
      <li>• Numbers starting with 0 auto-convert to 94</li>
      <li>• Use templates for consistent messages</li>
      <li>• All sends are saved to SMS Logs</li>
    </ul>
  </div>

  {{-- Recent Sends --}}
  <div class="panel">
    <h3 class="font-extrabold text-slate-900 mb-3">Recent Sends</h3>
    @php $recentLogs = \App\Models\SmsLog::orderByDesc('created_at')->limit(5)->get(); @endphp
    @forelse($recentLogs as $rl)
    <div class="flex items-center justify-between py-2 border-b border-slate-50 last:border-0">
      <div>
        <p class="text-xs font-bold">{{ $rl->recipient_name ?? ($rl->phone_number ?? $rl->phone) }}</p>
        <p class="text-[10px] text-slate-400">{{ $rl->created_at->diffForHumans() }}</p>
      </div>
      <span class="{{ $rl->status==='sent'||$rl->status==='delivered'?'badge-sent':($rl->status==='failed'?'badge-failed':'badge-pending') }}">{{ ucfirst($rl->status) }}</span>
    </div>
    @empty
    <p class="text-xs text-slate-400 py-2">No recent sends.</p>
    @endforelse
  </div>

</aside>
</div>

</div>

<script>
const templateData = @json($templates->keyBy('id')->map(fn($t) => $t->body));

function loadTemplate(id) {
  if (!id || !templateData[id]) return;
  document.getElementById('message').value = templateData[id];
  updateCounter();
}

function updateCounter() {
  const msg = document.getElementById('message').value;
  const len = msg.length;
  const smsCount = len === 0 ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153));
  document.getElementById('charCount').textContent = len;
  document.getElementById('smsCount').textContent = smsCount;
  document.getElementById('previewChars').textContent = len;
  document.getElementById('previewSms').textContent = smsCount;
  document.getElementById('smsPreview').textContent = msg || 'Your message will appear here...';
}

function insertVar(v) {
  const el = document.getElementById('message');
  const start = el.selectionStart;
  const end = el.selectionEnd;
  el.value = el.value.slice(0, start) + v + el.value.slice(end);
  el.selectionStart = el.selectionEnd = start + v.length;
  el.focus();
  updateCounter();
}

function previewSms() {
  const msg = document.getElementById('message').value;
  if (!msg.trim()) { alert('Please type a message first.'); return; }
  alert('SMS Preview:\n\n' + msg + '\n\n(' + msg.length + ' characters)');
}

function resetForm() {
  document.getElementById('charCount').textContent = '0';
  document.getElementById('smsCount').textContent = '0';
  document.getElementById('previewChars').textContent = '0';
  document.getElementById('previewSms').textContent = '0';
  document.getElementById('smsPreview').textContent = 'Your message will appear here...';
  document.getElementById('template_select').value = '';
}

function switchRecipientType(type) {
  const wrapper = document.getElementById('searchWrapper');
  if (type === 'customer' || type === 'technician') {
    wrapper.classList.remove('hidden');
  } else {
    wrapper.classList.add('hidden');
  }
}

// AJAX search
let searchTimeout;
document.getElementById('recipientSearch').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  const q = this.value.trim();
  const type = document.getElementById('recipient_type').value;
  if (q.length < 2) { document.getElementById('searchResults').classList.add('hidden'); return; }
  searchTimeout = setTimeout(() => {
    fetch(`/sms/search-recipients?type=${type}&q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        const box = document.getElementById('searchResults');
        if (!data.length) { box.classList.add('hidden'); return; }
        box.innerHTML = data.map(r => `<div class="cursor-pointer px-4 py-2.5 text-sm hover:bg-red-50 hover:text-red-700" onclick="selectRecipient('${r.name}','${r.phone}')">${r.name} — ${r.phone}</div>`).join('');
        box.classList.remove('hidden');
      });
  }, 300);
});

function selectRecipient(name, phone) {
  document.getElementById('recipient_name').value = name;
  document.getElementById('phone').value = phone;
  document.getElementById('searchResults').classList.add('hidden');
  document.getElementById('recipientSearch').value = name;
}
</script>
</x-layouts::app>
