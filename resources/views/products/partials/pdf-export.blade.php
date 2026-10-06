<script>
window.productPdfExport = (options) => {
    let controller;
    return {
        ...options, from: 1, to: Math.min(5, options.pages), busy: false, error: '', message: '', downloaded: false,
        selectedColumns: [...options.columns],
        get perPage() { return this.selectedColumns.length > 4 ? this.landscapePerPage : this.portraitPerPage; },
        get pages() { return Math.ceil(this.total / this.perPage); },
        init() {
            this.$watch('selectedColumns', () => {
                this.from = 1; this.to = Math.min(5, this.pages);
                this.downloaded = false; this.error = ''; this.message = '';
            });
        },
        get count() { return Math.max(0, Number(this.to) - Number(this.from) + 1); },
        get invalid() { return !this.total || this.count < 1 || Number(this.from) < 1 || Number(this.to) > this.pages || !Number.isInteger(Number(this.from)) || !Number.isInteger(Number(this.to)); },
        next() {
            const length = this.count;
            this.from = Number(this.to) + 1;
            this.to = Math.min(this.pages, this.from + length - 1);
            this.downloaded = false; this.error = ''; this.message = '';
        },
        close(dialog) { controller?.abort(); dialog.close(); },
        cancel() { controller?.abort(); },
        async download(form) {
            if (this.busy || this.invalid) return;
            this.error = ''; this.message = ''; this.downloaded = false;
            const parameters = new URLSearchParams(new FormData(form));
            if (!parameters.getAll('columns[]').length) { this.error = 'Select at least one column.'; return; }
            this.busy = true;
            controller = new AbortController();
            const signal = controller.signal;
            try {
                const response = await fetch(form.action + '?' + parameters, {signal, headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok) {
                    const details = await response.json().catch(() => ({}));
                    throw new Error(Object.values(details.errors || {}).flat()[0] || 'Unable to generate this PDF. Try a smaller page range.');
                }
                if (!response.headers.get('Content-Type')?.includes('application/pdf')) throw new Error('Please refresh this page and sign in again before downloading.');
                const blob = await response.blob();
                if (signal.aborted) return;
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'products.pdf';
                document.body.appendChild(link); link.click(); link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
                this.message = 'Downloaded pages ' + this.from + '-' + this.to + '.';
                this.downloaded = true;
            } catch (error) {
                if (error.name === 'AbortError') this.message = 'Download cancelled.';
                else this.error = error.message || 'Unable to download the PDF. Please try again.';
            } finally { this.busy = false; controller = null; }
        },
    };
};
</script>
<dialog id="export-pdf-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/60" style="max-height:90vh;overflow-y:auto" x-data="productPdfExport(@js($productPdf))" @cancel.prevent="close($el)">
    <div class="space-y-5 p-6">
        <div class="flex items-center justify-between gap-3"><div><h2 class="font-extrabold">Export PDF Options</h2><p class="mt-1 text-xs text-slate-500">A4 <span x-text="selectedColumns.length > 4 ? 'landscape' : 'portrait'"></span> · <span x-text="perPage"></span> products per page</p></div><button type="button" aria-label="Close PDF export" class="btn-secondary !px-3" @click="close($el.closest('dialog'))">✕</button></div>
        <div class="grid grid-cols-2 gap-3 rounded-xl bg-blue-50 p-4"><div><p class="text-xs font-bold text-slate-500">Matching Products</p><b class="text-xl">{{ number_format($productPdf['total']) }}</b></div><div><p class="text-xs font-bold text-slate-500">Total PDF Pages</p><b class="text-xl" x-text="pages.toLocaleString()">{{ number_format($productPdf['pages']) }}</b></div></div>
        @if(!$productPdf['total'])<p class="text-sm text-slate-500">No products match your filters. Change the filters to export products.</p>@endif
        <form method="GET" action="{{ route('products.pdf.export') }}" @submit.prevent="download($el)" class="space-y-5">
            @foreach(request()->only(['q','rack_number','supplier','brand','status']) as $key=>$value) @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
            <fieldset :disabled="busy || !total" class="space-y-5">
                <div class="grid grid-cols-2 gap-3"><label><span class="label">From Page</span><input name="from_page" type="number" min="1" :max="pages" x-model.number="from" required class="field"></label><label><span class="label">To Page</span><input name="to_page" type="number" :min="from" :max="pages" x-model.number="to" required class="field"></label></div>
                <div><p class="text-sm font-semibold" x-show="!invalid"><span x-text="count"></span> page(s) selected · products <span x-text="(Number(from)-1)*perPage+1"></span>-<span x-text="Math.min(Number(to)*perPage,total)"></span></p><p class="mt-1 text-xs text-slate-500">For faster downloads, we recommend {{ $productPdf['recommendedPages'] }} pages at a time. You can download any valid range, including all pages.</p><p role="status" x-cloak x-show="!invalid && count > recommendedPages" class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Large downloads may take longer or time out depending on server capacity. You can still download this range.</p><p x-cloak x-show="total && invalid" class="mt-2 text-xs font-semibold text-red-600">Choose whole page numbers from 1 to <span x-text="pages"></span>, with To Page at or after From Page.</p></div>
                <fieldset><legend class="label mb-2">Columns to Include</legend><div class="grid grid-cols-2 gap-3">@foreach(\App\Services\ProductPdfExport::COLUMNS as $key=>[$label,$weight])<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="columns[]" value="{{ $key }}" x-model="selectedColumns" checked class="size-4 rounded border-slate-300">{{ $label }}</label>@endforeach</div></fieldset>
                <label class="block"><span class="label">Sort By</span><select name="sort" class="field"><option value="name">Name (A-Z)</option><option value="qty_asc">Quantity (Low to High)</option><option value="qty_desc">Quantity (High to Low)</option><option value="rack_number">Rack Number</option></select></label>
                <p class="text-xs text-slate-500">Full details use A4 landscape with {{ $productPdf['landscapePerPage'] }} products per page; up to four columns use A4 portrait with {{ $productPdf['portraitPerPage'] }} products per page. Changing columns resets the page range.</p>
            </fieldset>
            <p role="alert" x-cloak x-show="error" x-text="error" class="rounded-xl bg-red-50 p-3 text-sm text-red-700"></p>
            <p role="status" aria-live="polite" x-cloak x-show="message || busy" x-text="busy ? 'Preparing your selected pages…' : message" class="text-sm font-semibold text-emerald-700"></p>
            <div class="flex flex-wrap justify-end gap-2"><button type="button" x-cloak x-show="downloaded && Number(to) < pages" @click="next()" class="btn-secondary">Next Pages</button><button type="button" @click="busy ? cancel() : close($el.closest('dialog'))" class="btn-secondary" x-text="busy ? 'Cancel Download' : 'Close'"></button><button type="submit" :disabled="busy || invalid" class="btn-primary disabled:opacity-50" x-text="busy ? 'Generating…' : 'Download A4 PDF'"></button></div>
        </form>
    </div>
</dialog>
