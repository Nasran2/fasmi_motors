window.spreadsheetSafeText = (value) => {
    const text = String(value ?? '');

    return /^\s*[=+\-@]/u.test(text) ? `'${text}` : text;
};

window.csvCell = (value) => `"${window.spreadsheetSafeText(value).replace(/"/g, '""')}"`;

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
