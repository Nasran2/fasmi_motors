<x-layouts::app :title="match($page){'upcoming'=>'Upcoming Service Reminders','sent'=>'Sent Reminders',default=>'Overdue Services'}">
@php
    $title = match ($page) {
        'upcoming' => 'Upcoming Service Reminders',
        'sent' => 'Sent Reminders',
        default => 'Overdue Services',
    };
    $metrics = [
        ['Total reminders', $records->total(), 'neutral'],
        ['SMS ready', $records->getCollection()->filter(fn ($item) => filled($item->bike?->customer?->phone))->count(), 'blue'],
        ['Pending', $records->getCollection()->where('status', 'Pending')->count(), 'amber'],
        ['SMS sent', $records->getCollection()->whereNotNull('sent_at')->count(), 'green'],
        ['Custom', $records->getCollection()->where('reminder_type', 'Custom')->count(), 'purple'],
    ];
@endphp
<style>
    .reminders-page { max-width:1440px; margin:0 auto; padding:8px 8px 32px; color:#1e293b; }
    .reminders-page *, .reminders-dialog * { box-sizing:border-box; letter-spacing:0; }
    .reminders-header { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-bottom:24px; flex-wrap:wrap; }
    .reminders-header h1 { font-size:24px; font-weight:800; line-height:1.3; margin:0; }
    .reminders-header p { font-size:13px; color:#64748b; margin-top:6px; }
    .reminders-header-actions { display:flex; gap:8px; align-items:center; }
    .reminders-button { min-height:40px; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:8px 14px; border:1px solid #dbe1e8; border-radius:6px; background:#fff; color:#475569; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; transition:background .15s, border-color .15s; }
    .reminders-button svg { width:17px; height:17px; flex-shrink:0; }
    .reminders-button:hover { background:#f1f5f9; border-color:#b8c2cf; }
    .reminders-button.primary { color:#fff; background:#e51d2a; border-color:#e51d2a; box-shadow:0 2px 4px #e51d2a15; }
    .reminders-button.primary:hover { background:#c91420; }
    .reminders-button.icon { width:40px; padding:0; flex-shrink:0; }
    .reminders-metrics { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin:0 0 24px; }
    .reminders-metric { position:relative; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:18px; display:flex; flex-direction:column; min-height:106px; box-shadow:0 1px 2px #0f172a04; }
    .reminders-metric dt { font-size:12px; font-weight:600; color:#64748b; padding-right:38px; }
    .reminders-metric dd { font-size:27px; line-height:1.2; font-weight:800; margin:10px 0 0; font-variant-numeric:tabular-nums; }
    .reminders-metric-icon { position:absolute; right:16px; top:18px; width:34px; height:34px; display:grid; place-items:center; border-radius:6px; color:#475569; background:#f1f5f9; }
    .reminders-metric-icon svg { width:18px; height:18px; }
    .reminders-metric-icon.blue { color:#2563eb; background:#eff6ff; }
    .reminders-metric-icon.amber { color:#b45309; background:#fffbeb; }
    .reminders-metric-icon.green { color:#059669; background:#ecfdf5; }
    .reminders-metric-icon.purple { color:#9333ea; background:#faf5ff; }
    .reminders-list { background:#fff; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; }
    .reminders-tabs { display:flex; gap:24px; padding:0 20px; border-bottom:1px solid #e2e8f0; }
    .reminders-tabs a { display:inline-flex; gap:8px; align-items:center; padding:16px 0; font-size:13px; font-weight:700; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-1px; }
    .reminders-tabs a:hover { color:#dc2626; }
    .reminders-tabs a[aria-current="page"] { color:#dc2626; border-color:#dc2626; }
    .reminders-tab-count { font-size:11px; padding:1px 6px; border-radius:4px; background:#fff1f2; }
    .reminders-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 20px; flex-wrap:wrap; }
    .reminders-toolbar-title { font-size:14px; font-weight:800; }
    .reminders-toolbar-title small { display:block; margin-top:4px; font-size:12px; font-weight:400; color:#64748b; }
    .reminders-tools { display:flex; align-items:center; gap:8px; }
    .reminders-search { display:flex; align-items:center; gap:6px; min-width:0; }
    .reminders-search-field { position:relative; }
    .reminders-search-field>svg { position:absolute; top:12px; left:12px; width:16px; height:16px; color:#94a3b8; pointer-events:none; }
    .reminders-page .reminders-input, .reminders-dialog .reminders-input { width:100%; min-width:0; min-height:40px; height:40px; border:1px solid #dbe1e8 !important; border-radius:6px; padding:9px 12px; background:#fff; color:#1e293b; font-size:13px; font-weight:400; }
    .reminders-page .reminders-search-field input { width:290px; padding-left:36px; background:#f8fafc; }
    .reminders-input:focus { outline:2px solid #fee2e2; outline-offset:1px; border-color:#f87171 !important; }
    .reminders-table-scroll { overflow-x:auto; }
    .reminders-table { width:100%; min-width:1000px; border-collapse:collapse; font-size:13px; text-align:left; }
    .reminders-table th { padding:12px 18px; background:#f8fafc; color:#64748b; font-size:11px; font-weight:700; white-space:nowrap; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; }
    .reminders-table td { padding:20px 18px; vertical-align:middle; border-bottom:1px solid #f1f5f9; }
    .reminders-table tbody tr:last-child td { border-bottom:0; }
    .reminders-table tbody tr:hover { background:#fafbfc; }
    .reminders-main { font-weight:700; color:#1e293b; }
    a.reminders-main { color:#1d4ed8; }
    .reminders-secondary { color:#64748b; font-size:12px; margin-top:5px; }
    .reminders-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px; font-size:11px; line-height:1.4; font-weight:600; white-space:nowrap; background:#f8fafc; color:#64748b; }
    .reminders-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
    .reminders-badge.green { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .reminders-badge.amber { background:#fffbeb; color:#a16207; border-color:#fde68a; }
    .reminders-badge.red { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
    .reminders-badge.blue { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .reminders-actions { display:flex; align-items:center; gap:6px; }
    .reminders-actions .reminders-button { min-height:34px; padding:6px 10px; font-size:12px; }
    .reminders-actions .reminders-button.icon { width:34px; padding:0; }
    .reminders-actions .send { color:#fff; border-color:#2563eb; background:#2563eb; }
    .reminders-actions .send:hover { background:#1d4ed8; }
    .reminders-actions .complete { color:#059669; }
    .reminders-actions button:disabled { opacity:.45; cursor:not-allowed; }
    .reminders-empty { text-align:center; padding:64px 16px !important; color:#64748b; }
    .reminders-empty strong { display:block; color:#334155; font-size:14px; margin-bottom:5px; }
    .reminders-pagination { padding:14px 20px; border-top:1px solid #e2e8f0; }
    .reminders-dialog { width:calc(100% - 32px); max-width:720px; max-height:90vh; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px; padding:0; margin:auto; background:#fff; color:#1e293b; box-shadow:0 24px 60px #0f172a33; }
    .reminders-dialog::backdrop { background:#0f172a80; }
    .reminders-dialog-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #e2e8f0; }
    .reminders-section-title { font-size:18px; font-weight:800; margin:0; }
    .reminders-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; padding:24px; }
    .reminders-form label { display:block; min-width:0; }
    .reminders-label { display:block; font-size:12px; font-weight:700; margin-bottom:7px; color:#475569; }
    .reminders-form .reminders-bike, .reminders-note, .reminders-form-footer { grid-column:1/-1; }
    .reminders-form-footer { display:flex; justify-content:flex-end; gap:8px; padding-top:6px; }
    @media(max-width:1100px) { .reminders-metric { padding:14px; } .reminders-metric dt { padding-right:0; } .reminders-metric-icon { right:12px; top:47px; width:28px; height:28px; } }
    @media(max-width:600px) {
        .reminders-page { padding:4px 0 24px; }
        .reminders-header { gap:14px; margin-bottom:20px; } .reminders-header h1 { font-size:21px; }
        .reminders-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .reminders-metric { min-height:94px; } .reminders-metric:last-child { grid-column:1/-1; min-height:80px; }
        .reminders-metric-icon { top:45px; } .reminders-metric:last-child .reminders-metric-icon { top:23px; }
        .reminders-tabs { gap:20px; padding:0 14px; } .reminders-tabs a { font-size:12px; }
        .reminders-toolbar { padding:16px 14px; } .reminders-tools { width:100%; }
        .reminders-search { flex:1; } .reminders-search-field { flex:1; min-width:0; }
        .reminders-page .reminders-search-field input { width:100%; }
        .reminders-form { grid-template-columns:minmax(0,1fr); padding:20px; gap:16px; }
        .reminders-dialog-header { padding:16px 20px; }
    }
    @media print { .reminders-header-actions,.reminders-tools,.reminders-tabs,.reminders-actions,.reminders-dialog { display:none !important; } .reminders-page { max-width:none; } .reminders-table { min-width:0; font-size:10px; } .reminders-table th,.reminders-table td { padding:8px; } }
</style>

<div class="reminders-page">
    <header class="reminders-header">
        <div><h1>{{ $title }}</h1><p>{{ today()->format('l, d F Y') }}</p></div>
        <div class="reminders-header-actions">
            <a href="{{ route('service-reminders.page', ['page' => 'settings']) }}" class="reminders-button icon" aria-label="Reminder settings" title="Reminder settings"><flux:icon.cog-6-tooth/></a>
            @if($page === 'upcoming')<button type="button" class="reminders-button primary" onclick="document.getElementById('new-reminder-dialog').showModal()"><flux:icon.plus/>New reminder</button>@endif
        </div>
    </header>
    <x-flash/>
    <dl class="reminders-metrics">
        @foreach($metrics as [$label, $value, $color])
        <div class="reminders-metric"><dt>{{ $label }}</dt><dd>{{ number_format($value) }}</dd><span class="reminders-metric-icon {{ $color }}" aria-hidden="true"><flux:icon :name="match($color) { 'blue' => 'paper-airplane', 'amber' => 'clock', 'green' => 'check-circle', 'purple' => 'adjustments-horizontal', default => 'bell' }"/></span></div>
        @endforeach
    </dl>

    @if($page === 'upcoming')
    <dialog id="new-reminder-dialog" class="reminders-dialog" aria-labelledby="new-reminder-title">
        <div class="reminders-dialog-header"><h2 id="new-reminder-title" class="reminders-section-title">New service reminder</h2><button type="button" class="reminders-button icon" onclick="document.getElementById('new-reminder-dialog').close()" aria-label="Close reminder form" title="Close"><flux:icon.x-mark/></button></div>
        <form method="POST" action="{{ route('service-reminders.store') }}" class="reminders-form">
            @csrf<x-submission-token/>
            <label class="reminders-bike"><span class="reminders-label">Bike / customer <span class="text-red-500">*</span></span>
                <select required name="bike_id" class="reminders-input noinput">
                    <option value="">Select bike / customer</option>
                    @foreach($bikes as $bike)
                    <option value="{{ $bike->id }}" @selected(old('bike_id') == $bike->id)>{{ $bike->number }} - {{ $bike->customer?->name }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="reminders-label">Due date</span><input type="date" name="due_date" value="{{ old('due_date', today()->addDays(30)->toDateString()) }}" class="reminders-input noinput"></label>
            <label><span class="reminders-label">Next service (km)</span><input type="number" name="due_km" min="0" value="{{ old('due_km') }}" placeholder="e.g. 25,500" class="reminders-input noinput"></label>
            <label class="reminders-bike"><span class="reminders-label">Reminder type</span><select name="reminder_type" class="reminders-input noinput">@foreach(['Date Based', 'KM Based', 'Custom'] as $type)<option @selected(old('reminder_type', 'Date Based') === $type)>{{ $type }}</option>@endforeach</select></label>
            <label class="reminders-note"><span class="reminders-label">Note <span class="font-normal text-slate-400">(optional)</span></span><input name="note" maxlength="1000" value="{{ old('note') }}" class="reminders-input noinput" placeholder="Add a note"></label>
            <div class="reminders-form-footer"><button type="button" class="reminders-button" onclick="document.getElementById('new-reminder-dialog').close()">Cancel</button><button type="submit" class="reminders-button primary"><flux:icon.plus/>Create reminder</button></div>
        </form>
    </dialog>
    @if(old('bike_id'))<script>document.getElementById('new-reminder-dialog').showModal();</script>@endif
    @endif

    <section class="reminders-list">
    <nav class="reminders-tabs" aria-label="Service reminders">
        @foreach(['upcoming' => 'Upcoming', 'sent' => 'Sent reminders', 'overdue' => 'Overdue'] as $key => $label)
        <a href="{{ route('service-reminders.page', ['page' => $key]) }}" @if($page === $key) aria-current="page" @endif>{{ $label }} @if($page === $key)<span class="reminders-tab-count">{{ number_format($records->total()) }}</span>@endif</a>
        @endforeach
    </nav>
    <div class="reminders-toolbar">
        <div class="reminders-toolbar-title">{{ match($page) { 'upcoming' => 'Upcoming reminders', 'sent' => 'Sent reminders', default => 'Overdue reminders' } }}<small>{{ $records->total() ? $records->firstItem().' - '.$records->lastItem().' of '.number_format($records->total()) : '0' }} {{ Str::plural('reminder', $records->total()) }}</small></div>
        <div class="reminders-tools">
        <form method="GET" class="reminders-search" role="search">
            <div class="reminders-search-field"><flux:icon.magnifying-glass/><input type="search" name="q" value="{{ request('q') }}" aria-label="Search reminders" placeholder="Bike, customer or phone" class="reminders-input noinput"></div>
            <button type="submit" class="reminders-button icon" aria-label="Search" title="Search"><flux:icon.magnifying-glass/></button>
        </form>
        @if(request()->filled('q'))<a href="{{ url()->current() }}" class="reminders-button icon" aria-label="Clear search" title="Clear search"><flux:icon.x-mark/></a>@endif
        <button type="button" onclick="window.print()" class="reminders-button icon" aria-label="Print reminders" title="Print reminders"><flux:icon.printer/></button>
        </div>
    </div>

        <div class="reminders-table-scroll">
            <table class="reminders-table">
                <thead><tr><th scope="col">Due date</th><th scope="col">Bike</th><th scope="col">Customer / phone</th><th scope="col">Last service</th><th scope="col">Next service</th><th scope="col">SMS status</th><th scope="col">Priority</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @forelse($records as $reminder)
                    @php
                        $smsStatus = strtolower($reminder->sms_status ?? 'Not Sent');
                        $smsColor = match ($smsStatus) { 'sent', 'delivered', 'success' => 'green', 'failed', 'error' => 'red', 'pending', 'queued' => 'amber', default => '' };
                        $priorityColor = match (strtolower($reminder->priority ?? '')) { 'high', 'urgent' => 'red', 'medium', 'normal' => 'amber', 'low' => 'blue', default => '' };
                        $isOverdue = $reminder->status === 'Pending' && $reminder->due_date?->lt(today());
                    @endphp
                    <tr>
                        <td><div class="reminders-main" @if($isOverdue) style="color:#be123c" @endif>{{ $reminder->due_date?->format('d M Y') ?: 'KM based' }}</div>@if($isOverdue)<div class="reminders-secondary" style="color:#be123c">Overdue</div>@endif</td>
                        <td><a class="reminders-main" href="{{ route('customers.bikes.show', $reminder->bike_id) }}">{{ $reminder->bike?->number }}</a><div class="reminders-secondary">{{ $reminder->bike?->model }}</div></td>
                        <td><div class="reminders-main">{{ $reminder->bike?->customer?->name ?: 'Walk-in customer' }}</div><div class="reminders-secondary">{{ $reminder->bike?->customer?->phone ?: 'No phone' }}</div></td>
                        <td><div>{{ $reminder->last_service_date?->format('d M Y') ?: '-' }}</div><div class="reminders-secondary">{{ $reminder->last_service_km ? number_format($reminder->last_service_km).' km' : '-' }}</div></td>
                        <td><div>{{ $reminder->due_km ? number_format($reminder->due_km).' km' : '-' }}</div><div class="reminders-secondary">{{ $reminder->reminder_type }}</div></td>
                        <td><span class="reminders-badge {{ $smsColor }}">{{ $reminder->sms_status }}</span></td>
                        <td><span class="reminders-badge {{ $priorityColor }}">{{ $reminder->priority ?: '-' }}</span></td>
                        <td><div class="reminders-actions">
                            @if($reminder->status === 'Pending')
                            <form method="POST" action="{{ route('service-reminders.send', $reminder) }}">@csrf<x-submission-token/><button type="submit" class="reminders-button send" @disabled(blank($reminder->bike?->customer?->phone)) title="{{ blank($reminder->bike?->customer?->phone) ? 'Customer has no phone number' : 'Send reminder SMS' }}"><flux:icon.paper-airplane/>Send SMS</button></form>
                            <form method="POST" action="{{ route('service-reminders.complete', $reminder) }}">@csrf<x-submission-token/><button type="submit" class="reminders-button icon complete" aria-label="Mark completed" title="Mark completed"><flux:icon.check-circle/></button></form>
                            @endif
                            <a href="{{ route('customers.bikes.show', $reminder->bike_id) }}" class="reminders-button icon" aria-label="Bike history" title="Bike history"><flux:icon.arrow-top-right-on-square/></a>
                        </div></td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="reminders-empty"><strong>No reminders found</strong>{{ request()->filled('q') ? 'No matches for your search.' : 'There are no reminders in this list.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($records->hasPages())<div class="reminders-pagination">{{ $records->links() }}</div>@endif
    </section>
</div>
</x-layouts::app>
