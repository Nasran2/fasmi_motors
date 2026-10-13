<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    localStorage.removeItem('flux.appearance');
    localStorage.removeItem('theme');
    document.documentElement.classList.remove('dark');
</script>

<!-- Flatpickr for Date Inputs -->
<link rel="stylesheet" href="https://npmcdn.com/flatpickr/dist/themes/airbnb.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
<style>
    .flatpickr-calendar {
        z-index: 9999999 !important;
        font-family: inherit;
        background: #fff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    }
    .flatpickr-day {
        color: #0f172a !important;
    }
    .flatpickr-day.selected {
        background: #dc2626 !important; /* Red 600 */
        border-color: #dc2626 !important;
        color: #fff !important;
    }
    .flatpickr-months {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    .flatpickr-current-month .flatpickr-monthDropdown-months {
        appearance: menulist;
        background: transparent;
    }
    .flatpickr-day:hover {
        background: #f1f5f9 !important;
    }
    .flatpickr-wrapper {
        display: block !important;
        width: 100% !important;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const initFlatpickr = (elements) => {
            if (!elements || elements.length === 0) return;
            Array.from(elements).forEach(el => {
                if (!el.getAttribute('placeholder')) {
                    el.setAttribute('placeholder', 'YYYY-MM-DD');
                }
                flatpickr(el, {
                    allowInput: true,
                    dateFormat: "Y-m-d",
                    static: true
                });
            });
        };

        // Initialize existing
        initFlatpickr(document.querySelectorAll('input[type="date"]:not(.flatpickr-input)'));

        // Month Picker Initialize
        const initMonthPickr = (elements) => {
            if (!elements || elements.length === 0) return;
            Array.from(elements).forEach(el => {
                if (!el.getAttribute('placeholder')) {
                    el.setAttribute('placeholder', 'YYYY-MM');
                }
                flatpickr(el, {
                    allowInput: true,
                    plugins: [
                        new monthSelectPlugin({
                          shorthand: true, // defaults to false
                          dateFormat: "Y-m", // defaults to "F Y"
                          altFormat: "F Y", // defaults to "F Y"
                        })
                    ],
                    static: true
                });
            });
        };
        initMonthPickr(document.querySelectorAll('input[type="month"]:not(.flatpickr-input)'));

        // Observe for dynamic additions (modals, livewire updates)
        const observer = new MutationObserver((mutations) => {
            let newDateInputs = [];
            let newMonthInputs = [];
            let newTomSelects = [];
            let newSearchDropdowns = [];
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('input[type="date"]:not(.flatpickr-input)')) {
                            newDateInputs.push(node);
                        }
                        if (node.matches && node.matches('input[type="month"]:not(.flatpickr-input)')) {
                            newMonthInputs.push(node);
                        }
                        if (node.matches && node.matches('.tom-select')) {
                            newTomSelects.push(node);
                        }
                        if (node.matches && node.matches('input[list]')) newSearchDropdowns.push(node);
                        node.querySelectorAll?.('input[list]').forEach(input => newSearchDropdowns.push(input));
                        
                        const dateChildren = node.querySelectorAll ? node.querySelectorAll('input[type="date"]:not(.flatpickr-input)') : [];
                        dateChildren.forEach(child => newDateInputs.push(child));
                        
                        const monthChildren = node.querySelectorAll ? node.querySelectorAll('input[type="month"]:not(.flatpickr-input)') : [];
                        monthChildren.forEach(child => newMonthInputs.push(child));
                        
                        const tomChildren = node.querySelectorAll ? node.querySelectorAll('.tom-select') : [];
                        tomChildren.forEach(child => newTomSelects.push(child));
                    }
                });
            });
            if (newDateInputs.length > 0) initFlatpickr(newDateInputs);
            if (newMonthInputs.length > 0) initMonthPickr(newMonthInputs);
            if (newTomSelects.length > 0 && typeof initTomSelect === 'function') initTomSelect(newTomSelects);
            if (newSearchDropdowns.length > 0 && window.initSearchDropdowns) window.initSearchDropdowns(newSearchDropdowns);
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    });
</script>

