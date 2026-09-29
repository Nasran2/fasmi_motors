<x-layouts::app title="{{ $bike->number }}">
@php
    $visits = $bike->intakes->sortByDesc('created_at');
    $due = $bike->intakes->map(fn ($intake) => $intake->jobCard?->invoice)->filter()->sum('due_amount');
    $summary = [
        ['Current mileage', number_format($bike->last_mileage ?? 0).' km', 'chart-bar', 'blue'],
        ['Workshop visits', $visits->count(), 'wrench-screwdriver', 'purple'],
        ['Customer due', 'Rs. '.number_format($due, 2), 'banknotes', $due > 0 ? 'red' : 'green'],
        ['Technician bike', $bike->is_technician_bike ? ($bike->technician?->name ?? 'Yes') : 'No', 'user-circle', 'amber'],
    ];
    $statusTones = [
        'Received' => 'blue', 'Assigned' => 'purple', 'Diagnosis' => 'purple',
        'In Progress' => 'blue', 'Waiting for Parts' => 'amber', 'On Hold' => 'amber',
        'Repair Ready' => 'green', 'Ready for Delivery' => 'green',
        'Washing / Final Check' => 'green', 'Completed' => 'green',
        'Billed' => 'teal', 'Pending Payment' => 'amber', 'Delivered' => 'green',
        'Cancelled' => 'red',
    ];
