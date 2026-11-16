/**
 * Global Live Filter System
 * Provides instantaneous 0ms client-side row filtering as the user types each word,
 * combined with debounced automatic AJAX fetch to query the full database without
 * page reload or losing input focus.
 */

const DEBOUNCE_DELAY = 220;
const activeControllers = new WeakMap();
const debounceTimers = new WeakMap();

function isFilterForm(form) {
    if (!form || !(form instanceof HTMLFormElement)) return false;
    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method !== 'GET') return false;
    if (form.matches('[data-no-live-filter], [data-live-job-filter]')) return false;
    if (form.closest('dialog, [data-flux-modal]')) return false;

    // Check if form has a search input or filter field
    const hasSearchOrFilter = form.querySelector(
        'input[name="q"], input[name="search"], input[type="search"], input[placeholder*="search" i], select[name="filter"], select[name="status"], select[name="category"], select[name="account_id"], select[name="payment_method"]'
    );
    return Boolean(hasSearchOrFilter);
}

function findTargetTableWrap(form) {
    // 1. Check if form explicitly points to a target via data-target
    const customTarget = form.getAttribute('data-target');
    if (customTarget) {
        const el = document.querySelector(customTarget);
        if (el) return el;
    }

    // 2. Look for table-wrap, overflow-x-auto, or table in subsequent sibling elements
    let next = form.nextElementSibling;
    while (next) {
        if (next.classList?.contains('table-wrap') || next.classList?.contains('overflow-x-auto') || next.tagName === 'TABLE') {
            return next;
        }
        const inner = next.querySelector?.('.table-wrap, .overflow-x-auto, table');
        if (inner) return inner;
        next = next.nextElementSibling;
    }

    // 3. Look in parent element container
    const parent = form.parentElement;
    if (parent) {
        const wrap = parent.querySelector('.table-wrap, .overflow-x-auto, table');
        if (wrap && wrap !== form) return wrap;
    }

    // 4. Panel or article container (e.g. workspace record pages)
    const container = form.closest('article, .panel, main, [data-testid]');
    if (container) {
        const wrap = container.querySelector('.table-wrap, .overflow-x-auto, table');
        if (wrap) return wrap;
    }

    // 5. Fallback to document
    return document.querySelector('.table-wrap, .overflow-x-auto, table');
}

function clientFilterRows(table, query) {
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (!rows.length) return;

    const words = query.toLowerCase().trim().split(/\s+/).filter(Boolean);

    let matchCount = 0;
    rows.forEach(row => {
        // Remove previous temporary empty notice if present
        if (row.hasAttribute('data-live-empty-row')) {
            row.remove();
            return;
        }
        // Handle server-rendered empty row with colspan
        if (row.querySelector('td[colspan]')) {
            row.hidden = words.length > 0;
            return;
        }

        if (words.length === 0) {
            row.hidden = false;
            matchCount++;
            return;
        }

        const rawText = (row.textContent || '').toLowerCase();
        const cleanText = rawText.replace(/[\s\-_]/g, '');

        const matches = words.every(word => {
            if (rawText.includes(word)) return true;
            const cleanWord = word.replace(/[\s\-_]/g, '');
            if (cleanWord && cleanText.includes(cleanWord)) return true;
            return false;
        });

        row.hidden = !matches;
        if (matches) matchCount++;
    });

    // If words were entered but no rows match on current page, show instant notice
    if (words.length > 0 && matchCount === 0) {
        const firstRow = rows.find(r => !r.hasAttribute('data-live-empty-row'));
        const colCount = firstRow?.children.length || 6;
        const emptyRow = document.createElement('tr');
        emptyRow.setAttribute('data-live-empty-row', 'true');
        emptyRow.innerHTML = `<td colspan="${colCount}" class="py-12 text-center text-xs font-semibold text-slate-400">Searching all records…</td>`;
        tbody.appendChild(emptyRow);
    }
}

