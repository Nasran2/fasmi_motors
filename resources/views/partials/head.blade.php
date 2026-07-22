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
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    });
</script>

<!-- TomSelect for advanced dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<style>
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
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const initTomSelect = (elements) => {
            if (!elements || elements.length === 0) return;
            Array.from(elements).forEach(el => {
                if(!el.tomselect) {
                    new TomSelect(el, {
                        plugins: ['remove_button'],
                        maxOptions: null,
                    });
                }
            });
        };
        window.initTomSelect = initTomSelect;
        initTomSelect(document.querySelectorAll('.tom-select'));
    });
</script>