@endphp
<style>
    .bike-profile { max-width:1440px; margin:0 auto; padding:8px 8px 32px; color:#1e293b; }
    .bike-profile * { box-sizing:border-box; letter-spacing:0; }
    .bp-breadcrumb { display:flex; align-items:center; gap:8px; font-size:12px; color:#64748b; margin-bottom:16px; }
    .bp-breadcrumb a:hover { color:#dc2626; }
    .bp-breadcrumb svg { width:14px; height:14px; }
    .bp-header { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:24px; }
    .bp-header h1 { font-size:28px; font-weight:800; line-height:1.2; margin:0; }
    .bp-header p { font-size:13px; color:#64748b; margin-top:8px; }
    .bp-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .bp-button { min-height:40px; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:8px 14px; border:1px solid #dbe1e8; border-radius:6px; background:#fff; color:#475569; font-size:13px; font-weight:700; white-space:nowrap; }
    .bp-button svg { width:17px; height:17px; flex-shrink:0; }
    .bp-button:hover { background:#f1f5f9; }
    .bp-button.primary { background:#e51d2a; border-color:#e51d2a; color:#fff; }
    .bp-button.primary:hover { background:#c91420; }
    .bp-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:24px; }
    .bp-stat { position:relative; padding:18px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; min-height:106px; box-shadow:0 1px 2px #0f172a04; }
    .bp-stat dt { font-size:12px; font-weight:600; color:#64748b; padding-right:40px; }
    .bp-stat dd { margin:12px 38px 0 0; font-size:23px; line-height:1.3; font-weight:800; overflow-wrap:anywhere; font-variant-numeric:tabular-nums; }
    .bp-stat-icon { position:absolute; right:16px; top:18px; display:grid; place-items:center; width:34px; height:34px; border-radius:6px; }
    .bp-stat-icon svg { width:18px; height:18px; }
    .bp-stat-icon.blue { background:#eff6ff; color:#2563eb; }
    .bp-stat-icon.purple { background:#faf5ff; color:#9333ea; }
    .bp-stat-icon.red { background:#fff1f2; color:#e11d48; }
    .bp-stat-icon.green { background:#ecfdf5; color:#059669; }
    .bp-stat-icon.amber { background:#fffbeb; color:#b45309; }
    .bp-details { display:grid; grid-template-columns:minmax(0,2fr) minmax(260px,1fr); background:#fff; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; margin-bottom:24px; }
    .bp-vehicle, .bp-owner { padding:22px; min-width:0; }
    .bp-owner { border-left:1px solid #e2e8f0; }
    .bp-heading { display:flex; align-items:center; gap:8px; margin:0 0 20px; font-size:14px; font-weight:800; }
    .bp-heading svg { width:18px; height:18px; color:#64748b; }
    .bp-fields { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:22px 20px; }
    .bp-fields dt { color:#64748b; font-size:12px; }
    .bp-fields dd { font-size:13px; font-weight:600; margin:6px 0 0; overflow-wrap:anywhere; }
    .bp-owner-name { font-size:17px; font-weight:800; margin:0 0 5px; overflow-wrap:anywhere; }
    .bp-owner-type { font-size:12px; color:#64748b; }
    .bp-contact { display:flex; gap:10px; align-items:flex-start; margin-top:18px; font-size:13px; overflow-wrap:anywhere; }
    .bp-contact svg { width:16px; height:16px; color:#94a3b8; flex-shrink:0; margin-top:2px; }
    .bp-owner-link { display:inline-flex; align-items:center; gap:6px; color:#2563eb; font-size:12px; font-weight:700; margin-top:20px; }
    .bp-owner-link svg { width:14px; height:14px; }
    .bp-notes { grid-column:1/-1; padding:16px 22px; border-top:1px solid #e2e8f0; font-size:13px; color:#475569; white-space:pre-line; overflow-wrap:anywhere; }
    .bp-notes strong { display:block; margin-bottom:6px; font-size:12px; color:#64748b; }
    .bp-history { background:#fff; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; }
    .bp-history-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:20px 22px; }
    .bp-history-header .bp-heading { margin:0; }
    .bp-history-header p { font-size:12px; color:#64748b; margin-top:6px; }
    .bp-history-count { font-size:12px; color:#64748b; white-space:nowrap; }
    .bp-table-scroll { overflow-x:auto; }
    .bp-table { width:100%; min-width:1050px; border-collapse:collapse; text-align:left; font-size:13px; }
    .bp-table th { padding:12px 18px; font-size:11px; color:#64748b; font-weight:700; background:#f8fafc; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
    .bp-table td { padding:20px 18px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .bp-table tr:last-child td { border-bottom:0; }
    .bp-table tbody tr:hover { background:#fafbfc; }
    .bp-link { color:#1d4ed8; font-weight:600; overflow-wrap:anywhere; }
    .bp-link:hover,.bp-owner-link:hover { text-decoration:underline; }
    .bp-date { font-weight:600; white-space:nowrap; }
    .bp-complaint { max-width:220px; overflow-wrap:anywhere; line-height:1.5; }
    .bp-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px; font-size:11px; font-weight:600; line-height:1.4; white-space:nowrap; color:#64748b; background:#f8fafc; }
    .bp-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
    .bp-badge.blue { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .bp-badge.purple { background:#faf5ff; color:#7e22ce; border-color:#e9d5ff; }
    .bp-badge.amber { background:#fffbeb; color:#a16207; border-color:#fde68a; }
    .bp-badge.green { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .bp-badge.teal { background:#f0fdfa; color:#0f766e; border-color:#99f6e4; }
    .bp-badge.red { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
    .bp-due { margin-top:6px; color:#be123c; font-size:12px; font-weight:600; }
    .bp-paid { margin-top:6px; color:#047857; font-size:12px; }
    .bp-empty { text-align:center; padding:48px 18px !important; color:#64748b; }
    @media(max-width:1100px) { .bp-details { grid-template-columns:minmax(0,1.5fr) minmax(240px,1fr); } .bp-fields { grid-template-columns:repeat(2,minmax(0,1fr)); } .bp-stat dd { font-size:21px; margin-right:0; } }
    @media(max-width:700px) { .bike-profile { padding:4px 0 24px; } .bp-header h1 { font-size:25px; } .bp-summary { grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; } .bp-stat { padding:14px; } .bp-stat dt { padding-right:0; } .bp-stat-icon { width:28px; height:28px; right:12px; top:45px; } .bp-stat dd { font-size:19px; margin-right:30px; } .bp-details { grid-template-columns:minmax(0,1fr); } .bp-owner { border-left:0; border-top:1px solid #e2e8f0; } .bp-vehicle,.bp-owner { padding:18px; } .bp-history-header { padding:18px; } }
</style>
<div class="bike-profile">
    <nav class="bp-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('customers.bikes.index') }}">Bikes</a><flux:icon.chevron-right/><span>{{ $bike->number }}</span></nav>
    <header class="bp-header">
        <div><h1>{{ $bike->number }}</h1><p>{{ trim($bike->brand.' '.$bike->model) ?: 'Bike profile' }}</p></div>
        <div class="bp-header-actions">
            <a href="{{ route('customers.ownership', ['bike' => $bike->id]) }}" class="bp-button"><flux:icon.arrows-right-left/>Change ownership</a>
            <a href="{{ route('intakes.create', ['bike' => $bike->number]) }}" class="bp-button primary"><flux:icon.plus/>Create intake</a>
        </div>
    </header>
    <x-flash/>
    <dl class="bp-summary">
        @foreach($summary as [$label, $value, $icon, $tone])
        <div class="bp-stat"><dt>{{ $label }}</dt><dd @if($tone === 'red') style="color:#be123c" @endif>{{ $value }}</dd><span class="bp-stat-icon {{ $tone }}" aria-hidden="true"><flux:icon :name="$icon"/></span></div>
        @endforeach
    </dl>

    <div class="bp-details">
        <section class="bp-vehicle" aria-labelledby="bike-information-title">
            <h2 id="bike-information-title" class="bp-heading"><flux:icon.wrench-screwdriver/>Bike information</h2>
            <dl class="bp-fields">
                @foreach([
                    'Model' => $bike->model,
                    'Brand' => $bike->brand,
                    'Color' => $bike->color,
                    'Chassis number' => $bike->chassis_number,
                    'Engine number' => $bike->engine_number,
                    'Fuel type' => $bike->fuel_type,
                    'Previous mileage' => isset($bike->previous_mileage) ? number_format($bike->previous_mileage).' km' : null,
                ] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '-' }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="bp-owner" aria-labelledby="bike-owner-title">
            <h2 id="bike-owner-title" class="bp-heading"><flux:icon.user-circle/>Current owner</h2>
            <p class="bp-owner-name">{{ $bike->customer->name ?: 'Walk-in customer' }}</p>
            <p class="bp-owner-type">{{ $bike->customer->customer_type ?: 'Customer' }}</p>
            <div class="bp-contact"><flux:icon.phone/><span>{{ $bike->customer->phone ?: 'No phone number' }}</span></div>
            @if($bike->customer->address)<div class="bp-contact"><flux:icon.map-pin/><span>{{ $bike->customer->address }}</span></div>@endif
            <a href="{{ route('customers.show', $bike->customer) }}" class="bp-owner-link">View customer<flux:icon.arrow-right/></a>
        </section>
        @if($bike->notes)<div class="bp-notes"><strong>Notes</strong>{{ $bike->notes }}</div>@endif
    </div>

    <section class="bp-history">
        <div class="bp-history-header">
            <div><h2 class="bp-heading"><flux:icon.clock/>Service & repair history</h2><p>{{ $visits->first() ? 'Last visit: '.$visits->first()->created_at->format('d M Y') : 'No workshop visits yet' }}</p></div>
            <span class="bp-history-count">{{ number_format($visits->count()) }} {{ Str::plural('visit', $visits->count()) }}</span>
        </div>
        <div class="bp-table-scroll">
            <table class="bp-table">
                <thead><tr><th scope="col">Date</th><th scope="col">Intake</th><th scope="col">Complaint</th><th scope="col">Job card</th><th scope="col">Technician</th><th scope="col">Status</th><th scope="col">Invoice / balance</th></tr></thead>
                <tbody>
                    @forelse($visits as $intake)
                    @php
                        $job = $intake->jobCard;
                        $invoice = $job?->invoice;
                        $status = $job?->status ?? 'Intake only';
                    @endphp
                    <tr>
                        <td class="bp-date">{{ $intake->created_at->format('d M Y') }}</td>
                        <td><a href="{{ route('intakes.show', $intake) }}" class="bp-link">{{ $intake->intake_number }}</a></td>
                        <td class="bp-complaint">{{ $intake->complaint }}</td>
                        <td>@if($job)<a href="{{ route('jobs.show', $job) }}" class="bp-link">{{ $job->number }}</a>@else<span style="color:#94a3b8">-</span>@endif</td>
                        <td>{{ $job?->technician?->name ?? 'Unassigned' }}</td>
                        <td><span class="bp-badge {{ $statusTones[$status] ?? '' }}">{{ $status }}</span></td>
                        <td>
                            @if($invoice)
                            @if($invoice->public_token)<a href="{{ route('invoices.public', $invoice->public_token) }}" class="bp-link" target="_blank" rel="noopener">{{ $invoice->number }}</a>@else<span>{{ $invoice->number }}</span>@endif
                            @if($invoice->due_amount > 0)<div class="bp-due">Due Rs. {{ number_format($invoice->due_amount, 2) }}</div>@else<div class="bp-paid">Paid in full</div>@endif
                            @else<span style="color:#94a3b8">Not billed</span>@endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="bp-empty">No service or repair records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
</x-layouts::app>
