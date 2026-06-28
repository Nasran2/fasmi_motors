<x-layouts::app title="New Bike Intake">
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="mb-1 text-[10px] font-bold uppercase tracking-[.18em] text-red-600">Workshop Operations</p>
                <h1 class="page-title">New Bike Intake</h1>
                <p class="page-subtitle">Register an incoming bike, customer details and repair requirements.</p>
            </div>
            <a href="{{ route('intakes.index') }}" class="btn-secondary">View all intakes</a>
        </div>

        <x-flash/>

        <form method="POST" action="{{ route('intakes.store') }}" class="space-y-5">
            @csrf
            <div class="grid min-w-0 gap-5 xl:grid-cols-12">
                <section class="panel min-w-0 xl:col-span-8">
                    <div class="mb-5 flex items-center gap-3 border-b border-slate-100 pb-4">
                        <span class="grid size-10 place-items-center rounded-xl bg-red-50 text-red-600"><x-dashboard-icon name="clipboard" class="size-5"/></span>
                        <div><h2 class="font-extrabold text-slate-900">Bike & Customer Information</h2><p class="mt-0.5 text-[11px] text-slate-400">Search by registration number to retrieve previous details.</p></div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label><span class="label">Bike number <em class="not-italic text-red-500">*</em></span><input id="bike_number" name="bike_number" value="{{ old('bike_number') }}" class="field uppercase" required placeholder="BAM 5428" autocomplete="off"></label>
                        <label><span class="label">Bike model <em class="not-italic text-red-500">*</em></span><input id="bike_model" name="bike_model" value="{{ old('bike_model') }}" class="field" required placeholder="e.g. Yamaha FZ-S V3"></label>
                        <label><span class="label">Customer name</span><input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="field" placeholder="Full name"></label>
                        <label><span class="label">Phone number <em class="not-italic text-red-500">*</em></span><input id="phone" name="phone" value="{{ old('phone') }}" class="field" required placeholder="07X XXX XXXX"></label>
                        <label class="md:col-span-2"><span class="label">Address</span><input id="address" name="address" value="{{ old('address') }}" class="field" placeholder="Customer address"></label>
                        <label><span class="label">Brand</span><input name="brand" value="{{ old('brand') }}" class="field" placeholder="Yamaha, Honda, Bajaj..."></label>
                        <label><span class="label">Color</span><input name="color" value="{{ old('color') }}" class="field" placeholder="Bike color"></label>
                        <label><span class="label">NIC</span><input id="nic" name="nic" value="{{ old('nic') }}" class="field" placeholder="National ID"></label>
                        <label><span class="label">Current mileage</span><input type="number" name="current_mileage" value="{{ old('current_mileage') }}" class="field" placeholder="Current KM"></label>
                        <label class="md:col-span-2"><span class="label">Complaint / customer issue <em class="not-italic text-red-500">*</em></span><textarea name="complaint" class="field min-h-24" rows="3" required placeholder="Describe the repair issue clearly...">{{ old('complaint') }}</textarea></label>
                    </div>
                </section>

                <aside class="space-y-5 xl:col-span-4">
                    <section id="lookup-card" class="panel overflow-hidden !p-0">
                        <div id="lookup-heading" class="flex items-center gap-3 border-b border-blue-100 bg-gradient-to-r from-blue-50 to-slate-50 px-5 py-4 text-blue-800">
                            <span class="grid size-9 place-items-center rounded-xl bg-white shadow-sm"><x-dashboard-icon name="bike" class="size-5"/></span>
                            <div><h2 class="text-sm font-extrabold">Smart Bike Lookup</h2><p class="text-[10px] text-blue-500">Waiting for a registration number</p></div>
                        </div>
                        <div class="p-5">
                            <div id="lookup-empty" class="py-5 text-center">
                                <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200"><svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m21 21-4.3-4.3M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14Z"/><path d="M8 11h6m-3-3v6"/></svg></span>
                                <p class="mt-3 text-sm font-bold text-slate-700">Find an existing bike</p>
                                <p class="mx-auto mt-1 max-w-56 text-xs leading-5 text-slate-400">Enter at least three characters. Customer and bike details will be filled automatically.</p>
                            </div>
                            <div id="lookup-found" class="hidden space-y-3">
                                <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2.5"><span class="text-xs font-bold text-emerald-700">Existing bike found</span><span class="grid size-6 place-items-center rounded-full bg-emerald-500 text-white">✓</span></div>
                                <dl class="divide-y divide-slate-100 text-xs">
                                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Bike number</dt><dd id="found-number" class="font-bold text-slate-800"></dd></div>
                                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Model</dt><dd id="found-model" class="text-right font-bold text-slate-800"></dd></div>
                                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Customer</dt><dd id="found-customer" class="text-right font-bold text-slate-800"></dd></div>
                                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Previous visits</dt><dd id="found-visits" class="font-bold text-slate-800"></dd></div>
                                </dl>
                            </div>
                            <div id="history" class="mt-4 hidden rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs font-medium text-blue-700"></div>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-xl bg-amber-50 text-amber-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5m0 3v.1"/></svg></span><div><h2 class="text-sm font-extrabold">Intake Checklist</h2><p class="text-[10px] text-slate-400">Required before saving</p></div></div>
                        <div class="mt-4 space-y-2.5 text-xs text-slate-600">
                            <p class="flex items-center gap-2"><span class="size-1.5 rounded-full bg-red-500"></span>Confirm bike registration number</p>
                            <p class="flex items-center gap-2"><span class="size-1.5 rounded-full bg-red-500"></span>Record customer complaint clearly</p>
                            <p class="flex items-center gap-2"><span class="size-1.5 rounded-full bg-red-500"></span>Check fuel and received accessories</p>
                        </div>
                    </section>
                </aside>
            </div>

            <section class="panel">
                <div class="mb-5 flex items-center gap-3 border-b border-slate-100 pb-4"><span class="grid size-10 place-items-center rounded-xl bg-[#0b2447]/5 text-[#0b2447]"><x-dashboard-icon name="bike" class="size-5"/></span><div><h2 class="font-extrabold">Bike Condition & Intake Notes</h2><p class="mt-0.5 text-[11px] text-slate-400">Operational details, expected delivery and payment information.</p></div></div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label><span class="label">Fuel level</span><select name="fuel_level" class="field"><option value="">Select level</option>@foreach(['Empty','Quarter','Half','Three quarters','Full'] as $fuel)<option @selected(old('fuel_level')===$fuel)>{{ $fuel }}</option>@endforeach</select></label>
                    <label><span class="label">Estimated delivery</span><input type="date" name="estimated_delivery_date" value="{{ old('estimated_delivery_date') }}" class="field"></label>
                    <label><span class="label">Estimated amount (LKR)</span><input type="number" step=".01" name="estimated_amount" value="{{ old('estimated_amount') }}" class="field" placeholder="0.00"></label>
                    <label><span class="label">Advance payment</span><input type="number" step=".01" name="advance_payment" value="{{ old('advance_payment') }}" class="field" placeholder="0.00"></label>
                    <label><span class="label">Payment method</span><select name="payment_method" class="field">@foreach(['Cash','Card','Bank Transfer','QR'] as $method)<option @selected(old('payment_method')===$method)>{{ $method }}</option>@endforeach</select></label>
                    <label><span class="label">Accessories received</span><input name="accessories" value="{{ old('accessories') }}" class="field" placeholder="Helmet, key, documents..."></label>
                    <label class="sm:col-span-2"><span class="label">Condition notes</span><input name="condition_notes" value="{{ old('condition_notes') }}" class="field" placeholder="Scratches, damage or special notes..."></label>
                </div>

                <div class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"><label class="flex cursor-pointer items-center gap-3 font-bold text-slate-800"><input type="checkbox" name="is_service" value="1" class="size-4 accent-red-600" onchange="document.getElementById('service-fields').classList.toggle('hidden',!this.checked)" @checked(old('is_service'))> Service job</label><p class="ml-7 mt-1 text-[11px] text-slate-400">Schedule the customer's next service reminder.</p><div id="service-fields" class="mt-3 {{ old('is_service') ? '' : 'hidden' }} grid gap-3 sm:grid-cols-2"><input type="number" name="next_service_km" value="{{ old('next_service_km') }}" class="field" placeholder="Next service KM"><input type="date" name="next_service_date" value="{{ old('next_service_date') }}" class="field"></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"><label class="flex cursor-pointer items-center gap-3 font-bold text-slate-800"><input type="checkbox" name="is_technician_bike" value="1" class="size-4 accent-red-600" onchange="document.getElementById('tech-field').classList.toggle('hidden',!this.checked)" @checked(old('is_technician_bike'))> Technician bike</label><p class="ml-7 mt-1 text-[11px] text-slate-400">Link this intake to an internal technician.</p><select id="tech-field" name="technician_id" class="field mt-3 {{ old('is_technician_bike') ? '' : 'hidden' }}"><option value="">Select technician</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected(old('technician_id')==$t->id)>{{ $t->name }}</option>@endforeach</select></div>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('intakes.index') }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary min-w-44"><svg class="mr-2 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3h12l2 2v16H5V3Z"/><path d="M8 3v6h8V3M8 17h8"/></svg>Save Bike Intake</button>
            </div>
        </form>
    </div>

    <script>
        let timer;
        const bikeNumber = document.getElementById('bike_number');
        bikeNumber.addEventListener('input', event => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const number = event.target.value.trim();
                const empty = document.getElementById('lookup-empty');
                const found = document.getElementById('lookup-found');
                const history = document.getElementById('history');
                if (number.length < 3) { empty.classList.remove('hidden'); found.classList.add('hidden'); history.classList.add('hidden'); return; }
                const response = await fetch(@json(url('/bike-intakes/lookup')) + '/' + encodeURIComponent(number));
                if (!response.ok) return;
                const bike = await response.json();
                if (bike && bike.id) {
                    document.getElementById('bike_model').value = bike.model || '';
                    document.getElementById('phone').value = bike.customer?.phone || '';
                    document.getElementById('customer_name').value = bike.customer?.name || '';
                    document.getElementById('address').value = bike.customer?.address || '';
                    document.getElementById('nic').value = bike.customer?.nic || '';
                    document.getElementById('found-number').textContent = bike.number || number.toUpperCase();
                    document.getElementById('found-model').textContent = bike.model || 'Not recorded';
                    document.getElementById('found-customer').textContent = bike.customer?.name || bike.customer?.phone || 'Walk-in';
                    document.getElementById('found-visits').textContent = bike.intakes?.length || 0;
                    history.textContent = `Details loaded from ${bike.intakes?.length || 0} previous intake(s). You can update them if needed.`;
                    empty.classList.add('hidden'); found.classList.remove('hidden'); history.classList.remove('hidden');
                } else {
                    empty.classList.remove('hidden'); found.classList.add('hidden');
                    history.textContent = 'New bike — no previous workshop record was found.';
                    history.classList.remove('hidden');
                }
            }, 450);
        });
    </script>
</x-layouts::app>
