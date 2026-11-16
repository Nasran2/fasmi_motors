const desktopSidebar = window.matchMedia('(min-width: 90rem) and (min-height: 52.001rem) and (hover: hover) and (pointer: fine)');

function syncSidebar() {
    const sidebar = document.querySelector('.workshop-shell > #workshop-sidebar');
    if (!sidebar) return;

    if (desktopSidebar.matches) {
        if (sidebar.matches(':modal')) sidebar.close();
        sidebar.setAttribute('open', '');
        sidebar.setAttribute('role', 'complementary');
    } else if (!sidebar.matches(':modal')) {
        sidebar.removeAttribute('open');
        sidebar.removeAttribute('role');
    }
}

desktopSidebar.addEventListener('change', syncSidebar);
document.addEventListener('livewire:navigated', syncSidebar);
syncSidebar();

const recommendedPages = ['dashboard.overview', 'sales-billing.parts-sales', 'bike-intake.intake-list', 'job-cards.all-job-cards', 'products-stock.product-list', 'reports.sales-report'];

function renderMenuSearch(dialog) {
    const search = dialog.querySelector('input[type="search"]').value.trim().toLowerCase();
    const terms = search.split(/\s+/).filter(Boolean);
    const menuLinks = [...document.querySelectorAll('#workshop-sidebar [data-menu-child]')];
    if (!terms.length) {
        const ranks = new Map(recommendedPages.map((key, index) => [key, index]));
        const rank = link => ranks.get(link.dataset.menuKey) ?? recommendedPages.length;
        menuLinks.sort((a, b) => rank(a) - rank(b));
    }

    const matches = menuLinks.filter(link => terms.every(term => link.dataset.search.includes(term)));
    updateSearchResults(dialog, matches.slice(0, search ? 15 : 6).map(link => ({
        title: link.textContent.trim(),
        detail: link.closest('[data-menu-group]').dataset.title,
        url: link.href,
    })), matches.length ? (search ? `${matches.length} matching pages` : 'Recommended pages') : 'No matching pages');
    dialog.querySelector('button[type="submit"]').disabled = !matches.length;
}

function updateSearchResults(dialog, entries, summary) {
    const items = entries.map(({ title, detail, url }) => {
        const anchor = document.createElement('a');
        anchor.className = 'workshop-search-result';
        anchor.href = url;
        anchor.textContent = title;
        if (detail) {
            const subtitle = document.createElement('span');
            subtitle.className = 'text-xs font-medium text-slate-500';
            subtitle.textContent = detail;
            anchor.append(subtitle);
        }
        const item = document.createElement('li');
        item.append(anchor);
        return item;
    });

    dialog.querySelector('[data-search-results]').replaceChildren(...items);
    dialog.querySelector('[data-search-summary]').textContent = summary;
}

const intakeSearches = new WeakMap();

function queueIntakeSearch(dialog, delay = 0) {
    const previous = intakeSearches.get(dialog);
    clearTimeout(previous?.timer);
    previous?.request.abort();

    const query = dialog.querySelector('input[type="search"]').value.trim();
    const url = new URL(dialog.dataset.intakeSearch);
    url.searchParams.set('suggestions', '1');
    url.searchParams.set('q', query);

    const request = new AbortController();
    updateSearchResults(dialog, [], 'Loading suggestions…');
    const timer = setTimeout(async () => {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error('Could not load intake suggestions');
            const entries = await response.json();
            if (!request.signal.aborted && dialog.open) {
                updateSearchResults(dialog, entries, entries.length ? (query ? 'Matching intakes' : 'Recent intakes') : 'No matching intakes');
            }
        } catch {
            if (!request.signal.aborted && dialog.open) updateSearchResults(dialog, [], 'Suggestions unavailable. Use Search to see the full results.');
        }
    }, delay);
    intakeSearches.set(dialog, { request, timer });
}

document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) return;

    for (const menu of document.querySelectorAll('[data-header-more][open]')) {
        if (!menu.contains(event.target)) menu.open = false;
    }

    const opener = event.target.closest('[data-open-dialog]');
    if (opener) {
        const dialog = document.getElementById(opener.dataset.openDialog);
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        if (dialog.hasAttribute('data-menu-search-dialog')) {
            opener.closest('.workshop-sidebar:modal')?.close();
            renderMenuSearch(dialog);
        }
        dialog.showModal();
        if (dialog.hasAttribute('data-intake-search')) queueIntakeSearch(dialog);
        return;
    }

    const closer = event.target.closest('[data-close-dialog]');
    if (closer) {
        closer.closest('dialog')?.close();
        return;
    }

    if (event.target.matches('.workshop-sidebar:modal, .workshop-search-dialog:modal')) {
        const { left, right, top, bottom } = event.target.getBoundingClientRect();
        if (event.clientX < left || event.clientX > right || event.clientY < top || event.clientY > bottom) event.target.close();
    }
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const search = event.target.closest?.('.workshop-search-dialog:modal');
    if (search) {
        event.preventDefault();
        search.close();
        return;
    }
    if (document.querySelector('dialog:modal')) return;
    const more = document.querySelector('[data-header-more][open]');
    if (!more) return;
    more.open = false;
    more.querySelector('summary').focus();
});

document.addEventListener('input', event => {
    const dialog = event.target.closest?.('dialog');
    if (dialog?.hasAttribute('data-menu-search-dialog')) renderMenuSearch(dialog);
    if (dialog?.hasAttribute('data-intake-search')) queueIntakeSearch(dialog, 200);
});

document.addEventListener('submit', event => {
    if (!event.target.matches('[data-menu-search-form]')) return;
    event.preventDefault();
    event.target.closest('dialog').querySelector('[data-search-results] a')?.click();
});
