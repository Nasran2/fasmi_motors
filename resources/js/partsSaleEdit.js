const money = value => `Rs. ${Number(value).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// Capture before the shared submission lock so opening the review does not submit or disable the form.
document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-parts-sale-edit') || form.dataset.reviewApproved === 'true') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.reviewLoading === 'true') return;
    form.dataset.reviewLoading = 'true';
    const dialog = form.querySelector('#sale-edit-review');
    const error = dialog.querySelector('[data-review-error]');
    const body = dialog.querySelector('[data-review-body]');
    const confirm = dialog.querySelector('[data-confirm-sale-review]');
    const save = form.querySelector('[data-save-sale]');
    const removed = dialog.querySelector('[data-removed-items]');
    const offset = dialog.querySelector('[name="refund_action"]');
    removed.replaceChildren();
    offset.checked = false;
    error.hidden = true;
    body.hidden = true;
    confirm.disabled = true;
    form.elements.namedItem('revision_confirmation').value = '';
    save.disabled = true;
    save.textContent = 'Reviewing…';
    try {
        const data = new FormData(form);
        data.delete('_method');
        data.delete('_submission_token');
        data.delete('refund_action');
        const response = await fetch(form.dataset.previewUrl, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session may have expired. Refresh the page and try again.');
        const preview = await response.json();
        if (!response.ok) throw new Error(Object.values(preview.errors ?? {}).flat().join('\n') || preview.message || 'Unable to review these changes.');
        form.elements.namedItem('revision_confirmation').value = preview.confirmation;
        dialog.querySelector('[data-removed-section]').hidden = preview.removed.length === 0;
        const template = form.querySelector('#sale-edit-removed-template');
        for (const item of preview.removed) {
            const row = template.content.cloneNode(true);
            row.querySelector('[data-removed-name]').textContent = item.name;
            row.querySelector('[data-removed-quantity]').textContent = item.quantity;
            row.querySelector('[data-removed-cost]').textContent = money(item.cost);
            const select = row.querySelector('select');
            select.name = `dispositions[${item.item_id}]`;
            select.dataset.cost = item.cost;
            select.querySelector('[value="expense"]').disabled = !preview.can_expense;
            removed.append(row);
        }
        dialog.querySelector('[data-review-total]').textContent = money(preview.new_total);
        dialog.querySelector('[data-review-new-due]').textContent = money(preview.new_due);
        dialog.querySelector('[data-review-due-reduction]').textContent = preview.sale_due_reduction > 0
            ? `${money(preview.sale_due_reduction)} is deducted from this sale’s unpaid balance first.` : '';
        dialog.querySelector('[data-refund-section]').hidden = preview.refund <= 0;
        dialog.querySelector('[data-review-refund]').textContent = money(preview.refund);
        const showOffset = Boolean(preview.customer_id) && preview.other_due > 0 && preview.refund > 0;
        dialog.querySelector('[data-offset-section]').hidden = !showOffset;
        dialog.querySelector('[data-review-credit-row]').hidden = !showOffset;
        offset.disabled = !showOffset || !preview.can_offset_due;
        dialog.querySelector('[data-review-other-due]').textContent = `${preview.customer_name ?? ''} has ${money(preview.other_due)} due on other bills.`;
        dialog.querySelector('[data-offset-label]').textContent = `Apply the refund to ${preview.customer_name ?? 'this customer'}’s outstanding dues first?`;
        const refresh = () => {
            let expense = 0;
            removed.querySelectorAll('select').forEach(select => { if (select.value === 'expense') expense += Number(select.dataset.cost); });
            const credit = offset.checked && !offset.disabled ? Math.min(preview.refund, preview.other_due) : 0;
            dialog.querySelector('[data-review-expense]').textContent = money(expense);
            dialog.querySelector('[data-review-credit]').textContent = money(credit);
            dialog.querySelector('[data-review-payout]').textContent = money(preview.refund - credit);
        };
        dialog.onchange = refresh;
        refresh();
        body.hidden = false;
        confirm.disabled = false;
        if (preview.refund > 0 && !preview.can_refund) {
            error.textContent = 'You do not have permission to refund sale payments. Ask a user with refund permission to save this correction.';
            error.hidden = false;
            confirm.disabled = true;
        }
        confirm.onclick = () => {
            if (!form.reportValidity()) return;
            form.dataset.reviewApproved = 'true';
            dialog.close();
            form.requestSubmit(save);
        };
    } catch (failure) {
        error.textContent = failure.message || 'Unable to review these changes. Please try again.';
        error.hidden = false;
    } finally {
        delete form.dataset.reviewLoading;
        save.disabled = false;
        save.textContent = 'Save Changes';
        if (!dialog.open) dialog.showModal();
    }
}, true);

document.addEventListener('click', event => {
    const close = event.target.closest('[data-close-sale-review]');
    if (close) close.closest('dialog').close();
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-parts-sale-edit]').forEach(form => { delete form.dataset.reviewApproved; delete form.dataset.reviewLoading; });
});
