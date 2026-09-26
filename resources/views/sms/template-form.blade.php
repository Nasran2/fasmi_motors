<x-layouts::app title="{{ $template ? 'Edit Template' : 'Add Template' }}"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p>
    <h1 class="page-title">{{ $template ? 'Edit Template' : 'Add SMS Template' }}</h1>
    <p class="page-subtitle">{{ $template ? 'Update the SMS template details below' : 'Create a new reusable SMS message template' }}</p>
  </div>
  <a href="{{ route('sms.templates') }}" class="btn-secondary">← Back to Templates</a>
</div>

<x-flash/>

<div class="grid gap-6 xl:grid-cols-[1fr_320px]">

<form method="POST" action="{{ $template ? route('sms.templates.update',$template) : route('sms.templates.store') }}">
@csrf<x-submission-token/>
@if($template) @method('PUT') @endif

<div class="space-y-5">
  <div class="panel">
    <h2 class="mb-4 font-extrabold">Template Details</h2>
    <div class="grid gap-4 sm:grid-cols-2">

      <div>
        <label class="label" for="name">Template Name <span class="required-star">*</span></label>
        <input type="text" name="name" id="name" value="{{ old('name',$template?->name) }}" placeholder="e.g. Bike Received Notification" required>
        @error('name')<p class="invalid-feedback">{{ $message }}</p>@enderror
      </div>

      <div>
        <label class="label" for="category">Category <span class="required-star">*</span></label>
        <select name="category" id="category" required>
          <option value="">— Select Category —</option>
          @foreach(\App\Models\SmsTemplate::$categories as $cat)
          <option value="{{ $cat }}" @selected(old('category',$template?->category)===$cat)>{{ $cat }}</option>
          @endforeach
        </select>
        @error('category')<p class="invalid-feedback">{{ $message }}</p>@enderror
      </div>

      <div>
        <label class="label" for="event">Event / Hook (optional)</label>
        <input type="text" name="event" id="event" value="{{ old('event',$template?->event) }}" placeholder="e.g. bike_received, job_completed">
        <p class="form-help">Used to trigger this template automatically</p>
      </div>

      <div>
        <label class="label" for="status">Status <span class="required-star">*</span></label>
        <select name="status" id="status" required>
          <option value="active" @selected(old('status',$template?->status??'active')==='active')>Active</option>
          <option value="inactive" @selected(old('status',$template?->status)==='inactive')>Inactive</option>
        </select>
      </div>

      <div class="sm:col-span-2 flex items-center gap-3">
        <input type="hidden" name="auto_send" value="0">
        <input type="checkbox" name="auto_send" id="auto_send" value="1" class="noinput size-5 rounded border-2 border-slate-300" @checked(old('auto_send',$template?->auto_send))>
        <label for="auto_send" class="cursor-pointer text-sm font-bold text-slate-700">Enable Auto Send — send this template automatically when the event triggers</label>
      </div>

    </div>
  </div>

  <div class="panel">
    <h2 class="mb-4 font-extrabold">Message Body</h2>
    <div>
      <label class="label" for="body">Message <span class="required-star">*</span></label>
      <textarea name="body" id="body" rows="6" placeholder="Type your message..." oninput="updateCount()" required>{{ old('body',$template?->body) }}</textarea>
      <div class="mt-2 flex items-center justify-between">
        <p class="form-help"><span id="bodyCount">0</span> characters | <span id="bodySms">0</span> SMS(s)</p>
      </div>
      @error('body')<p class="invalid-feedback">{{ $message }}</p>@enderror
    </div>

    <div class="mt-4">
      <p class="label">Insert Variable</p>
      <div class="flex flex-wrap gap-1.5 mt-1">
        @foreach(\App\Models\SmsTemplate::$variables as $var)
        <button type="button" onclick="insertVar('{{ $var }}')" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-red-50 hover:text-red-600 transition">{{ $var }}</button>
        @endforeach
      </div>
    </div>
  </div>

  <div class="flex gap-3">
    <button type="submit" class="btn-primary">{{ $template ? 'Update Template' : 'Save Template' }}</button>
    <a href="{{ route('sms.templates') }}" class="btn-secondary">Cancel</a>
  </div>
</div>
</form>

{{-- Preview Panel --}}
<aside class="space-y-4">
  <div class="panel">
    <h3 class="font-extrabold mb-3">📱 Live Preview</h3>
    <div class="rounded-2xl bg-slate-900 p-4 min-h-[120px]">
      <p id="preview" class="text-sm text-slate-100 leading-relaxed font-mono whitespace-pre-wrap">Start typing to see preview...</p>
    </div>
  </div>
  <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4">
    <h3 class="font-extrabold text-blue-900 mb-2">Available Variables</h3>
    <ul class="text-xs text-blue-700 space-y-1">
      <li><b>{customer_name}</b> — Customer's full name</li>
      <li><b>{bike_number}</b> — Bike plate number</li>
      <li><b>{bike_model}</b> — Bike model/brand</li>
      <li><b>{job_card_no}</b> — Job card number</li>
      <li><b>{invoice_no}</b> — Invoice number</li>
      <li><b>{amount}</b> — Bill/amount value</li>
      <li><b>{due}</b> — Due amount</li>
      <li><b>{bill_link}</b> — Public bill URL</li>
      <li><b>{business_name}</b> — Your shop name</li>
      <li><b>{phone}</b> — Business phone</li>
    </ul>
  </div>
</aside>

</div>

<script>
function updateCount() {
  const msg = document.getElementById('body').value;
  const len = msg.length;
  document.getElementById('bodyCount').textContent = len;
  document.getElementById('bodySms').textContent = len === 0 ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153));
  document.getElementById('preview').textContent = msg || 'Start typing to see preview...';
}
function insertVar(v) {
  const el = document.getElementById('body');
  const s = el.selectionStart, e = el.selectionEnd;
  el.value = el.value.slice(0,s) + v + el.value.slice(e);
  el.selectionStart = el.selectionEnd = s + v.length;
  el.focus(); updateCount();
}
updateCount();
</script>
</x-layouts::app>
