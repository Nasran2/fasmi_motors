function initializeJobListSearch() {
    const form = document.querySelector('[data-live-job-filter]');
    if (!form || form.dataset.liveReady) return;
    form.dataset.liveReady = 'true';
    const results = document.querySelector('[data-job-results]');
    const message = document.querySelector('[data-job-search-status]');
    const bike = form.elements.namedItem('bike_id');
    const search = form.elements.namedItem('bike_search');
    const status = form.elements.namedItem('status');
    const clear = form.querySelector('[data-clear-job-filters]');
    let searchMode = Boolean(search.value.trim());
    let activeRequest = null;
    let revision = 0;

    const filterUrl = () => {
        const url = new URL(form.action, window.location.href);
        for (const [key, value] of new FormData(form)) {
            const text = String(value).trim();
            if (text && !(searchMode && key === 'bike_id')) url.searchParams.set(key, text);
        }
        return url;
    };

    async function refresh() {
        const currentRevision = ++revision;
        activeRequest?.abort();
        activeRequest = new AbortController();
        const url = filterUrl();
        clear.hidden = !url.search;
        results.setAttribute('aria-busy', 'true');
        message.textContent = 'Updating job cards…';
        try {
            const response = await fetch(url, {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
                signal: activeRequest.signal,
            });
            if (!response.ok) throw new Error('Unable to load job cards');
            const data = await response.json();
            if (currentRevision !== revision) return;
            if (typeof data.html !== 'string' || !Number.isFinite(data.total)) throw new Error('Invalid job list');
            results.innerHTML = data.html;
            window.history.replaceState(null, '', url);
            const query = url.searchParams.get('bike_search');
            message.textContent = `${data.total} job card${data.total === 1 ? '' : 's'}${query ? ` matching “${query}”` : ''}`;
        } catch (error) {
            if (error.name !== 'AbortError' && currentRevision === revision) {
                message.textContent = 'Could not refresh the list. Click Filter to try again.';
            }
        } finally {
            if (currentRevision === revision) results.setAttribute('aria-busy', 'false');
        }
    }

    bike.addEventListener('bike-search-input', event => {
        search.value = event.detail.query;
        searchMode = true;
        refresh();
    });
    bike.addEventListener('change', () => {
        search.value = '';
        searchMode = false;
        refresh();
    });
    status.addEventListener('change', refresh);
    form.addEventListener('submit', event => {
        event.preventDefault();
        refresh();
    });
    if (searchMode && bike.tomselect) {
        bike.tomselect.setTextboxValue(search.value);
        bike.tomselect.refreshOptions(false);
    }
}

document.addEventListener('DOMContentLoaded', initializeJobListSearch, {once: true});
if (document.readyState === 'complete') initializeJobListSearch();
