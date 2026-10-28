<x-layouts::app title="Staff List">
<div class="space-y-5">
 <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Staff</p><h1 class="page-title">Staff List</h1><p class="page-subtitle">Manage workshop staff, salary, commission, and job history</p></div><a href="{{ route('technicians.create') }}" class="btn-primary">＋ Add Staff</a></div>
 <x-flash/>
 <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
  @foreach([['Total Staff',$stats['total']],['Active Staff',$stats['active']],['Present Today',$stats['present']],['Salary Pending','Rs. '.number_format($stats['salary_pending'],2)],['Commission This Month','Rs. '.number_format($stats['commission'],2)]] as [$label,$value])
   <article class="panel !p-4"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-black">{{ $value }}</p></article>
  @endforeach
 </div>
 <form class="panel grid gap-3 md:grid-cols-2 xl:grid-cols-5"><input name="q" value="{{ request('q') }}" class="field xl:col-span-2" placeholder="Search name or phone"><select name="status" class="field"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select><select name="salary_type" class="field"><option value="">All salary types</option>@foreach(['Monthly','Daily','Weekly','Hourly'] as $type)<option @selected(request('salary_type')===$type)>{{ $type }}</option>@endforeach</select><div class="flex gap-2"><select name="commission" class="field"><option value="">Commission: All</option><option value="yes">Enabled</option><option value="no">Disabled</option></select><button class="btn-secondary">Filter</button></div></form>
 <div class="table-wrap"><table class="data-table w-full min-w-[1000px]">
  <thead>
    <tr>
      <th>Staff Member</th>
      <th>Role</th>
      <th>Compensation</th>
      <th>Attendance</th>
      <th>This Month</th>
      <th>Deductions Due</th>
      <th>Status</th>
      <th class="text-right">Actions</th>
    </tr>
  </thead>
  <tbody>
  @forelse($technicians as $technician)
    <tr>
      <td class="whitespace-nowrap">
        <div class="flex items-center gap-3">
          <div class="grid size-10 place-items-center rounded-full bg-red-50 text-red-600 font-bold">
            {{ substr($technician->name, 0, 1) }}
          </div>
          <div>
            <a class="font-black text-slate-900 hover:text-red-600" href="{{ route('technicians.history',['technician'=>$technician->id]) }}">{{ $technician->name }}</a>
            <div class="flex items-center gap-2 text-xs text-slate-500">
              <span class="font-mono">{{ $technician->phone }}</span>
              <span class="size-1 rounded-full bg-slate-300"></span>
              {{ $technician->salary_type }}
            </div>
          </div>
        </div>
      </td>
      <td class="whitespace-nowrap">
        <span class="font-bold text-slate-700 capitalize">{{ $technician->role }}</span>
        @if($technician->designation)
          <br><span class="text-xs text-slate-400">{{ $technician->designation }}</span>
        @endif
      </td>
      <td class="whitespace-nowrap">
        <div class="font-bold text-slate-700">Rs. {{ number_format($technician->basic_salary,2) }}</div>
        <div class="text-xs text-blue-600 font-semibold">{{ $technician->commission_enabled ? $technician->commission_percentage.'% Comm.' : 'No Comm.' }}</div>
      </td>
      <td class="whitespace-nowrap">
        @php $att = $technician->attendance->first()?->status ?: 'Not marked'; @endphp
        <span class="inline-flex rounded-md px-2 py-1 text-xs font-bold {{ $att === 'Present' ? 'bg-emerald-50 text-emerald-700' : ($att === 'Absent' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600') }}">
          {{ $att }}
        </span>
      </td>
      <td class="whitespace-nowrap">
        <div class="font-bold text-slate-700">{{ $technician->month_jobs_count }} Jobs</div>
        <div class="text-xs text-emerald-600 font-bold">Rs. {{ number_format($technician->month_commission ?? 0, 2) }} Earned</div>
      </td>
      <td class="whitespace-nowrap">
        @if(($technician->advance_balance ?? 0) > 0 || ($technician->bike_balance ?? 0) > 0)
          @if(($technician->advance_balance ?? 0) > 0)
            <div class="text-xs font-bold text-orange-600">Advance: Rs. {{ number_format($technician->advance_balance, 2) }}</div>
          @endif
          @if(($technician->bike_balance ?? 0) > 0)
            <div class="text-xs font-bold text-red-600">Bike: Rs. {{ number_format($technician->bike_balance, 2) }}</div>
          @endif
        @else
          <span class="text-xs text-slate-400">Clear</span>
        @endif
      </td>
      <td class="whitespace-nowrap">
        @if($technician->active)
          <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">
            <span class="size-1.5 rounded-full bg-emerald-500"></span>Active
          </span>
        @else
          <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
            <span class="size-1.5 rounded-full bg-slate-400"></span>Inactive
          </span>
        @endif
      </td>
      <td class="text-right whitespace-nowrap">
        <div class="inline-flex items-center gap-1 rounded-lg bg-slate-50 p-1">
          <a class="flex h-7 items-center justify-center rounded-md px-2.5 text-[11px] font-bold text-slate-600 hover:bg-white hover:text-slate-900 hover:shadow-sm" href="{{ route('technicians.history',['technician'=>$technician->id]) }}">Profile</a>
          <a class="flex h-7 items-center justify-center rounded-md px-2.5 text-[11px] font-bold text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm" href="{{ route('technicians.edit', $technician->id) }}">Edit</a>
          <a class="flex h-7 items-center justify-center rounded-md px-2.5 text-[11px] font-bold text-slate-600 hover:bg-white hover:text-emerald-600 hover:shadow-sm" href="{{ route('technicians.attendance.index',['technician'=>$technician->id]) }}">Time</a>
          <a class="flex h-7 items-center justify-center rounded-md bg-red-600 px-2.5 text-[11px] font-bold text-white hover:bg-red-700 shadow-sm shadow-red-500/20" href="{{ route('technicians.salaries',['technician'=>$technician->id]) }}">Pay</a>
        </div>
      </td>
    </tr>
  @empty
    <tr>
      <td colspan="8" class="py-16 text-center">
        <div class="mx-auto grid size-14 place-items-center rounded-full bg-slate-50 text-2xl mb-3">👨‍🔧</div>
        <b class="block text-lg text-slate-700">No staff match these filters</b>
        <a href="{{ route('technicians.create') }}" class="mt-3 inline-block font-bold text-red-600 hover:underline">Add the first staff member</a>
      </td>
    </tr>
  @endforelse
  </tbody>
 </table>
 <div class="border-t border-slate-100 p-4">{{ $technicians->links() }}</div>
</div>
</div>
</x-layouts::app>
