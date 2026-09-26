<x-layouts::app title="Failed SMS"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">Failed SMS</h1><p class="page-subtitle">Review failed messages and resend them</p></div>
  <a href="{{ route('sms.dashboard') }}" class="btn-secondary">← Dashboard</a>
</div>

<x-flash/>

{{-- Help --}}
<aside class="flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-900">
  <span class="grid size-9 shrink-0 place-items-center rounded-full bg-red-500 font-black text-white">!</span>
  <div><h2 class="font-black">About Failed SMS</h2><p class="mt-1 text-sm">This page shows messages that failed due to invalid number, provider error, insufficient balance, or API issue. Resending creates a new SMS log entry — original records are never deleted.</p></div>
</aside>

{{-- Stats --}}
<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
  @foreach(['Failed Today'=>$stats['failed_today'],'Failed This Month'=>$stats['failed_month'],'Invalid Numbers'=>$stats['invalid'],'Provider Errors'=>$stats['provider'],'Resent Successfully'=>$stats['resent']] as $label=>$val)
  <article class="panel text-center">
    <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-black text-red-600">{{ number_format($val) }}</p>
  </article>
  @endforeach
</section>

{{-- Filters --}}
<div class="panel">
  <h2 class="mb-4 font-extrabold">Filter Failed SMS</h2>
  <form method="GET" class="grid gap-4 md:grid-cols-4">
    <div><label class="label">From Date</label><input type="date" name="from" value="{{ $from }}"></div>
    <div><label class="label">To Date</label><input type="date" name="to" value="{{ $to }}"></div>
    <div><label class="label">Search Phone / Recipient</label><input type="text" name="search" value="{{ request('search') }}" placeholder="Phone or name..."></div>
    <div><label class="label">Module</label>
      <select name="module">
        <option value="">All Modules</option>
        @foreach($modules as $m)
        <option value="{{ $m }}" @selected(request('module')===$m)>{{ ucwords(str_replace('_',' ',$m)) }}</option>
        @endforeach
      </select>
    </div>
    <div class="md:col-span-4 flex gap-2">
      <button type="submit" class="btn-primary">Apply Filter</button>
      <a href="{{ route('sms.failed') }}" class="btn-secondary">Reset</a>
    </div>
  </form>
</div>

{{-- Table --}}
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="overflow-x-auto">
    <table class="min-w-[900px] w-full divide-y divide-slate-100 text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th class="p-4 text-left">Failed Date</th>
          <th class="p-4 text-left">Phone</th>
          <th class="p-4 text-left">Recipient</th>
          <th class="p-4 text-left">Module</th>
          <th class="p-4 text-left">Message</th>
          <th class="p-4 text-left">Failure Reason</th>
          <th class="p-4 text-center">Retries</th>
          <th class="p-4 text-left">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($failed as $log)
        <tr class="hover:bg-red-50/40">
          <td class="whitespace-nowrap p-4"><b>{{ $log->created_at->format('d M Y') }}</b><div class="text-xs text-slate-400">{{ $log->created_at->format('h:i A') }}</div></td>
          <td class="p-4 font-mono text-xs">{{ $log->phone_number ?? $log->phone }}</td>
          <td class="p-4 text-sm">{{ $log->recipient_name ?? '—' }}</td>
          <td class="p-4 capitalize text-xs">{{ str_replace('_',' ',$log->module ?? '—') }}</td>
          <td class="p-4 max-w-[180px]"><p class="line-clamp-2 text-xs text-slate-600">{{ $log->message }}</p></td>
          <td class="p-4 max-w-[200px]"><p class="text-xs text-red-600 font-semibold">{{ $log->failure_reason ?? $log->api_response ?? '—' }}</p></td>
          <td class="p-4 text-center"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold">{{ $log->retry_count ?? 0 }}</span></td>
          <td class="p-4">
            <form method="POST" action="{{ route('sms.resend',$log) }}" class="inline">@csrf<x-submission-token/>
              <button class="btn-primary text-xs px-3 py-1.5 min-h-0">↻ Resend</button>
            </form>
          </td>
        </tr>
        @empty
        <tr><td colspan="8" class="p-12 text-center">
          <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-emerald-50 text-2xl">✅</div>
          <p class="mt-3 font-bold text-slate-600">No failed SMS in this period!</p>
          <p class="text-sm text-slate-400 mt-1">All messages delivered successfully.</p>
        </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="p-4">{{ $failed->links() }}</div>
</div>

</div></x-layouts::app>
