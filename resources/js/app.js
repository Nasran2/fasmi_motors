import './outgoingFunding';
import './jobListSearch';
import './printing';
import './partsSaleEdit';
import './cashier';
import './attendanceImport';
import './liveFilter';
import './workshopLayout';

window.spreadsheetSafeText = (value) => {
    const text = String(value ?? '');

    return /^\s*[=+\-@]/u.test(text) ? `'${text}` : text;
};

window.csvCell = (value) => `"${window.spreadsheetSafeText(value).replace(/"/g, '""')}"`;

// Delegate so newly added payment, product and expense rows behave the same.
const pendingNumericSelections = new WeakMap();
const selectNumericValue = (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.disabled || input.readOnly) return;
    if (input.type !== 'number' && !(input.type === 'text' && input.inputMode === 'decimal')) return;
    if (event.type === 'click' && event.button !== 0) return;
    input.select();
    const request = { value: input.value };
    pendingNumericSelections.set(input, request);
    // Finish after browser focus defaults and reactive input updates have settled.
    requestAnimationFrame(() => {
        if (pendingNumericSelections.get(input) !== request) return;
        pendingNumericSelections.delete(input);
        if (document.activeElement === input && input.value === request.value && !input.disabled && !input.readOnly) input.select();
    });
};

document.addEventListener('focusin', selectNumericValue);
// A pointer click can collapse the selection made on focus; select again afterwards.
document.addEventListener('click', selectNumericValue);
// Never reselect after the user has already started typing, even if the value is still zero.
document.addEventListener('input', (event) => pendingNumericSelections.delete(event.target));

// Prevent wheel stepping without cancelling normal page or panel scrolling.
// Delegation also covers number inputs added later in payment rows and dialogs.
document.addEventListener('wheel', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.type === 'number' && document.activeElement === input) {
        input.blur();
    }
}, { capture: true, passive: true });

window.workshopNotify = (message) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'workshop-dialog';
    const title = document.createElement('h2');
    title.textContent = 'Notification';
    const body = document.createElement('p');
    body.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-primary';
    close.textContent = 'OK';
    close.addEventListener('click', () => dialog.close());
    dialog.append(title, body, close);
    document.body.append(dialog);
    dialog.addEventListener('close', () => dialog.remove(), { once: true });
    dialog.showModal();
};

window.workshopConfirm = (message) => new Promise((resolve) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'workshop-dialog';
    const title = document.createElement('h2');
    title.textContent = 'Confirm Action';
    const body = document.createElement('p');
    body.textContent = message;
    const actions = document.createElement('div');
    actions.className = 'workshop-dialog-actions';
    for (const [label, value] of [['Cancel', false], ['Continue', true]]) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = value ? 'btn-primary' : 'btn-secondary';
        button.textContent = label;
        button.addEventListener('click', () => dialog.close(value ? 'confirmed' : 'cancelled'));
        actions.append(button);
    }
    dialog.append(title, body, actions);
    document.body.append(dialog);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close('cancelled');
        }
    });
    dialog.addEventListener('close', () => {
        resolve(dialog.returnValue === 'confirmed');
        dialog.remove();
    }, { once: true });
    dialog.showModal();
    actions.firstElementChild.focus();
});

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get') return;
    if (form.dataset.submitting === 'true' || form.dataset.confirming === 'true') {
        event.preventDefault();
        return;
    }
    const message = form.dataset.confirm || event.submitter?.dataset.confirm;
    if (message && form.dataset.confirmed !== 'true') {
        event.preventDefault();
        const submitter = event.submitter;
        form.dataset.confirming = 'true';
        const confirmed = await window.workshopConfirm(message);
        delete form.dataset.confirming;
        if (confirmed) {
            form.dataset.confirmed = 'true';
            form.requestSubmit(submitter);
        }
        return;
    }
    if (!event.defaultPrevented) {
        form.dataset.submitting = 'true';
        // Keep the clicked button's name/value available while the browser serializes the form.
        queueMicrotask(() => form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach((button) => { button.disabled = true; }));
    }
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach((form) => {
        delete form.dataset.submitting;
        delete form.dataset.confirmed;
        form.querySelectorAll('button, input[type="submit"]').forEach((button) => { button.disabled = false; });
    });
});
