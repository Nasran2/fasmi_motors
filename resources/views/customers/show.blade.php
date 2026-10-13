<x-layouts::app title="{{ $customer->name ?: 'Customer Profile' }}">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div class="flex items-start gap-4">
                <div class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-blue-600 to-blue-800 text-white shadow-lg shadow-blue-500/20">
                    <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <div>
                    <h1 class="page-title flex items-center gap-3">
                        {{ $customer->name ?: 'Walk-in Customer' }}
                        @if($customer->active)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-bold text-slate-600 ring-1 ring-inset ring-slate-500/20">Inactive</span>
                        @endif
                    </h1>
                    <p class="page-subtitle mt-1 flex items-center gap-2">
                        <span class="font-medium text-slate-700">{{ $customer->phone }}</span>
                        <span class="size-1 rounded-full bg-slate-300"></span>
                        {{ $customer->customer_type }}
                        @if($customer->nic)
                            <span class="size-1 rounded-full bg-slate-300"></span>
                            NIC: {{ $customer->nic }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('customers.bikes.create', ['customer' => $customer->id]) }}" class="btn-primary shadow-red-500/20">
                    <svg class="mr-2 size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Register New Bike
                </a>
            </div>
        </div>

        <x-flash/>

        @php
            $totalVisits = $customer->bikes->sum(fn($b) => $b->intakes->count()) + $quickVisits;
        @endphp

        <!-- Stats Grid -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="panel flex items-center gap-5">
                <div class="grid size-12 place-items-center rounded-full bg-blue-50 text-blue-600">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Registered Bikes</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $customer->bikes->count() }}</p>
                </div>
            </div>
            <div class="panel flex items-center gap-5">
                <div class="grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Workshop Visits</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $totalVisits }}</p>
                </div>
            </div>
            <div class="panel flex items-center gap-5 {{ $dueAmount > 0 ? 'border-red-200 bg-red-50/30' : '' }}">
                <div class="grid size-12 place-items-center rounded-full {{ $dueAmount > 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-500' }}">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider {{ $dueAmount > 0 ? 'text-red-700' : 'text-slate-500' }}">Current Due</p>
                    <p class="mt-1 text-2xl font-black {{ $dueAmount > 0 ? 'text-red-600' : 'text-slate-900' }}">Rs. {{ number_format($dueAmount, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-12">
            <!-- Left side: Edit Customer Form -->
            <div class="lg:col-span-4">
                <form method="POST" action="{{ route('customers.update', $customer) }}" class="panel space-y-5 border-t-4 border-t-slate-800 sticky top-6">
                    @csrf<x-submission-token/> 
                    @method('PUT')
                    
                    <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900">
                        <svg class="size-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        Edit Customer Profile
                    </h2>
                    
                    <div class="space-y-4">
                        <label>
                            <span class="label">Full Name <em class="not-italic text-red-500">*</em></span>
                            <input name="name" value="{{ $customer->name }}" class="field" required placeholder="Customer's full name">
                        </label>
                        
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            <label>
                                <span class="label">Phone Number <em class="not-italic text-red-500">*</em></span>
                                <input name="phone" value="{{ $customer->phone }}" class="field" required placeholder="07X XXX XXXX">
                            </label>
                            <label>
                                <span class="label">Alternative Phone</span>
                                <input name="alternative_phone" value="{{ $customer->alternative_phone }}" class="field" placeholder="Optional">
                            </label>
                        </div>
                        
                        <label>
                            <span class="label">Address</span>
                            <textarea name="address" class="field min-h-20" rows="2" placeholder="Customer's residential or business address">{{ $customer->address }}</textarea>
                        </label>
                        
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label>
                                <span class="label">NIC</span>
                                <input name="nic" value="{{ $customer->nic }}" class="field" placeholder="National ID">
                            </label>
                            <label>
                                <span class="label">Email</span>
                                <input type="email" name="email" value="{{ $customer->email }}" class="field" placeholder="Email address">
                            </label>
                        </div>
                        
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label>
                                <span class="label">Customer Type</span>
                                <select name="customer_type" class="field">
                                    @foreach(['Normal Customer','Regular Customer','Credit Customer','Technician Bike Owner'] as $type)
                                        <option @selected($customer->customer_type === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="label">Account Status</span>
                                <select name="active" class="field">
                                    <option value="1">Active</option>
                                    <option value="0" @selected(!$customer->active)>Inactive</option>
                                </select>
                            </label>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-5 mt-2">
                        <button class="btn-primary w-full shadow-red-500/20">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Right side: Connected Bikes -->
            <div class="min-w-0 lg:col-span-8">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900">
                        <x-dashboard-icon name="bike" class="size-5 text-slate-400" />
                        Connected Bikes
                    </h2>
                </div>
                
                <section class="space-y-4">
                    @forelse($customer->bikes as $bike)
                        <article class="panel hover:-translate-y-1 hover:shadow-lg transition-all duration-200 border-l-4 border-l-red-500">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <a href="{{ route('customers.bikes.show', $bike) }}" class="group inline-flex items-center gap-2">
                                        <h3 class="text-xl font-black text-slate-900 transition group-hover:text-red-600">{{ $bike->number }}</h3>
                                        <svg class="size-4 text-slate-300 transition group-hover:text-red-500 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                                    </a>
                                    <p class="mt-1 text-sm font-medium text-slate-500">
                                        {{ $bike->brand }} {{ $bike->model }} 
                                        @if($bike->last_mileage)
                                            <span class="mx-1.5 text-slate-300">|</span> 
                                            {{ number_format($bike->last_mileage) }} km
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('intakes.create', ['bike' => $bike->number]) }}" class="btn-secondary h-9 px-3 text-xs">
                                        <svg class="mr-1.5 size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                        Create Intake
                                    </a>
                                    <a href="{{ route('customers.ownership', ['bike' => $bike->id]) }}" class="btn-secondary h-9 px-3 text-xs">Change Owner</a>
                                </div>
                            </div>
                            
                            @php
                                $bikeDue = (float) $bike->due_amount;
                                $lastIntake = $bike->intakes->toBase()->concat($bike->quickJobs)->sortByDesc('created_at')->first();
                            @endphp
                            
                            <div class="mt-5 grid grid-cols-3 gap-3 border-t border-slate-100 pt-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Visits</p>
                                    <p class="mt-1 text-lg font-bold text-slate-700">{{ $bike->intakes->count() + $bike->quickJobs->count() }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Last Visit</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-700 mt-1.5">
                                        {{ $lastIntake ? $lastIntake->created_at->format('d M Y') : 'Never' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider {{ $bikeDue > 0 ? 'text-red-400' : 'text-slate-400' }}">Outstanding Due</p>
                                    <p class="mt-1 text-lg font-bold {{ $bikeDue > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                        Rs. {{ number_format($bikeDue, 2) }}
                                    </p>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 py-16 text-center">
                            <div class="mx-auto grid size-16 place-items-center rounded-full bg-white shadow-sm ring-1 ring-slate-900/5 mb-4">
                                <x-dashboard-icon name="bike" class="size-8 text-slate-300" />
                            </div>
                            <h3 class="text-base font-bold text-slate-700">No bikes registered</h3>
                            <p class="mt-1 text-sm text-slate-500">This customer doesn't have any bikes linked to their profile yet.</p>
                            <a href="{{ route('customers.bikes.create', ['customer' => $customer->id]) }}" class="btn-primary mt-6">
                                <svg class="mr-2 size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                Register First Bike
                            </a>
                        </div>
                    @endempty
                </section>
                @include('customers.partials.bills')
            </div>
        </div>
    </div>
    @if(auth()->user()->canAccess('sales.receive_payments'))
        <x-job-due-payment-dialog :accounts="$accounts" />
    @endif
</x-layouts::app>
