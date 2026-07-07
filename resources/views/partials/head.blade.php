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
@fluxAppearance

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
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('input[type="date"]:not(.flatpickr-input)')) {
                            newDateInputs.push(node);
                        }
                        if (node.matches && node.matches('input[type="month"]:not(.flatpickr-input)')) {
                            newMonthInputs.push(node);
                        }
                        
                        const dateChildren = node.querySelectorAll ? node.querySelectorAll('input[type="date"]:not(.flatpickr-input)') : [];
                        dateChildren.forEach(child => newDateInputs.push(child));
                        
                        const monthChildren = node.querySelectorAll ? node.querySelectorAll('input[type="month"]:not(.flatpickr-input)') : [];
                        monthChildren.forEach(child => newMonthInputs.push(child));
                    }
                });
            });
            if (newDateInputs.length > 0) initFlatpickr(newDateInputs);
            if (newMonthInputs.length > 0) initMonthPickr(newMonthInputs);
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    });
</script>
