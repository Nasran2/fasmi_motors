<x-layouts::app title="Data Management">
    <div class="space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Data Management</p>
            <h1 class="page-title">Export & Import</h1>
            <p class="page-subtitle">Export data to Excel or import bulk records</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <!-- Customers -->
            <div class="panel space-y-4">
                <div class="flex items-center gap-3">
                    <div class="grid size-12 place-items-center rounded-full bg-blue-50 text-blue-600">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <h2 class="font-bold text-lg">Customers Data</h2>
                        <p class="text-sm text-slate-500">Export or bulk import customer details</p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('customers.excel.export') }}" class="btn-secondary w-full justify-center">Export Customers</a>
                </div>

                <form method="POST" action="{{ route('customers.excel.import') }}" enctype="multipart/form-data" class="space-y-3 border-t pt-4">
                    @csrf<x-submission-token/>
                    <div>
                        <label class="label">Import Customers (Excel/CSV)</label>
                        <input type="file" name="file" required accept=".csv,.xlsx,.xls" class="w-full text-slate-900 p-2.5 rounded-lg border border-slate-200">
                    </div>
                    <button type="submit" class="btn-primary w-full justify-center">Upload & Import</button>
                </form>
            </div>

            <!-- Products -->
            <div class="panel space-y-4">
                <div class="flex items-center gap-3">
                    <div class="grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                    </div>
                    <div>
                        <h2 class="font-bold text-lg">Products Data</h2>
                        <p class="text-sm text-slate-500">Export or bulk import product inventory</p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('products.excel.export') }}" class="btn-secondary w-full justify-center">Export Products</a>
                </div>

                <form method="POST" action="{{ route('products.excel.import') }}" enctype="multipart/form-data" class="space-y-3 border-t pt-4">
                    @csrf<x-submission-token/>
                    <div>
                        <label class="label">Import Products (Excel/CSV)</label>
                        <input type="file" name="file" required accept=".csv,.xlsx,.xls" class="w-full text-slate-900 p-2.5 rounded-lg border border-slate-200">
                    </div>
                    <button type="submit" class="btn-primary w-full justify-center">Upload & Import</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts::app>
