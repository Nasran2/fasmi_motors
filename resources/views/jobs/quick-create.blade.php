<x-layouts::app title="Quick Job">
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h1 class="page-title">Quick Job</h1><p class="page-subtitle">Small adjustments, washing, and other quick work. All details are optional.</p></div>
            <a href="{{ route('jobs.create') }}" class="btn-secondary">Create from intake</a>
        </div>
        <x-flash/>
        <form method="POST" action="{{ route('jobs.quick.store') }}" class="space-y-5" x-data="{ staffBike: @js((bool) old('is_technician_bike', false)) }">
            @csrf<x-submission-token/>
            <section class="panel">
                <h2 class="mb-4 text-lg font-bold">Customer & Bike</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['bike_number' => ['Bike number', 'text'], 'bike_model' => ['Bike model', 'text'], 'customer_name' => ['Customer name', 'text'], 'phone' => ['Phone number', 'tel'], 'nic' => ['NIC', 'text']] as $name => [$label, $type])
                        <label><span class="label">{{ $label }}</span><input name="{{ $name }}" type="{{ $type }}" value="{{ old($name) }}" class="field" placeholder="Optional"></label>
                    @endforeach
                </div>
                <details class="mt-4" @if($errors->any()) open @endif>
                    <summary class="cursor-pointer text-sm font-semibold text-blue-700">More optional details & opening balance</summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label><span class="label">Email</span><input name="email" type="email" value="{{ old('email') }}" class="field" placeholder="Optional"></label>
                        <label class="sm:col-span-2 lg:col-span-3"><span class="label">Address</span><input name="address" value="{{ old('address') }}" class="field" placeholder="Optional"></label>
                        @foreach(['brand' => 'Brand', 'color' => 'Color', 'chassis_number' => 'Chassis number', 'engine_number' => 'Engine number'] as $name => $label)
                            <label><span class="label">{{ $label }}</span><input name="{{ $name }}" value="{{ old($name) }}" class="field" placeholder="Optional"></label>
                        @endforeach
                        <label><span class="label">Current mileage (KM)</span><input name="current_mileage" type="number" min="0" value="{{ old('current_mileage') }}" class="field" placeholder="Optional"></label>
                        <label><span class="label">Opening balance (LKR)</span><input name="opening_balance" type="number" min="0" step="0.01" value="{{ old('opening_balance') }}" class="field" placeholder="0.00"></label>
                        <label><span class="label">Opening balance date</span><input name="opening_balance_date" type="date" max="{{ today()->toDateString() }}" value="{{ old('opening_balance_date', today()->toDateString()) }}" class="field"></label>
                    </div>
                </details>
                <div class="mt-4 rounded-xl border border-slate-200 p-4">
                    <input type="hidden" name="is_technician_bike" value="0">
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="is_technician_bike" value="1" x-model="staffBike" @checked(old('is_technician_bike'))>Staff bike</label>
                    <label class="mt-3 block" x-show="staffBike" x-cloak><span class="label">Staff bike owner</span><select name="technician_id" class="field" :disabled="!staffBike" :required="staffBike"><option value="">Select staff member</option>@foreach($technicians as $staff)<option value="{{ $staff->id }}" @selected((string) old('technician_id') === (string) $staff->id)>{{ $staff->name }}</option>@endforeach</select></label>
                </div>
            </section>
            <section class="panel">
                <h2 class="mb-4 text-lg font-bold">Quick Work</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="label">Work / customer issue</span><textarea name="complaint" rows="2" class="field" placeholder="e.g. Chain adjustment or washing only">{{ old('complaint') }}</textarea></label>
                    <label><span class="label">Technician (optional)</span><select name="main_technician_id" class="field"><option value="">Unassigned</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}" @selected((string) old('main_technician_id') === (string) $technician->id)>{{ $technician->name }}</option>@endforeach</select></label>
                    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                        @foreach(['service_total' => 'Service (LKR)', 'labor_total' => 'Labor (LKR)', 'washing_total' => 'Washing (LKR)'] as $name => $label)
                            <label><span class="label">{{ $label }}</span><input name="{{ $name }}" type="number" min="0" step="0.01" value="{{ old($name) }}" class="field" placeholder="0.00"></label>
                        @endforeach
                    </div>
                </div>
            </section>
            <section class="panel"><h2 class="mb-4 text-lg font-bold">Washing Assignment</h2><x-washing-assignment :technicians="$technicians" /></section>
            <div class="flex justify-end gap-3"><a href="{{ route('jobs.index') }}" class="btn-secondary">Cancel</a><button type="submit" class="btn-primary">Create Quick Job</button></div>
        </form>
    </div>
</x-layouts::app>
