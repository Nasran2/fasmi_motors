<x-layouts::app title="Staff Bike Repairs">
<div class="space-y-5">
    <div class="flex flex-wrap justify-between gap-3"><div><p class="text-xs font-bold uppercase text-red-600">Staffs / Repairs</p><h1 class="page-title">Staff Bike Repairs</h1><p class="page-subtitle">Normal payments, salary recovery, and remaining repair balances for regular and quick jobs.</p></div><a href="{{ route('jobs.quick.create') }}" class="btn-primary">New Quick Job</a></div>
    <x-flash/>
    <div class="panel text-sm text-slate-600">Mark the bike as a <b>Staff bike</b> on its intake or quick job and select its owner. In the job's billing section, choose <b>Pay normally</b> or <b>Deduct from salary</b>. Salary Payments lets you choose how much to deduct against each repair.</div>
    <form method="GET" class="panel flex flex-wrap items-end gap-3">
        <label><span class="label">Staff</span><select name="staff" class="field"><option value="">All staff</option>@foreach($technicians as $staff)<option value="{{ $staff->id }}" @selected(request('staff') == $staff->id)>{{ $staff->name }}</option>@endforeach</select></label>
        <label class="flex-1"><span class="label">Find repair</span><input name="q" value="{{ request('q') }}" placeholder="Job, bill, or bike number" class="field"></label><button class="btn-secondary">Search</button>
    </form>
    <div class="table-wrap"><table class="data-table min-w-[1100px]"><thead><tr><th>Repair job</th><th>Staff owner / bike</th><th>Work</th><th>Bill total</th><th>Salary recovered</th><th>Other settled payments</th><th>Bill balance</th><th>Payment arrangement</th><th>Actions</th></tr></thead><tbody>
    @forelse($jobs as $job)
        @php($salaryPaid = (float) ($job->invoice?->payments->where('method', 'Salary Deduction')->where('status', 'Completed')->sum('amount') ?? 0))
        @php($plan = $job->bikeDeductionPlans->firstWhere('status', 'Active'))
        <tr><td><b>{{ $job->number }}</b><span class="block text-xs text-slate-500">{{ $job->is_quick_job ? 'Quick Job' : 'Job Card' }} · {{ $job->status }}</span><span class="block text-xs">{{ $job->invoice?->number ?: 'Not billed' }}</span></td>
        <td><b>{{ $job->repair_staff?->name }}</b><span class="block text-xs">{{ $job->bike?->number ?: 'No registration recorded' }} {{ $job->bike?->model }}</span></td><td>{{ $job->complaint }}</td>
        <td>Rs. {{ number_format($job->invoice?->total ?? $job->total, 2) }}</td><td>Rs. {{ number_format($salaryPaid, 2) }}</td><td>Rs. {{ number_format(max(0, (float) $job->invoice?->paid_amount - $salaryPaid), 2) }}</td><td class="font-bold text-red-600">Rs. {{ number_format($job->invoice?->due_amount ?? $job->total, 2) }}</td>
        <td>{{ $plan && $job->invoice?->due_amount > 0 ? $plan->deduction_type : ($salaryPaid > 0 ? 'Salary recovery / settled' : 'Normal payment') }}@if($plan && $job->invoice?->due_amount > 0)<span class="block text-xs">Rs. {{ number_format($plan->installment_amount, 2) }} suggested per month</span>@endif</td>
        <td><a href="{{ route('technicians.bike-repairs.show', $job) }}" class="btn-secondary">Repair details</a></td></tr>
    @empty<tr><td colspan="9" class="py-10 text-center text-slate-500">No staff bike repairs found. Select Staff bike and its owner when creating an intake or quick job.</td></tr>@endforelse
    </tbody></table><div class="border-t p-4">{{ $jobs->links() }}</div></div>
</div>
</x-layouts::app>