async function fetchLiveFilter(form, overrideUrl = null) {
    const tableWrap = findTargetTableWrap(form);

    // Cancel previous fetch if still running
    const existingCtrl = activeControllers.get(form);
    if (existingCtrl) {
        existingCtrl.abort();
    }
    const controller = new AbortController();
    activeControllers.set(form, controller);

    let url;
    if (overrideUrl) {
        url = new URL(overrideUrl, window.location.origin);
    } else {
        const action = form.getAttribute('action') || window.location.pathname;
        url = new URL(action, window.location.origin);
        const formData = new FormData(form);
        const params = new URLSearchParams();
        for (const [key, val] of formData.entries()) {
            const trimmed = String(val).trim();
            if (trimmed !== '') {
                params.set(key, trimmed);
            }
        }
        url.search = params.toString();
    }

    if (tableWrap) {
        tableWrap.style.transition = 'opacity 0.15s ease';
        tableWrap.style.opacity = '0.65';
    }

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            },
            signal: controller.signal
        });

        if (!response.ok) throw new Error(`Live filter HTTP ${response.status}`);

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        applyNewResults(form, tableWrap, doc);
        window.history.replaceState(null, '', url.toString());
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.warn('[LiveFilter] Request error:', err);
        }
    } finally {
        if (tableWrap) {
            tableWrap.style.opacity = '';
        }
    }
}

function applyNewResults(form, currentWrap, doc) {
    if (!currentWrap || !doc) return;

    // Find the matching target in doc
    let newWrap = null;
    if (currentWrap.id) {
        newWrap = doc.getElementById(currentWrap.id);
    }
    if (!newWrap && currentWrap.classList?.contains('table-wrap')) {
        newWrap = doc.querySelector('.table-wrap');
    }
    if (!newWrap && currentWrap.classList?.contains('overflow-x-auto')) {
        newWrap = doc.querySelector('.overflow-x-auto');
    }
    if (!newWrap) {
        newWrap = doc.querySelector('.table-wrap, .overflow-x-auto, table');
    }

    if (newWrap) {
        // If both have tables, swap tbody and table content cleanly
        const currentTable = currentWrap.tagName === 'TABLE' ? currentWrap : currentWrap.querySelector('table');
        const newTable = newWrap.tagName === 'TABLE' ? newWrap : newWrap.querySelector('table');

        if (currentTable && newTable) {
            const currentTbody = currentTable.querySelector('tbody');
            const newTbody = newTable.querySelector('tbody');
            if (currentTbody && newTbody) {
                currentTbody.innerHTML = newTbody.innerHTML;
            } else {
                currentTable.innerHTML = newTable.innerHTML;
            }

            // Check if there's pagination nested inside currentWrap / newWrap (e.g. .table-wrap .border-t)
            const currentInnerPag = currentWrap.querySelector('nav[role="navigation"], nav[aria-label*="Pagination"], .pagination');
            const newInnerPag = newWrap.querySelector('nav[role="navigation"], nav[aria-label*="Pagination"], .pagination');
            if (currentInnerPag && newInnerPag) {
                currentInnerPag.innerHTML = newInnerPag.innerHTML;
            } else if (currentInnerPag && !newInnerPag) {
                currentInnerPag.closest('.border-t, div')?.remove();
            } else if (!currentInnerPag && newInnerPag) {
                const wrapper = newInnerPag.closest('.border-t, div') || newInnerPag;
                currentWrap.appendChild(wrapper.cloneNode(true));
            }
        } else {
            currentWrap.innerHTML = newWrap.innerHTML;
        }
    }

    // Also update any standalone pagination outside currentWrap
    const currentPagination = document.querySelector('nav[role="navigation"], nav[aria-label*="Pagination"], .pagination');
    const newPagination = doc.querySelector('nav[role="navigation"], nav[aria-label*="Pagination"], .pagination');
    if (currentPagination && newPagination && !currentWrap.contains(currentPagination)) {
        currentPagination.replaceWith(newPagination);
    } else if (currentPagination && !newPagination && !currentWrap.contains(currentPagination)) {
        currentPagination.remove();
    } else if (!currentPagination && newPagination && currentWrap && !currentWrap.contains(newPagination)) {
        currentWrap.after(newPagination);
    }

    // Update matching record count text if present (e.g. "X matching records")
    const countRegex = /matching\s+(?:workshop\s+|job\s+)?records?|matching\s+job\s+card/i;
    const currentCounts = Array.from(document.querySelectorAll('p, span, div, h2, h3')).filter(el => countRegex.test(el.textContent) && el.children.length === 0);
    const newCounts = Array.from(doc.querySelectorAll('p, span, div, h2, h3')).filter(el => countRegex.test(el.textContent) && el.children.length === 0);
    currentCounts.forEach((el, i) => {
        if (newCounts[i]) el.textContent = newCounts[i].textContent;
    });
}

