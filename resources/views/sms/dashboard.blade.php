<x-layouts::app title="SMS Dashboard"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">SMS Dashboard</h1><p class="page-subtitle">Monitor SMS sending, delivery status, and message usage</p></div>
  <div class="flex gap-2">
    <a href="{{ route('sms.send') }}" class="btn-primary">✉ Send SMS</a>
    <a href="{{ route('sms.logs') }}" class="btn-secondary">View All Logs</a>
  </div>
</div>

<x-flash/>

@if(!$settings->enabled)
<div class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
  <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-500 font-black text-white">!</span>
  <div><b>SMS Gateway Disabled</b><p class="mt-1 text-sm">SMS sending is currently disabled. <a href="{{ route('sms.settings') }}" class="underline font-bold">Configure SMS Provider Settings</a> to enable it.</p></div>
</div>
@endif

{{-- Summary Cards --}}
<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
  @php
    $cards = [
      ['label'=>'SMS Sent Today','value'=>$stats['sent_today'],'color'=>'emerald','icon'=>'📤'],
      ['label'=>'SMS This Month','value'=>$stats['sent_month'],'color'=>'blue','icon'=>'📅'],
      ['label'=>'Successful','value'=>$stats['successful'],'color'=>'green','icon'=>'✅'],
      ['label'=>'Failed','value'=>$stats['failed'],'color'=>'red','icon'=>'❌'],
      ['label'=>'Pending','value'=>$stats['pending'],'color'=>'amber','icon'=>'⏳'],
    ];
  @endphp
  @foreach($cards as $c)
  <article class="rounded-2xl border bg-white p-4 shadow-sm">
    <div class="flex items-center gap-3">
      <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $c['color']==='red'?'bg-red-50 text-red-600':($c['color']==='amber'?'bg-amber-50 text-amber-600':($c['color']==='green'||$c['color']==='emerald'?'bg-emerald-50 text-emerald-600':'bg-blue-50 text-blue-600')) }} text-xl">{{ $c['icon'] }}</span>
      <div><p class="text-xs font-bold text-slate-500">{{ $c['label'] }}</p><p class="mt-0.5 text-2xl font-black text-slate-900">{{ number_format($c['value']) }}</p></div>
    </div>
  </article>
  @endforeach
  <article class="rounded-2xl border bg-slate-900 p-4 shadow-sm text-white">
    <div class="flex items-center gap-3">
      <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-white/10 text-xl">💳</span>
      <div><p class="text-xs font-bold text-slate-300">Provider Status</p><p class="mt-0.5 text-lg font-black">{{ $settings->enabled ? 'Active' : 'Disabled' }}</p></div>
    </div>
  </article>
</section>

<div class="grid gap-5 lg:grid-cols-[1fr_300px]">

  {{-- Recent SMS Activity --}}
  <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b px-5 py-4">
      <h2 class="font-extrabold text-slate-900">Recent SMS Activity</h2>
      <a href="{{ route('sms.logs') }}" class="text-sm font-bold text-red-600 hover:underline">View All →</a>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-slate-100 text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="p-4">Date/Time</th>
            <th class="p-4">Phone</th>
            <th class="p-4">Recipient</th>
            <th class="p-4">Module</th>
            <th class="p-4">Type</th>
            <th class="p-4">Status</th>
            <th class="p-4">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          @forelse($recent as $log)
          <tr class="hover:bg-slate-50/70">
            <td class="whitespace-nowrap p-4"><b>{{ $log->created_at->format('d M Y') }}</b><div class="text-xs text-slate-400">{{ $log->created_at->format('h:i A') }}</div></td>
            <td class="p-4 font-mono text-xs">{{ $log->phone_number ?? $log->phone }}</td>
            <td class="p-4">{{ $log->recipient_name ?? '—' }}</td>
            <td class="p-4 capitalize">{{ str_replace('_',' ',$log->module ?? '—') }}</td>
            <td class="p-4">{{ $log->message_type ?? '—' }}</td>
            <td class="p-4">
              @php $s = $log->status; @endphp
              <span class="{{ $s==='sent'||$s==='delivered'?'badge-sent':($s==='failed'?'badge-failed':'badge-pending') }}">{{ ucfirst($s) }}</span>
            </td>
            <td class="p-4">
              @if($log->status==='failed')
              <form method="POST" action="{{ route('sms.resend',$log) }}" class="inline">@csrf
                <button class="text-xs font-bold text-red-600 hover:underline">Resend</button>
              </form>
              @endif
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="p-10 text-center text-slate-400">No SMS activity yet. <a href="{{ route('sms.send') }}" class="text-red-600 font-bold">Send your first SMS →</a></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- SMS by Module --}}
  <div class="space-y-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="font-extrabold text-slate-900 mb-4">SMS by Module</h2>
      @forelse($byModule as $m)
      <div class="flex items-center justify-between py-2 border-b border-slate-50 last:border-0">
        <span class="text-sm font-semibold capitalize">{{ str_replace('_',' ',$m->module) }}</span>
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold">{{ $m->count }}</span>
      </div>
      @empty
      <p class="text-sm text-slate-400 text-center py-4">No data yet.</p>
      @endforelse
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="font-extrabold text-slate-900 mb-4">Quick Links</h2>
      <div class="space-y-2">
        <a href="{{ route('sms.send') }}" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 hover:bg-slate-50 transition">
          <span class="grid size-9 place-items-center rounded-lg bg-red-50 text-red-600">✉</span>
          <span class="text-sm font-bold text-slate-700">Send Manual SMS</span>
        </a>
        <a href="{{ route('sms.templates') }}" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 hover:bg-slate-50 transition">
          <span class="grid size-9 place-items-center rounded-lg bg-blue-50 text-blue-600">📋</span>
          <span class="text-sm font-bold text-slate-700">Manage Templates</span>
        </a>
        <a href="{{ route('sms.failed') }}" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 hover:bg-slate-50 transition">
          <span class="grid size-9 place-items-center rounded-lg bg-rose-50 text-rose-600">❌</span>
          <span class="text-sm font-bold text-slate-700">Failed SMS ({{ $stats['failed'] }})</span>
        </a>
        <a href="{{ route('sms.settings') }}" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 hover:bg-slate-50 transition">
          <span class="grid size-9 place-items-center rounded-lg bg-slate-100 text-slate-600">⚙</span>
          <span class="text-sm font-bold text-slate-700">Provider Settings</span>
        </a>
      </div>
    </div>
  </div>

</div>
</div></x-layouts::app>
