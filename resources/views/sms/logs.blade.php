<x-layouts::app title="SMS Logs"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">SMS Logs</h1><p class="page-subtitle">View all SMS sent from the system</p></div>
  <div class="flex gap-2">
    <a href="{{ route('sms.send') }}" class="btn-primary">✉ Send SMS</a>
    <a href="{{ route('sms.dashboard') }}" class="btn-secondary">← Dashboard</a>
  </div>
</div>

<x-flash/>

{{-- Stats --}}
<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
  @foreach(['Total SMS'=>[$stats['total'],'slate'],'Sent'=>[$stats['sent'],'emerald'],'Delivered'=>[$stats['delivered'],'blue'],'Failed'=>[$stats['failed'],'red'],'Pending'=>[$stats['pending'],'amber']] as $label=>[$val,$color])
  <article class="panel text-center">
    <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-black {{ $color==='red'?'text-red-600':($color==='amber'?'text-amber-600':($color==='blue'?'text-blue-600':($color==='emerald'?'text-emerald-600':'text-slate-900'))) }}">{{ number_format($val) }}</p>
  </article>
  @endforeach
</section>

{{-- Filters --}}
<div class="panel">
  <h2 class="mb-4 font-extrabold">Filter SMS Logs</h2>
  <div class="mb-4 flex gap-1 rounded-xl bg-slate-100 p-1 overflow-x-auto">
    @foreach(['today'=>'Today','yesterday'=>'Yesterday','this_week'=>'This Week','last_week'=>'Last Week','this_month'=>'This Month','last_month'=>'Last Month'] as $key=>$label)
    <a href="{{ request()->fullUrlWithQuery(['date_range'=>$key,'page'=>null]) }}" class="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-bold {{ $range===$key?'bg-white text-slate-900 shadow':'text-slate-500 hover:bg-white/60' }}">{{ $label }}</a>
    @endforeach
  </div>
  <form method="GET" class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
    <input type="hidden" name="date_range" value="custom">
    <div><label class="label">From Date</label><input type="date" name="from" value="{{ $from }}"></div>
    <div><label class="label">To Date</label><input type="date" name="to" value="{{ $to }}"></div>
    <div><label class="label">Search</label><input type="text" name="search" value="{{ request('search') }}" placeholder="Phone, name, message..."></div>
    <div><label class="label">Status</label>
      <select name="status">
        <option value="">All Status</option>
        @foreach(['sent','delivered','failed','pending'] as $s)
        <option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst($s) }}</option>
        @endforeach
      </select>
    </div>
    <div><label class="label">Module</label>
      <select name="module">
        <option value="">All Modules</option>
        @foreach($modules as $m)
        <option value="{{ $m }}" @selected(request('module')===$m)>{{ ucwords(str_replace('_',' ',$m)) }}</option>
        @endforeach
      </select>
    </div>
    <div class="flex items-end gap-2">
      <button type="submit" class="btn-primary flex-1">Apply</button>
      <a href="{{ route('sms.logs') }}" class="btn-secondary">Reset</a>
    </div>
  </form>
</div>

{{-- Table --}}
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="overflow-x-auto">
    <table class="min-w-[900px] w-full divide-y divide-slate-100 text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th class="p-4 text-left">Date / Time</th>
          <th class="p-4 text-left">Phone</th>
          <th class="p-4 text-left">Recipient</th>
          <th class="p-4 text-left">Module</th>
          <th class="p-4 text-left">Type</th>
          <th class="p-4 text-left">Message</th>
          <th class="p-4 text-center">Status</th>
          <th class="p-4 text-left">Sent By</th>
          <th class="p-4 text-left">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($logs as $log)
        <tr class="hover:bg-slate-50/70">
          <td class="whitespace-nowrap p-4"><b>{{ $log->created_at->format('d M Y') }}</b><div class="text-xs text-slate-400">{{ $log->created_at->format('h:i A') }}</div></td>
          <td class="p-4 font-mono text-xs">{{ $log->phone_number ?? $log->phone }}</td>
          <td class="p-4">{{ $log->recipient_name ?? '—' }}</td>
          <td class="p-4 capitalize text-xs">{{ str_replace('_',' ',$log->module ?? '—') }}</td>
          <td class="p-4 text-xs">{{ $log->message_type ?? '—' }}</td>
          <td class="p-4 max-w-[200px]"><p class="line-clamp-2 text-xs text-slate-600">{{ $log->message }}</p></td>
          <td class="p-4 text-center">
            @php $s=$log->status; @endphp
            <span class="{{ $s==='sent'||$s==='delivered'?'badge-sent':($s==='failed'?'badge-failed':'badge-pending') }}">{{ ucfirst($s) }}</span>
          </td>
          <td class="p-4 text-xs">{{ $log->sender?->name ?? 'System' }}</td>
          <td class="p-4">
            @if(in_array(strtolower($log->status), ['failed', 'queued', 'pending']))
            <form method="POST" action="{{ route('sms.resend',$log) }}" class="inline">@csrf
              <button class="text-xs font-bold text-red-600 hover:underline">
                {{ in_array(strtolower($log->status), ['queued', 'pending']) ? 'Send Now' : 'Resend' }}
              </button>
            </form>
            @else<span class="text-xs text-slate-300">—</span>@endif
          </td>
        </tr>
        @empty
        <tr><td colspan="9" class="p-12 text-center text-slate-400">No SMS logs found for this period.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="p-4">{{ $logs->links() }}</div>
</div>

</div></x-layouts::app>