// Global live search input listener
document.addEventListener('input', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.disabled || input.readOnly) return;
    if (input.matches('[data-no-live-filter], [data-menu-search], [data-board-search]')) return;
    if (['checkbox', 'radio', 'file', 'hidden', 'button', 'submit'].includes(input.type)) return;

    const form = input.form;
    if (!form || !isFilterForm(form)) return;

    // 1. Instant client-side row filtering (0ms)
    const tableWrap = findTargetTableWrap(form);
    const table = tableWrap?.tagName === 'TABLE' ? tableWrap : tableWrap?.querySelector('table');
    if (table) {
        clientFilterRows(table, input.value);
    }

    // 2. Debounced server-side database query
    clearTimeout(debounceTimers.get(form));
    const timer = setTimeout(() => {
        fetchLiveFilter(form);
    }, DEBOUNCE_DELAY);
    debounceTimers.set(form, timer);
});

// Select dropdown and date filters
document.addEventListener('change', event => {
    const el = event.target;
    if (!(el instanceof HTMLSelectElement || (el instanceof HTMLInputElement && el.type === 'date'))) return;
    const form = el.form;
    if (!form || !isFilterForm(form)) return;

    clearTimeout(debounceTimers.get(form));
    fetchLiveFilter(form);
});

// Intercept form submit (Enter key or Filter button)
document.addEventListener('submit', event => {
    const form = event.target;
    if (!isFilterForm(form)) return;

    event.preventDefault();
    clearTimeout(debounceTimers.get(form));
    fetchLiveFilter(form);
});

// Intercept pagination clicks for instant AJAX paging
document.addEventListener('click', event => {
    const link = event.target.closest('nav[role="navigation"] a, nav[aria-label*="Pagination"] a, .table-wrap nav a, .pagination a');
    if (!link || !link.href) return;

    // Find nearest form
    let form = link.closest('article, .panel, main, [data-testid]')?.querySelector('form');
    if (!form || !isFilterForm(form)) {
        const tableWrap = link.closest('.table-wrap, .overflow-x-auto') || link.parentElement?.previousElementSibling;
        let prev = tableWrap?.previousElementSibling;
        while (prev) {
            if (prev.tagName === 'FORM' && isFilterForm(prev)) {
                form = prev;
                break;
            }
            const innerForm = prev.querySelector?.('form');
            if (innerForm && isFilterForm(innerForm)) {
                form = innerForm;
                break;
            }
            prev = prev.previousElementSibling;
        }
    }
    if (!form || !isFilterForm(form)) {
        form = Array.from(document.querySelectorAll('form')).find(f => isFilterForm(f));
    }

    if (form && isFilterForm(form)) {
        event.preventDefault();
        fetchLiveFilter(form, link.href);
        const wrap = findTargetTableWrap(form);
        if (wrap) {
            wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
});

// Intercept Clear / Reset links
document.addEventListener('click', event => {
    const clearLink = event.target.closest('form a.btn-secondary, form [data-clear-filter]');
    if (!clearLink) return;
    const form = clearLink.closest('form');
    if (!form || !isFilterForm(form)) return;

    const text = clearLink.textContent.trim().toLowerCase();
    if (text === 'clear' || text === 'reset') {
        event.preventDefault();
        form.reset();
        Array.from(form.elements).forEach(el => {
            if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search' || !el.type)) el.value = '';
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
        });
        fetchLiveFilter(form, clearLink.href);
    }
});

// Handle browser back and forward history
window.addEventListener('popstate', () => {
    const forms = Array.from(document.querySelectorAll('form')).filter(f => isFilterForm(f));
    if (forms.length === 1) {
        const params = new URLSearchParams(window.location.search);
        const form = forms[0];
        Array.from(form.elements).forEach(el => {
            if (el.name && params.has(el.name)) {
                el.value = params.get(el.name);
            } else if (el.name && !params.has(el.name) && (el.type === 'text' || el.type === 'search')) {
                el.value = '';
            }
        });
        fetchLiveFilter(form, window.location.href);
    }
});
