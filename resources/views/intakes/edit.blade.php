<x-layouts::app title="Edit Bike Intake: {{ $intake->intake_number }}">
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="mb-1 text-[10px] font-bold uppercase tracking-[.18em] text-red-600">Workshop Operations</p>
                <h1 class="page-title">Edit Bike Intake</h1>
                <p class="page-subtitle">Update details for {{ $intake->intake_number }}.</p>
            </div>
            <a href="{{ route('intakes.index') }}" class="btn-secondary">View all intakes</a>
        </div>

        <x-flash/>

        <form method="POST" action="{{ route('intakes.update', $intake) }}" class="space-y-5">
            @method('PUT')
            @csrf<x-submission-token/>
            <div class="grid min-w-0 gap-5 xl:grid-cols-12">
                <section class="panel min-w-0 xl:col-span-8">
                    <div class="mb-5 flex items-center gap-3 border-b border-slate-100 pb-4">
                        <span class="grid size-10 place-items-center rounded-xl bg-red-50 text-red-600"><x-dashboard-icon name="clipboard" class="size-5"/></span>
                        <div><h2 class="font-extrabold text-slate-900">Bike & Customer Information</h2><p class="mt-0.5 text-[11px] text-slate-400">Search by registration number to retrieve previous details.</p></div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="md:col-span-2"><span class="label">Intake date <em class="not-italic text-red-500">*</em></span><input type="date" name="intake_date" value="{{ old('intake_date', ($intake->intake_date ?? $intake->created_at)->toDateString()) }}" class="field" required>@error('intake_date')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label><span class="label">Bike number <em class="not-italic text-red-500">*</em></span><input id="bike_number" name="bike_number" value="{{ old('bike_number', $intake->bike->number) }}" class="field uppercase" required placeholder="BAM 5428" autocomplete="off"></label>
                        <label><span class="label">Bike model <em class="not-italic text-red-500">*</em></span><input id="bike_model" name="bike_model" value="{{ old('bike_model', $intake->bike->model) }}" class="field" required placeholder="e.g. Yamaha FZ-S V3"></label>
                        <label><span class="label">Customer name</span><input id="customer_name" name="customer_name" value="{{ old('customer_name', $intake->bike->customer->name) }}" class="field" placeholder="Full name"></label>
                        <label><span class="label">Phone number <span class="text-xs font-normal text-slate-400">(optional)</span></span><input id="phone" name="phone" value="{{ old('phone', $intake->bike->customer->phone) }}" class="field" placeholder="07X XXX XXXX"></label>
                        <label class="md:col-span-2"><span class="label">Address</span><input id="address" name="address" value="{{ old('address', $intake->bike->customer->address) }}" class="field" placeholder="Customer address"></label>
                        <label><span class="label">Brand</span><input name="brand" value="{{ old('brand', $intake->bike->brand) }}" class="field" placeholder="Yamaha, Honda, Bajaj..."></label>
                        <label><span class="label">Color</span><input name="color" value="{{ old('color', $intake->bike->color) }}" class="field" placeholder="Bike color"></label>
                        <label><span class="label">Chassis number</span><input id="chassis_number" name="chassis_number" value="{{ old('chassis_number', $intake->bike->chassis_number) }}" class="field" maxlength="100" placeholder="Enter chassis / frame number">@error('chassis_number')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror</label>
                        <label><span class="label">NIC</span><input id="nic" name="nic" value="{{ old('nic', $intake->bike->customer->nic) }}" class="field" placeholder="National ID"></label>
                        <label><span class="label">Current mileage</span><input type="number" name="current_mileage" value="{{ old('current_mileage', $intake->current_mileage) }}" class="field" placeholder="Current KM"></label>
                        <label class="md:col-span-2"><span class="label">Complaint / customer issue <em class="not-italic text-red-500">*</em></span><textarea name="complaint" class="field min-h-24" rows="3" required placeholder="Describe the repair issue clearly...">{{ old('complaint', $intake->complaint) }}</textarea></label>
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
                                <p class="mt-3 text-sm font-bold text-slate-700">Find an existing record</p>
                                <p id="lookup-empty-text" class="mx-auto mt-1 max-w-56 text-xs leading-5 text-slate-400">Enter at least three characters of a bike number, name, or phone.</p>
                            </div>
                            <div id="lookup-results-container" class="hidden -mx-5 -mb-5">
                                <div id="lookup-results" class="max-h-[500px] overflow-y-auto"></div>
                            </div>
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

            <section class="panel relative z-10">
                <div class="mb-5 flex items-center gap-3 border-b border-slate-100 pb-4"><span class="grid size-10 place-items-center rounded-xl bg-[#0b2447]/5 text-[#0b2447]"><x-dashboard-icon name="bike" class="size-5"/></span><div><h2 class="font-extrabold">Bike Condition & Intake Notes</h2><p class="mt-0.5 text-[11px] text-slate-400">Operational details, expected delivery and payment information.</p></div></div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label><span class="label">Fuel level</span><select name="fuel_level" class="field"><option value="">Select level</option>@foreach(['Empty','Quarter','Half','Three quarters','Full'] as $fuel)<option @selected(old('fuel_level', $intake->fuel_level)===$fuel)>{{ $fuel }}</option>@endforeach</select></label>
                    <label><span class="label">Estimated delivery</span><input type="date" name="estimated_delivery_date" value="{{ old('estimated_delivery_date', $intake->estimated_delivery_date?->format('Y-m-d')) }}" class="field"></label>
                    <label><span class="label">Estimated amount (LKR)</span><input type="number" step=".01" name="estimated_amount" value="{{ old('estimated_amount', $intake->estimated_amount) }}" class="field" placeholder="0.00"></label>
                    <label><span class="label">Advance payment</span><input type="number" step=".01" name="advance_payment" value="{{ old('advance_payment', $intake->advance_payment) }}" class="field" placeholder="0.00"></label>
                    <label><span class="label">Payment method</span><select name="payment_method" class="field">@foreach(['Cash','Card','Bank Transfer','QR'] as $method)<option @selected(old('payment_method', $intake->payment_method)===$method)>{{ $method }}</option>@endforeach</select></label>
                    <label><span class="label">Accessories received</span><input name="accessories" value="{{ old('accessories', $intake->accessories) }}" class="field" placeholder="Helmet, key, documents..."></label>
                    <label class="sm:col-span-2"><span class="label">Condition notes</span><input name="condition_notes" value="{{ old('condition_notes', $intake->condition_notes) }}" class="field" placeholder="Scratches, damage or special notes..."></label>
                </div>

                <div class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"><label class="flex cursor-pointer items-center gap-3 font-bold text-slate-800"><input type="checkbox" name="is_service" value="1" class="size-4 accent-red-600" onchange="document.getElementById('service-fields').classList.toggle('hidden',!this.checked)" @checked(old('is_service', $intake->is_service))> Service job</label><p class="ml-7 mt-1 text-[11px] text-slate-400">Schedule the customer's next service reminder.</p><div id="service-fields" class="mt-3 {{ old('is_service', $intake->is_service) ? '' : 'hidden' }} grid gap-3 sm:grid-cols-2"><label><span class="label">Next service KM</span><input type="number" name="next_service_km" value="{{ old('next_service_km', $intake->next_service_km) }}" class="field" placeholder="Next service KM"></label><label><span class="label">Next service Date</span><input type="date" name="next_service_date" value="{{ old('next_service_date', $intake->next_service_date?->format('Y-m-d')) }}" class="field"></label></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"><label class="flex cursor-pointer items-center gap-3 font-bold text-slate-800"><input type="checkbox" name="is_technician_bike" value="1" class="size-4 accent-red-600" onchange="document.getElementById('tech-field').classList.toggle('hidden',!this.checked)" @checked(old('is_technician_bike', $intake->bike->is_technician_bike))> Technician bike</label><p class="ml-7 mt-1 text-[11px] text-slate-400">Link this intake to an internal technician.</p><select id="tech-field" name="technician_id" class="field mt-3 {{ old('is_technician_bike', $intake->bike->is_technician_bike) ? '' : 'hidden' }}"><option value="">Select technician</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected(old('technician_id', $intake->bike->technician_id)==$t->id)>{{ $t->name }}</option>@endforeach</select></div>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('intakes.index') }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary min-w-44"><svg class="mr-2 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3h12l2 2v16H5V3Z"/><path d="M8 3v6h8V3M8 17h8"/></svg>Update Bike Intake</button>
            </div>
        </form>
    </div>

    <script>
        let timer;
        const inputs = ['bike_number', 'customer_name', 'phone'].map(id => document.getElementById(id));
        
        inputs.forEach(input => {
            if(!input) return;
            input.addEventListener('input', event => {
                clearTimeout(timer);
                timer = setTimeout(async () => {
                    const term = event.target.value.trim();
                    const empty = document.getElementById('lookup-empty');
                    const container = document.getElementById('lookup-results-container');
                    const list = document.getElementById('lookup-results');
                    const statusText = document.getElementById('lookup-status') || document.querySelector('#lookup-heading p');
                    
                    if (term.length < 1) { 
                        empty.classList.remove('hidden'); 
                        container.classList.add('hidden');
                        document.getElementById('lookup-empty-text').textContent = 'Enter a bike number, name, or phone.';
                        if(statusText) statusText.textContent = 'Waiting for input';
                        return; 
                    }
                    
                    if(statusText) statusText.textContent = 'Searching...';
                    const response = await fetch(@json(url('/bike-intakes/lookup')) + '/' + encodeURIComponent(term));
                    if (!response.ok) {
                        if(statusText) statusText.textContent = 'Error fetching results';
                        return;
                    }
                    const data = await response.json();
                    
                    if(statusText) statusText.textContent = 'Results found';
                    renderLookupResults(data.bikes, data.customers);
                }, 450);
            });
        });
        
        window.populateForm = function(item, type) {
            if (type === 'bike') {
                document.getElementById('bike_number').value = item.number || '';
                document.getElementById('bike_model').value = item.model || '';
                document.getElementById('chassis_number').value = item.chassis_number || '';
                if (item.customer) {
                    document.getElementById('phone').value = item.customer.phone || '';
                    document.getElementById('customer_name').value = item.customer.name || '';
                    document.getElementById('address').value = item.customer.address || '';
                    document.getElementById('nic').value = item.customer.nic || '';
                }
            } else if (type === 'customer') {
                document.getElementById('phone').value = item.phone || '';
                document.getElementById('customer_name').value = item.name || '';
                document.getElementById('address').value = item.address || '';
                document.getElementById('nic').value = item.nic || '';
            }
            
            // Provide feedback
            const container = document.getElementById('lookup-results-container');
            container.innerHTML = `
                <div class="p-5 text-center">
                    <div class="mx-auto grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-600 mb-3"><svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                    <p class="font-bold text-slate-800 text-sm">Form populated!</p>
                    <p class="text-xs text-slate-500 mt-1">You can continue editing the details.</p>
                </div>
            `;
            setTimeout(() => {
                document.getElementById('lookup-empty').classList.remove('hidden');
                container.classList.add('hidden');
                document.getElementById('lookup-results-container').innerHTML = '<div id="lookup-results" class="max-h-[500px] overflow-y-auto"></div>';
            }, 2500);
        };
        
        function renderLookupResults(bikesData, customersData) {
            try {
                const empty = document.getElementById('lookup-empty');
                const container = document.getElementById('lookup-results-container');
                const list = document.getElementById('lookup-results');
                
                // Force into arrays in case Laravel serialized them as JSON objects
                const bikes = Array.isArray(bikesData) ? bikesData : Object.values(bikesData || {});
                const customers = Array.isArray(customersData) ? customersData : Object.values(customersData || {});
                
                if (bikes.length === 0 && customers.length === 0) {
                    empty.classList.remove('hidden');
                    if (container) container.classList.add('hidden');
                    document.getElementById('lookup-empty-text').textContent = 'No matching records found.';
                    return;
                }
                
                empty.classList.add('hidden');
                if (container) container.classList.remove('hidden');
                
                let html = '';
                if (bikes.length > 0) {
                    html += `<div class="px-5 py-2 bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-wider border-y border-slate-100">Matching Bikes</div>`;
                    bikes.forEach(bike => {
                        const cname = (bike.customer && bike.customer.name) ? bike.customer.name : 'Walk-in';
                        const cphone = (bike.customer && bike.customer.phone) ? bike.customer.phone : '';
                        const bikenumber = bike.number || '';
                        const bikemodel = bike.model || '';
                        
                        let dueBadge = '';
                        if (bike.total_due && parseFloat(bike.total_due) > 0) {
                            dueBadge = `<div class="mt-1 inline-flex items-center gap-1 rounded-md bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-600 ring-1 ring-inset ring-red-500/10"><svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Due: Rs ${parseFloat(bike.total_due).toFixed(2)}</div>`;
                        }
                        
                        html += `
                            <div class="p-4 border-b border-slate-100 hover:bg-emerald-50 cursor-pointer transition flex justify-between items-center group" onclick='populateForm(${JSON.stringify(bike).replace(/'/g, "&#39;")}, "bike")'>
                                <div>
                                    <div class="font-bold text-slate-800 text-sm group-hover:text-emerald-700">${bikenumber} <span class="text-xs text-slate-500 font-normal ml-1">${bikemodel}</span></div>
                                    <div class="text-xs text-slate-500 mt-0.5">${cname} ${cphone ? '· '+cphone : ''}</div>
                                    ${dueBadge}
                                </div>
                                <button type="button" class="text-xs font-bold text-emerald-600 bg-emerald-100 px-3 py-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition">Select</button>
                            </div>
                        `;
                    });
                }
                if (customers.length > 0) {
                    html += `<div class="px-5 py-2 bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-wider border-y border-slate-100">Matching Customers</div>`;
                    customers.forEach(customer => {
                        const cname = customer.name || '';
                        const cphone = customer.phone || 'No phone';
                        html += `
                            <div class="p-4 border-b border-slate-100 hover:bg-blue-50 cursor-pointer transition flex justify-between items-center group" onclick='populateForm(${JSON.stringify(customer).replace(/'/g, "&#39;")}, "customer")'>
                                <div>
                                    <div class="font-bold text-slate-800 text-sm group-hover:text-blue-700">${cname}</div>
                                    <div class="text-xs text-slate-500 mt-0.5">${cphone}</div>
                                </div>
                                <button type="button" class="text-xs font-bold text-blue-600 bg-blue-100 px-3 py-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition">Select</button>
                            </div>
                        `;
                    });
                }
                
                if (list) {
                    list.innerHTML = html;
                } else if (container) {
                    container.innerHTML = `<div id="lookup-results" class="max-h-[500px] overflow-y-auto">${html}</div>`;
                }
                
                // Debugging output directly into the container if it's completely empty
                if (container && container.innerHTML.trim() === '') {
                    container.innerHTML = `<div class="p-5 text-red-500 font-mono text-xs">Debug: html_len=${html.length}, bikes=${bikes.length}, list=${!!list}</div>`;
                }
                
            } catch (e) {
                console.error("Lookup render error", e);
                document.getElementById('lookup-empty').classList.remove('hidden');
                if (document.getElementById('lookup-results-container')) {
                    document.getElementById('lookup-results-container').classList.add('hidden');
                }
                document.getElementById('lookup-empty-text').textContent = 'Error rendering: ' + e.message;
            }
        }
    </script>
</x-layouts::app>