<!-- TomSelect for advanced dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<style>
    .ts-wrapper.field {
        border: none !important;
        padding: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }
    .ts-control {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 0.75rem !important;
        padding: 0.625rem 0.875rem !important;
        font-size: 0.875rem !important;
        background-color: #ffffff !important;
        min-height: 44px !important;
        display: flex;
        align-items: center;
        box-shadow: none !important;
    }
    .ts-control.focus {
        border-color: #ed142b !important;
        box-shadow: 0 0 0 3px rgba(237, 20, 43, 0.15) !important;
    }
    .ts-dropdown {
        border-radius: 0.5rem !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1) !important;
        margin-top: 4px !important;
    }
    .ts-dropdown .option {
        padding: 8px 12px !important;
        font-size: 0.875rem;
    }
    .ts-dropdown .option.active {
        background-color: #f8fafc !important;
        color: #0f172a !important;
    }
    .ts-dropdown .option.selected {
        background-color: #fee2e2 !important;
        color: #dc2626 !important;
        font-weight: 600;
    }
    .ts-control .item {
        background-color: #f1f5f9 !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 6px !important;
        color: #0f172a !important;
        padding: 2px 6px !important;
    }
    .search-dropdown { width:100%; min-width:0; font-size:14px; }
    .search-dropdown .ts-control { padding:10px 36px 10px 14px !important; border-radius:8px !important; position:relative; flex-wrap:nowrap; cursor:pointer; }
    .search-dropdown .ts-control::after { content:''; position:absolute; right:15px; top:17px; width:7px; height:7px; border-right:2px solid #94a3b8; border-bottom:2px solid #94a3b8; transform:rotate(45deg); pointer-events:none; }
    .search-dropdown.dropdown-active .ts-control::after { transform:rotate(225deg); top:20px; }
    .search-dropdown.focus .ts-control { border-color:#ed142b !important; box-shadow:0 0 0 3px #ed142b15 !important; }
    .search-dropdown .ts-control .item { padding:0 !important; background:transparent !important; border:0 !important; border-radius:0 !important; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
    .search-dropdown .ts-control input { min-height:0 !important; height:auto !important; padding:0 !important; border:0 !important; background:transparent !important; box-shadow:none !important; border-radius:0 !important; font-size:14px !important; }
    .search-filter.has-query .ts-control .item { display:none; }
    .search-dropdown-menu { z-index:10000; background:#fff; color:#1e293b; border-radius:8px !important; margin:0 !important; }
    .search-dropdown-menu .ts-dropdown-content { max-height:240px; overscroll-behavior:contain; }
    .search-dropdown-menu .option { padding:11px 14px !important; color:#334155; overflow-wrap:anywhere; line-height:1.45; border-bottom:1px solid #f1f5f9; }
    .search-dropdown-menu .option.active { background:#eff6ff !important; color:#1d4ed8 !important; }
    .search-dropdown-menu .option.selected { background:#fff1f2 !important; color:#be123c !important; }
    .search-dropdown-menu .no-results { padding:14px; color:#64748b; }
    .searchable-product-field .relative:has(.search-dropdown)>svg { display:none; }
    input.search-dropdown-source { display:none !important; }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.initSearchDropdowns = (elements) => {
            if (typeof TomSelect === 'undefined') return;
            Array.from(elements).forEach(input => {
                if (input.tomselect) return;
                const list = input.list;
                if (!list) return;
                const options = Array.from(list.options, (option, index) => ({value:option.value, text:option.label || option.value, sortOrder:Number(option.dataset.sortOrder ?? index)}));
                const originalClasses = Array.from(input.classList);
                const initialValue = input.value;
                input.removeAttribute('list');
                const positionMenu = (dropdown) => {
                    if (!dropdown.isOpen) return;
                    const bounds = dropdown.control.getBoundingClientRect();
                    const below = window.innerHeight - bounds.bottom - 12;
                    const above = bounds.top - 12;
                    const openAbove = below < 160 && above > below;
                    Object.assign(dropdown.dropdown.style, {
                        position:'fixed',
                        left:Math.max(8, Math.min(bounds.left, window.innerWidth - bounds.width - 8))+'px',
                        width:Math.min(bounds.width, window.innerWidth - 16)+'px',
                        top:openAbove ? 'auto' : bounds.bottom + 6 + 'px',
                        bottom:openAbove ? window.innerHeight - bounds.top + 6 + 'px' : 'auto',
                    });
                    dropdown.dropdown_content.style.maxHeight = Math.max(60, Math.min(240, openAbove ? above : below))+'px';
                };
                const control = new TomSelect(input, {
                    options,
                    ...(input.hasAttribute('data-product-search') ? {sortField:[{field:'sortOrder', direction:'asc'}, {field:'$score', direction:'desc'}]} : {}),
                    items: initialValue ? [initialValue] : [],
                    maxItems:1,
                    maxOptions:null,
                    create:false,
                    closeAfterSelect:true,
                    selectOnTab:false,
                    copyClassesToDropdown:false,
                    dropdownParent:input.closest('dialog') || document.body,
                    onDropdownOpen() {
                        positionMenu(this);
                    },
                    onChange() {
                        input.dispatchEvent(new Event('input', {bubbles:true}));
                    },
                });
                control.wrapper.classList.remove(...originalClasses);
                control.wrapper.classList.add('search-dropdown');
                control.dropdown.classList.add('search-dropdown-menu');
                const reposition = () => positionMenu(control);
                window.addEventListener('resize', reposition);
                document.addEventListener('scroll', reposition, true);
                control.on('destroy', () => {
                    window.removeEventListener('resize', reposition);
                    document.removeEventListener('scroll', reposition, true);
                });
                control.control_input.classList.add('noinput');
                input.classList.add('search-dropdown-source');
                const label = input.getAttribute('aria-label') || input.closest('label')?.querySelector('.label')?.textContent || input.closest('.searchable-product-field')?.querySelector('.label')?.textContent;
                if (label) control.control_input.setAttribute('aria-label', label.trim());
                input.addEventListener('invalid', event => {
                    event.preventDefault();
                    control.focus();
                    control.control_input.setCustomValidity(input.validationMessage);
                    control.control_input.reportValidity();
                });
                control.on('change', () => control.control_input.setCustomValidity(''));
                const sync = () => {
                    if (!input.isConnected) return;
                    if (input.disabled && !control.isDisabled) control.disable();
                    if (!input.disabled && control.isDisabled) control.enable();
                    if (String(control.getValue()) !== input.value) control.setValue(input.value, true);
                };
                new MutationObserver(sync).observe(input, {attributes:true, attributeFilter:['disabled']});
                input.closest('form')?.addEventListener('reset', () => setTimeout(sync, 0));
                input.closest('form')?.addEventListener('click', () => setTimeout(sync, 0));
            });
        };
        window.initSearchDropdowns(document.querySelectorAll('input[list]'));
        const initTomSelect = (elements) => {
            if (!elements || elements.length === 0) return;
            Array.from(elements).forEach(el => {
                if(!el.tomselect) {
                    const searchableFilter = el.hasAttribute('data-search-filter');
                    const control = new TomSelect(el, {
                        plugins: searchableFilter ? [] : ['remove_button'],
                        maxOptions: null,
                        ...(searchableFilter ? {
                            allowEmptyOption: true,
                            create: false,
                            placeholder: el.dataset.placeholder,
                            wrapperClass: 'ts-wrapper search-dropdown search-filter',
                            dropdownClass: 'ts-dropdown search-dropdown-menu',
                            copyClassesToDropdown: false,
                            onType(query) { this.wrapper.classList.toggle('has-query', Boolean(query)); },
                            onChange() { this.wrapper.classList.remove('has-query'); },
                            onBlur() { this.wrapper.classList.remove('has-query'); },
                        } : {}),
                    });
                    if (searchableFilter) {
                        control.control_input.classList.add('noinput');
                        control.control_input.setAttribute('aria-label', el.getAttribute('aria-label'));
                    }
                }
            });
        };
        window.initTomSelect = initTomSelect;
        initTomSelect(document.querySelectorAll('.tom-select'));
    });
</script>
