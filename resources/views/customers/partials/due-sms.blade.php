<dialog id="due-sms-dialog" aria-labelledby="due-sms-title" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[85vh] overflow-y-auto rounded-lg border-0 bg-white p-6 shadow-2xl backdrop:bg-slate-950/55">
  <div class="flex items-center justify-between gap-4">
    <h2 id="due-sms-title" class="text-lg font-bold">Due Reminder SMS</h2>
    <button type="button" data-due-sms-close title="Close" aria-label="Close SMS reminder" class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100"><flux:icon.x-mark class="size-4"/></button>
  </div>
  <div class="mt-4" data-due-sms-editor>
    <label for="due-sms-template" class="label">Message template</label>
    <textarea id="due-sms-template" rows="4" maxlength="1000" class="field">{{ \App\Models\Setting::valueFor('sms_tmpl_due', \App\Services\Sms\CustomerDueSmsService::TEMPLATE) }}</textarea>
    <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500"><code>{customer_name}</code><code>{due_amount}</code><code>{invoice_numbers}</code><code>{business_name}</code></div>
  </div>
  <p role="status" aria-live="polite" data-due-sms-status class="mt-4 text-sm text-slate-600"></p>
  <div data-due-sms-preview class="mt-4 divide-y divide-slate-200"></div>
  <div class="mt-5 flex flex-wrap justify-end gap-2">
    <a href="{{ route('sms.logs') }}" class="btn-secondary">SMS Logs</a>
    <button type="button" data-due-sms-preview-button class="btn-secondary">Preview Messages</button>
    <button type="button" data-due-sms-send class="btn-primary" hidden disabled>Send SMS</button>
  </div>
</dialog>
<style>
  #due-sms-dialog [data-due-sms-send][hidden] { display: none; }
  #due-sms-dialog button:disabled { opacity: .5; cursor: wait; }
</style>
<script>
(() => {
  const dialog = document.getElementById('due-sms-dialog');
  const template = dialog.querySelector('#due-sms-template');
  const previewButton = dialog.querySelector('[data-due-sms-preview-button]');
  const sendButton = dialog.querySelector('[data-due-sms-send]');
  const closeButton = dialog.querySelector('[data-due-sms-close]');
  const status = dialog.querySelector('[data-due-sms-status]');
  const previewList = dialog.querySelector('[data-due-sms-preview]');
  const checks = [...document.querySelectorAll('[data-due-sms-select]')];
  const selectAll = document.querySelector('[data-due-sms-all]');
  const bulkButton = document.querySelector('[data-due-sms-bulk]');
  let selectedIds = [], batch = null, busy = false, finished = false;
  const selected = () => checks.filter(input => input.checked).map(input => Number(input.value));
  const updateSelection = () => {
    const count = selected().length;
    document.querySelector('[data-due-sms-selection]').textContent = `${count} invoice${count === 1 ? '' : 's'} selected`;
    bulkButton.disabled = count === 0;
    selectAll.checked = count > 0 && count === checks.length;
    selectAll.indeterminate = count > 0 && count < checks.length;
  };
  checks.forEach(input => input.addEventListener('change', updateSelection));
  selectAll.addEventListener('change', () => { checks.forEach(input => input.checked = selectAll.checked); updateSelection(); });
  const setBusy = value => {
    busy = value;
    template.disabled = value;
    previewButton.disabled = value;
    closeButton.disabled = value;
    sendButton.hidden = !batch?.recipients.some(recipient => !recipient.error);
    sendButton.disabled = value || finished || !batch?.recipients.some(recipient => !recipient.error);
  };
  const open = ids => {
    selectedIds = ids; batch = null; finished = false;
    previewList.replaceChildren(); status.textContent = `${ids.length} selected invoice${ids.length === 1 ? '' : 's'}.`;
    sendButton.textContent = 'Send SMS'; setBusy(false); dialog.showModal();
    prepare();
  };
  document.querySelectorAll('[data-due-sms-one]').forEach(button => button.addEventListener('click', () => open([Number(button.dataset.dueSmsOne)])));
  bulkButton.addEventListener('click', () => open(selected()));
  closeButton.addEventListener('click', () => dialog.close());
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  template.addEventListener('input', () => {
    batch = null; finished = false; previewList.replaceChildren();
    sendButton.disabled = true; sendButton.hidden = true;
    status.textContent = 'Message changed.';
    previewButton.textContent = 'Update Preview';
  });
  const post = async (url, data) => {
    const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token())}, body: JSON.stringify(data)});
    const result = await response.json();
    if (!response.ok) {
      const error = new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'SMS request failed.');
      error.confirmed = true;
      throw error;
    }
    return result;
  };
  const render = () => {
    previewList.replaceChildren();
    for (const recipient of batch.recipients) {
      const row = document.createElement('div'); row.className = 'py-3';
      const heading = document.createElement('p'); heading.className = 'text-sm font-semibold';
      heading.textContent = `${recipient.name} | ${recipient.phone || 'No phone'} | Rs. ${recipient.due}`;
      const message = document.createElement('p'); message.className = 'mt-2 whitespace-pre-wrap break-words text-sm text-slate-600'; message.textContent = recipient.message;
      const result = document.createElement('p'); result.className = 'mt-2 text-xs font-semibold text-slate-600';
      result.dataset.recipientKey = recipient.key; result.textContent = recipient.error || 'Ready';
      row.append(heading, message, result); previewList.append(row);
    }
  };
  const prepare = async () => {
    if (busy) return;
    batch = null; finished = false; sendButton.textContent = 'Send SMS'; setBusy(true); status.textContent = 'Preparing messages...'; previewList.replaceChildren();
    try {
      batch = await post(@js(route('customers.dues.sms.preview')), {invoice_ids: selectedIds, template: template.value});
      render();
      previewButton.textContent = 'Refresh Preview';
      const count = batch.recipients.filter(recipient => !recipient.error).length;
      status.textContent = `${count} customer message${count === 1 ? '' : 's'} ready. ${batch.recipients.length - count} skipped.`;
    } catch (error) { status.textContent = error.message; }
    finally { setBusy(false); }
  };
  previewButton.addEventListener('click', prepare);
  sendButton.addEventListener('click', async () => {
    if (busy || !batch || finished) return;
    setBusy(true);
    let sent = 0, failed = 0, skipped = 0, unconfirmed = 0;
    for (const recipient of batch.recipients) {
      const row = [...previewList.querySelectorAll('[data-recipient-key]')].find(element => element.dataset.recipientKey === recipient.key);
      if (recipient.error) { skipped++; continue; }
      status.textContent = `Sending ${sent + failed + skipped + unconfirmed + 1} of ${batch.recipients.length}...`;
      row.textContent = 'Sending...';
      try {
        const result = await post(@js(route('customers.dues.sms.send')), {token: batch.token, recipient_key: recipient.key});
        if (result.success) { sent++; row.textContent = 'Provider accepted'; }
        else { result.status === 'skipped' ? skipped++ : failed++; row.textContent = result.error || 'Failed'; }
      } catch (error) {
        if (error.confirmed) { failed++; row.textContent = error.message; }
        else { unconfirmed++; row.textContent = 'Result unconfirmed. Retry to check this same request without duplicating it.'; }
      }
    }
    finished = unconfirmed === 0;
    sendButton.textContent = unconfirmed ? 'Retry Unconfirmed' : 'Send SMS';
    status.textContent = `${sent} accepted by provider | ${failed} failed | ${skipped} skipped${unconfirmed ? ` | ${unconfirmed} unconfirmed` : ''}`;
    setBusy(false);
  });
})();
</script>
