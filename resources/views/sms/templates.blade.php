<x-layouts::app title="SMS Templates"><div class="space-y-6">

<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">SMS Center</p><h1 class="page-title">SMS Templates</h1><p class="page-subtitle">Manage reusable SMS messages for system notifications</p></div>
  <a href="{{ route('sms.templates.create') }}" class="btn-primary">+ Add Template</a>
</div>

<x-flash/>

{{-- Stats --}}
<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
  @foreach(['Total Templates'=>$stats['total'],'Active Templates'=>$stats['active'],'Auto SMS Templates'=>$stats['auto'],'Manual Templates'=>$stats['manual']] as $label=>$val)
  <article class="panel text-center">
    <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-3xl font-black text-slate-900">{{ $val }}</p>
  </article>
  @endforeach
</section>

{{-- Table --}}
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-100 text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th class="p-4 text-left">Template Name</th>
          <th class="p-4 text-left">Category</th>
          <th class="p-4 text-left">Message Preview</th>
          <th class="p-4 text-center">Auto Send</th>
          <th class="p-4 text-center">Status</th>
          <th class="p-4 text-left">Updated</th>
          <th class="p-4 text-left">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        @forelse($templates as $t)
        <tr class="hover:bg-slate-50/70">
          <td class="p-4 font-bold text-slate-900">{{ $t->name }}</td>
          <td class="p-4"><span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">{{ $t->category ?? $t->event ?? '—' }}</span></td>
          <td class="p-4 max-w-sm text-slate-600"><p class="line-clamp-2 text-xs">{{ $t->body }}</p></td>
          <td class="p-4 text-center">@if($t->auto_send)<span class="badge-sent">Auto</span>@else<span class="badge-pending">Manual</span>@endif</td>
          <td class="p-4 text-center">
            <form method="POST" action="{{ route('sms.templates.toggle',$t) }}" class="inline">@csrf<x-submission-token/>
              <button type="submit" class="{{ $t->status==='active'?'badge-sent':'badge-failed' }} cursor-pointer hover:opacity-80">{{ ucfirst($t->status ?? 'active') }}</button>
            </form>
          </td>
          <td class="p-4 text-xs text-slate-400">{{ $t->updated_at->format('d M Y') }}</td>
          <td class="p-4">
            <div class="flex gap-2">
              <a href="{{ route('sms.templates.edit',$t) }}" class="text-xs font-bold text-blue-600 hover:underline">Edit</a>
              <form method="POST" action="{{ route('sms.templates.destroy',$t) }}" data-confirm="Delete this template?">@csrf<x-submission-token/> @method('DELETE')
                <button class="text-xs font-bold text-red-600 hover:underline">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        @empty
        <tr><td colspan="7" class="p-12 text-center">
          <p class="text-slate-400">No templates yet.</p>
          <a href="{{ route('sms.templates.create') }}" class="mt-3 inline-block btn-primary">+ Add First Template</a>
        </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="p-4">{{ $templates->links() }}</div>
</div>

</div></x-layouts::app>
