@php
    $accounts = \App\Models\Account::where('active', 1)->get();
    $columns = [
        'new_intake' => ['New Intake', '#2563eb', 'bike'],
        'diagnosis' => ['Diagnosis', '#f97316', 'search'],
        'waiting_parts' => ['Waiting for Parts', '#7c3aed', 'parts'],
        'in_repair' => ['In Repair', '#ed142b', 'tools'],
        'washing' => ['Washing / Final Check', '#0891b2', 'wash'],
        'ready' => ['Ready for Delivery', '#16a34a', 'check'],
        'on_hold' => ['On Hold', '#64748b', 'pause'],
        'pending_payment' => ['Pending Payment', '#be123c', 'wallet'],
    ];
    $summary = [
        'total' => ['Active Bikes', '#2563eb', 'bike', 'All bikes currently inside'],
        'new_intake' => ['New Intake', '#0ea5e9', 'plus', 'Awaiting job card'],
        'waiting_parts' => ['Waiting for Parts', '#7c3aed', 'parts', 'Parts action needed'],
        'in_repair' => ['In Repair', '#ed142b', 'tools', 'Work in progress'],
        'washing' => ['Washing / Final Check', '#0891b2', 'wash', 'Almost ready'],
        'ready' => ['Ready for Delivery', '#16a34a', 'check', 'Ready for handover'],
        'pending_payment' => ['Pending Payment', '#be123c', 'wallet', 'Payment action needed'],
        'on_hold' => ['On Hold', '#64748b', 'pause', 'Waiting for action'],
    ];
    $icon = function (string $name, string $class = 'size-5'): string {
        $paths = [
            'bike' => '<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-7h3l5 7M9 13h6M8 7h3l2 3m2-3h3"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
            'parts' => '<path d="M14.7 6.3a4 4 0 0 0-5 5L3.5 17.5a2.1 2.1 0 0 0 3 3l6.2-6.2a4 4 0 0 0 5-5l-2.4 2.4-3-3 2.4-2.4Z"/>',
            'tools' => '<path d="m14.7 6.3-2.4 2.4 3 3 2.4-2.4a4 4 0 0 1-5 5l-6.2 6.2a2.1 2.1 0 0 1-3-3l6.2-6.2a4 4 0 0 1 5-5Z"/>',
            'wash' => '<path d="M12 3s5 5.5 5 10a5 5 0 0 1-10 0c0-4.5 5-10 5-10Z"/><path d="M9.5 14.5a3 3 0 0 0 3 1.5"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'pause' => '<path d="M9 5v14M15 5v14"/>',
            'wallet' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H19v16H6.5A2.5 2.5 0 0 1 4 17.5v-11Z"/><path d="M4 8h15M15 13h4"/>',
        ];
        return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name] ?? $paths['bike']).'</svg>';
    };
@endphp

<style>
    #live-bike-board .board-scroll { scrollbar-color:#cbd5e1 transparent; scrollbar-width:thin; }
    #live-bike-board .board-column { transition:border-color .2s, box-shadow .2s, transform .2s; }
    #live-bike-board .board-column.is-over { border-color:var(--column-color); box-shadow:0 0 0 3px color-mix(in srgb,var(--column-color) 16%,transparent); transform:translateY(-2px); }
    #live-bike-board .bike-card { animation:board-card-in .3s ease both; }
    #live-bike-board .bike-card.is-dragging { opacity:.45; transform:rotate(1deg) scale(.98); }
    #live-bike-board .urgent-card { animation:urgent-pulse 2.8s ease-in-out infinite; }
    #live-bike-board .payment-card { box-shadow:0 0 0 1px rgba(190,18,60,.14),0 8px 26px rgba(190,18,60,.08); }
    #live-bike-board .filter-chip.is-active { border-color:#ed142b; background:#ed142b; color:white; box-shadow:0 8px 18px rgba(237,20,43,.2); }
    #live-bike-board details[open] > summary { background:#f8fafc; }
    #live-bike-board:not([data-mobile-status="all"]) .board-grid { min-width:0; grid-template-columns:minmax(280px,1fr); }
    #live-bike-board:not([data-mobile-status="all"]) .board-column { display:none; }
    @foreach(array_keys($columns) as $key)
    #live-bike-board[data-mobile-status="{{ $key }}"] .board-column[data-board-column="{{ $key }}"] { display:block; }
    @endforeach
    #quick-calculator::backdrop { background:rgba(2,10,24,.62); backdrop-filter:blur(4px); }
    @keyframes board-card-in { from { opacity:0; transform:translateY(8px) } }
    @keyframes urgent-pulse { 0%,100% { box-shadow:0 0 0 1px rgba(237,20,43,.24),0 7px 22px rgba(15,23,42,.06) } 50% { box-shadow:0 0 0 3px rgba(237,20,43,.18),0 10px 28px rgba(237,20,43,.12) } }
</style>

<main id="live-bike-board" data-fullscreen="{{ $fullscreen ? '1' : '0' }}" data-mobile-status="all" class="min-w-0 space-y-5 {{ $fullscreen ? 'min-h-screen p-4 lg:p-6' : '' }}">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div>
            @if($fullscreen)<p class="mb-1 text-[11px] font-black uppercase tracking-[.24em] text-red-600">Fasmir Motors · Workshop Display</p>@endif
            <h1 class="text-2xl font-black tracking-[-.035em] text-[#07182f] lg:text-[28px]">{{ $fullscreen ? 'Fasmir Motors Live Workshop Board' : 'Live Bike Status Board' }}</h1>
            <p class="mt-1 text-sm font-medium text-slate-500">See all active bikes at a glance with visual job progress</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex h-10 items-center gap-2 rounded-xl bg-[#07182f] px-3.5 text-xs font-bold text-white"><span class="size-2 rounded-full bg-emerald-400"></span><strong data-active-total>0</strong> active</span>
            <span id="board-clock" class="h-10 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-bold text-slate-600 shadow-sm"></span>
            @unless($fullscreen)
                <button type="button" data-calculator-open class="inline-flex h-11 items-center gap-2 rounded-xl bg-[#ed142b] px-4 text-sm font-extrabold text-white shadow-[0_10px_24px_rgba(237,20,43,.24)] transition hover:-translate-y-0.5 hover:bg-red-700">{!! $icon('plus', 'size-4') !!} Quick Calculator</button>
                <a href="{{ route('live-board.fullscreen') }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-extrabold text-[#07182f] shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300">Open Full Board <span aria-hidden="true">↗</span></a>
            @else
                <span class="inline-flex h-10 items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 text-xs font-bold text-emerald-700"><span class="size-2 animate-pulse rounded-full bg-emerald-500"></span>Refreshes every 30s</span>
                <button type="button" onclick="document.documentElement.requestFullscreen()" class="h-10 rounded-xl bg-[#07182f] px-4 text-xs font-bold text-white">Enter Fullscreen</button>
            @endunless
        </div>
    </header>

    @unless($fullscreen)
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-8" aria-label="Board summary">
        @foreach($summary as $key => [$label, $color, $iconName, $note])
            <article class="group rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-[0_8px_28px_rgba(15,35,65,.055)] transition hover:-translate-y-0.5 hover:shadow-lg">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl text-white shadow-md" style="background:{{ $color }};box-shadow:0 8px 18px color-mix(in srgb,{{ $color }} 24%,transparent)">{!! $icon($iconName) !!}</span>
                    <div class="min-w-0"><p class="truncate text-[11px] font-bold text-slate-500">{{ $label }}</p><strong data-summary="{{ $key }}" class="mt-0.5 block text-2xl font-black tracking-tight text-slate-950">0</strong></div>
                </div>
                <p class="mt-2 truncate pl-14 text-[10px] font-semibold" style="color:{{ $color }}">{{ $note }}</p>
            </article>
        @endforeach
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_8px_28px_rgba(15,35,65,.05)]">
        <div class="flex flex-wrap items-center gap-2">
            <label class="relative min-w-[240px] flex-1"><span class="sr-only">Search board</span><svg class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input data-board-search type="search" placeholder="Search bike, customer, phone, job card..." class="h-10 w-full !border-slate-200 !bg-slate-50 !py-2 !pl-9 text-xs"></label>
            <select data-board-technician class="h-10 !w-auto min-w-40 !border-slate-200 !bg-white !py-2 text-xs"><option value="">All technicians</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}">{{ $technician->name }}</option>@endforeach</select>
            <select data-board-priority class="h-10 !w-auto min-w-36 !border-slate-200 !bg-white !py-2 text-xs"><option value="">All priorities</option><option>Urgent</option><option>High</option><option>Normal</option><option>Low</option></select>
            <select data-board-status class="h-10 !w-auto min-w-40 !border-slate-200 !bg-white !py-2 text-xs"><option value="">All statuses</option>@foreach($columns as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            @foreach([['due_today','Due today'],['waiting_parts','Waiting for parts'],['payment_pending','Payment pending']] as [$filter,$label])
                <button type="button" data-toggle-filter="{{ $filter }}" aria-pressed="false" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-600 transition hover:border-red-200 hover:text-red-600">{{ $label }}</button>
            @endforeach
        </div>
        <div class="mt-3 flex items-center gap-2 overflow-x-auto pb-1">
            <button type="button" data-status-chip="" class="filter-chip is-active shrink-0 rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-600 transition">All</button>
            @foreach($columns as $key => [$label])<button type="button" data-status-chip="{{ $key }}" class="filter-chip shrink-0 rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-600 transition">{{ $label }}</button>@endforeach
            <span class="ml-auto shrink-0 text-[10px] font-semibold text-slate-400">Live update: <strong data-refresh-state class="text-emerald-600">connected</strong></span>
        </div>
    </section>
    @endunless

    <section aria-label="Live bike status columns">
        <div class="board-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 w-full">
            @foreach($columns as $key => [$label, $color, $iconName])
                <article data-board-column="{{ $key }}" class="board-column rounded-2xl border border-slate-200/90 p-2.5" style="--column-color:{{ $color }};background:linear-gradient(180deg,color-mix(in srgb,{{ $color }} 7%,white),rgba(255,255,255,.68))">
                    <div class="mb-2 flex items-center justify-between px-1 py-1.5">
                        <h2 class="flex min-w-0 items-center gap-2 text-[12px] font-black" style="color:{{ $color }}"><span class="grid size-7 shrink-0 place-items-center rounded-lg bg-white shadow-sm">{!! $icon($iconName, 'size-4') !!}</span><span class="truncate">{{ $label }}</span></h2>
                        <span data-count="{{ $key }}" class="ml-2 rounded-full bg-white px-2 py-0.5 text-[10px] font-black text-slate-600 shadow-sm">0</span>
                    </div>
                    <div data-column="{{ $key }}" class="min-h-52 space-y-2.5">
                        @for($i=0;$i<2;$i++)<div class="h-48 animate-pulse rounded-xl border border-slate-200 bg-white/80 p-3"><div class="h-8 rounded-lg bg-slate-100"></div><div class="mt-3 h-3 w-3/4 rounded bg-slate-100"></div><div class="mt-2 h-3 w-1/2 rounded bg-slate-100"></div><div class="mt-8 h-8 rounded-lg bg-slate-100"></div></div>@endfor
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <aside class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-[10px] font-bold text-slate-500 shadow-sm" aria-label="Board legend">
        <strong class="text-xs text-[#07182f]">Legend</strong>
        <span>🔥 Urgent / High Priority</span><span>⏱ Due Today</span><span>📦 Parts Shortage</span><span>👤 Waiting for Customer</span><span>⚠ Payment Due</span><span>↔ Technician Changed</span><span>✓ SMS Sent</span>
    </aside>
</main>

<dialog id="quick-calculator" class="m-auto w-[min(94vw,720px)] rounded-3xl border-0 bg-white p-0 text-slate-900 shadow-2xl">
    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between"><div><p class="text-[10px] font-black uppercase tracking-[.2em] text-red-600">Workshop utility</p><h2 class="mt-1 text-xl font-black text-[#07182f]">Quick Calculator</h2><p class="text-xs text-slate-500">Basic calculations and instant repair totals</p></div><button type="button" data-calculator-close class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button></div>
        <div class="mt-5 grid gap-5 md:grid-cols-[.85fr_1.15fr]">
            <section>
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500">Calculator display<input data-basic-display readonly value="0" class="mt-1.5 h-14 w-full !bg-[#07182f] !px-4 text-right !text-xl !font-black !text-white"></label>
                <div class="mt-3 grid grid-cols-4 gap-2">
                    @foreach(['C','⌫','%','÷','7','8','9','×','4','5','6','−','1','2','3','+','0','.','copy','='] as $key)
                        <button type="button" data-calc-key="{{ $key }}" class="h-10 rounded-xl {{ in_array($key,['÷','×','−','+','=']) ? 'bg-[#07182f] text-white' : ($key === 'C' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-700') }} text-xs font-black transition hover:-translate-y-0.5">{{ $key === 'copy' ? 'Copy' : $key }}</button>
                    @endforeach
                </div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <h3 class="text-sm font-black text-[#07182f]">Repair total</h3>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <label class="text-[10px] font-bold text-slate-600">Labor amount<input data-calc="labor" type="number" min="0" value="0" class="mt-1 h-9 w-full !bg-white !py-1.5 text-xs"></label>
                    <label class="text-[10px] font-bold text-slate-600">Parts amount<input data-calc="parts" type="number" min="0" value="0" class="mt-1 h-9 w-full !bg-white !py-1.5 text-xs"></label>
                    <label class="text-[10px] font-bold text-slate-600">Discount type<select data-calc="discount_type" class="mt-1 h-9 w-full !bg-white !py-1.5 text-xs"><option value="percentage">Percentage</option><option value="fixed">Fixed</option></select></label>
                    <label class="text-[10px] font-bold text-slate-600">Discount value<input data-calc="discount" type="number" min="0" value="0" class="mt-1 h-9 w-full !bg-white !py-1.5 text-xs"></label>
                    <label class="col-span-2 text-[10px] font-bold text-slate-600">Paid amount<input data-calc="paid" type="number" min="0" value="0" class="mt-1 h-9 w-full !bg-white !py-1.5 text-xs"></label>
                </div>
                <dl class="mt-3 space-y-2 rounded-xl bg-white p-3 text-xs"><div class="flex justify-between"><dt>Parts + labor</dt><dd data-calc-gross class="font-bold">Rs. 0.00</dd></div><div class="flex justify-between"><dt>Total after discount</dt><dd data-calc-total class="font-black">Rs. 0.00</dd></div><div class="flex justify-between border-t border-dashed pt-2 text-sm"><dt class="font-bold">Due balance</dt><dd data-calc-balance class="font-black text-red-600">Rs. 0.00</dd></div></dl>
            </section>
        </div>
        <div class="mt-5 flex justify-end gap-2"><button type="button" data-calculator-close class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-600">Close</button><button type="button" data-copy-result class="rounded-xl bg-[#ed142b] px-4 py-2 text-xs font-bold text-white">Copy Result</button></div>
    </div>
</dialog>

{{-- Status Change Confirmation Dialog --}}
<dialog id="status-confirm-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 24rem !important;">
    <div class="p-5 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-red-50 text-red-600 text-2xl mb-3">❓</span>
        <h3 class="text-base font-extrabold text-slate-900" style="color: #0f172a !important;">Confirm Status Change</h3>
        <p class="mt-2 text-xs text-slate-500 font-semibold leading-relaxed" id="status-confirm-text" style="color: #64748b !important;">Are you sure you want to change the status of this job?</p>
        <div class="mt-5 flex justify-center gap-2">
            <button type="button" id="status-confirm-cancel" class="rounded-xl px-4 py-2.5 text-xs font-bold transition-colors" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button type="button" id="status-confirm-ok" class="rounded-xl bg-red-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-red-700 transition-colors shadow-lg shadow-red-100">Yes, Change</button>
        </div>
    </div>
</dialog>

<script>
(() => {
    const root = document.getElementById('live-bike-board');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';
    const labels = @json(collect($columns)->map(fn ($column) => $column[0]));
    const colors = @json(collect($columns)->map(fn ($column) => $column[1]));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const money = value => `Rs. ${Number(value || 0).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    const filters = {status:'', due_today:false, waiting_parts:false, payment_pending:false};
    let searchTimer;
    let draggedJob = null;

    function actionMenu(job) {
        if (job.is_intake) return `<a href="${job.job_url}" class="mt-3 flex h-9 items-center justify-center rounded-lg bg-[#07182f] text-[10px] font-bold text-white">Create Job Card →</a>`;
        return `<details class="relative mt-3"><summary class="flex h-8 cursor-pointer list-none items-center justify-between rounded-lg border border-slate-200 px-2 text-[10px] font-bold text-slate-600">Quick actions <span>•••</span></summary><div class="absolute right-0 z-20 mt-1 grid w-48 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 text-[10px] font-semibold shadow-xl"><a class="rounded-lg px-3 py-2 hover:bg-slate-50" href="${job.job_url}">View / update job card</a><a class="rounded-lg px-3 py-2 hover:bg-slate-50" href="${job.job_url}#spare-parts">Add parts</a><a class="rounded-lg px-3 py-2 hover:bg-slate-50" href="${job.job_url}">Assign technician / add note</a><a class="rounded-lg px-3 py-2 hover:bg-slate-50" href="${job.bill_url}">Create bill</a><a class="rounded-lg px-3 py-2 hover:bg-slate-50" href="${job.sms_url}">Send SMS</a><button type="button" data-quick-status="ready" data-job="${job.id}" class="rounded-lg px-3 py-2 text-left hover:bg-slate-50">Move to ready</button><button type="button" data-quick-status="on_hold" data-job="${job.id}" class="rounded-lg px-3 py-2 text-left hover:bg-slate-50">Mark on hold</button></div></details>`;
    }

    function card(job, index) {
        const priority = ['Urgent','High'].includes(job.priority) ? `<span class="rounded-md bg-red-50 px-1.5 py-1 text-[9px] font-black text-red-600">🔥 ${esc(job.priority)}</span>` : '';
        const warning = job.due_amount > 0 ? `<span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-1 text-[9px] font-black text-rose-700"><span>⚠ ${money(job.due_amount)} due</span><button type="button" onclick="openDuePaymentModal(${job.invoice_id}, ${job.due_amount}, '${job.invoice_number}')" class="rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[9px] font-extrabold px-1.5 py-0.5 leading-none ml-1 transition-colors">Pay</button></span>` : '';
        const shortage = job.parts_shortage ? `<span class="rounded-md bg-violet-50 px-1.5 py-1 text-[9px] font-black text-violet-700">📦 ${job.parts_shortage} part${job.parts_shortage > 1 ? 's' : ''} short</span>` : '';
        const due = job.due_today ? '<span class="rounded-md bg-orange-50 px-1.5 py-1 text-[9px] font-black text-orange-600">⏱ Due today</span>' : (job.due ? `<span class="rounded-md bg-slate-50 px-1.5 py-1 text-[9px] font-bold text-slate-500">Due ${esc(job.due)}</span>` : '');
        const statusControl = job.is_intake ? '' : `<select data-status-job="${job.id}" data-current-status="${job.status}" class="mt-2.5 h-8 w-full !border-slate-200 !bg-white !px-2 !py-1 text-[10px] font-bold" style="color:${colors[job.status]}">${Object.entries(labels).map(([key,label]) => `<option value="${key}" ${key === job.status ? 'selected' : ''}>${label}</option>`).join('')}</select>`;
        return `<div draggable="${job.is_intake ? 'false' : 'true'}" data-job-card="${job.is_intake ? '' : job.id}" class="bike-card group rounded-xl border border-slate-200 bg-white p-3 shadow-[0_7px_22px_rgba(15,23,42,.055)] transition hover:-translate-y-0.5 hover:shadow-md ${['Urgent','High'].includes(job.priority) ? 'urgent-card' : ''} ${job.due_amount > 0 ? 'payment-card' : ''}" style="animation-delay:${Math.min(index * 35, 280)}ms">
            <div class="flex items-start gap-2.5"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl">🏍</span><div class="min-w-0 flex-1"><a href="${job.job_url}" class="block truncate text-[13px] font-black tracking-tight text-[#07182f] hover:text-red-600">${esc(job.bike_number)}</a><p class="truncate text-[10px] font-semibold text-slate-500">${esc(job.model)}</p><p class="mt-0.5 truncate text-[10px] font-bold text-slate-700">${esc(job.customer)}</p></div>${priority}</div>
            <p class="mt-2.5 line-clamp-2 min-h-7 text-[10px] leading-4 text-slate-600"><span class="font-bold text-slate-400">Complaint:</span> ${esc(job.complaint)}</p>
            <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-slate-100 pt-2 text-[9px]"><span class="flex min-w-0 items-center gap-1.5 font-bold text-slate-700"><b class="grid size-6 shrink-0 place-items-center rounded-full bg-[#07182f] text-[9px] text-white">${esc(job.technician_initial)}</b><span class="truncate">${esc(job.technician)}</span></span><span class="shrink-0 font-black text-slate-400">${esc(job.number)}</span></div>
            <div class="mt-2 flex flex-wrap gap-1">${shortage}${warning}${due}</div>${statusControl}${actionMenu(job)}
        </div>`;
    }

    function queryParams() {
        const params = new URLSearchParams();
        const search = root.querySelector('[data-board-search]')?.value;
        const technician = root.querySelector('[data-board-technician]')?.value;
        const priority = root.querySelector('[data-board-priority]')?.value;
        if (search) params.set('q', search);
        if (technician) params.set('technician_id', technician);
        if (priority) params.set('priority', priority);
        Object.entries(filters).forEach(([key,value]) => { if (value) params.set(key, value === true ? '1' : value); });
        return params;
    }

    async function loadBoard() {
        const state = root.querySelector('[data-refresh-state]');
        if (state) { state.textContent = 'refreshing'; state.className = 'animate-pulse text-amber-600'; }
        try {
            const response = await fetch(`{{ route('live-board.data') }}?${queryParams()}`, {headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Unable to load board');
            const data = await response.json();
            root.querySelector('[data-active-total]').textContent = data.total;
            root.querySelector('[data-summary="total"]')?.replaceChildren(document.createTextNode(data.total));
            Object.keys(labels).forEach(key => {
                const jobs = data.groups[key] || [];
                root.querySelector(`[data-summary="${key}"]`)?.replaceChildren(document.createTextNode(jobs.length));
                root.querySelector(`[data-count="${key}"]`).textContent = jobs.length;
                root.querySelector(`[data-column="${key}"]`).innerHTML = jobs.length ? jobs.map(card).join('') : `<div class="grid min-h-36 place-items-center rounded-xl border border-dashed border-slate-200 bg-white/60 p-4 text-center"><div><span class="mx-auto grid size-9 place-items-center rounded-full bg-white text-lg shadow-sm">🏍</span><p class="mt-2 text-[10px] font-bold text-slate-400">No bikes in this status</p></div></div>`;
            });
            if (state) { state.textContent = 'connected'; state.className = 'text-emerald-600'; }
        } catch (error) {
            if (state) { state.textContent = 'retrying'; state.className = 'text-red-600'; }
        }
    }

    function showStatusConfirm(jobId, status) {
        return new Promise((resolve) => {
            const dialog = document.getElementById('status-confirm-dialog');
            const confirmBtn = document.getElementById('status-confirm-ok');
            const cancelBtn = document.getElementById('status-confirm-cancel');
            
            document.getElementById('status-confirm-text').textContent = `Change job status to ${labels[status]}?`;
            
            const onConfirm = () => {
                cleanup();
                resolve(true);
            };
            
            const onCancel = () => {
                cleanup();
                resolve(false);
            };
            
            const cleanup = () => {
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
                dialog.close();
            };
            
            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click', onCancel);
            
            dialog.showModal();
        });
    }

    async function changeStatus(jobId, status, previousStatus = null) {
        const confirmed = await showStatusConfirm(jobId, status);
        if (!confirmed) return false;
        const response = await fetch(`{{ url('/workshop/live-board/jobs') }}/${jobId}/status`, {method:'PATCH', headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({status})});
        if (!response.ok) { alert('Status could not be updated. Please try again.'); return false; }
        await loadBoard();
        return true;
    }

    root.addEventListener('change', async event => {
        if (event.target.matches('[data-status-job]')) {
            const select = event.target;
            const previous = select.dataset.currentStatus;
            select.disabled = true;
            if (!await changeStatus(select.dataset.statusJob, select.value, previous)) { select.value = previous; select.disabled = false; }
        } else if (event.target.matches('[data-board-technician],[data-board-priority]')) loadBoard();
        else if (event.target.matches('[data-board-status]')) {
            filters.status = event.target.value;
            root.dataset.mobileStatus = filters.status || 'all';
            root.querySelectorAll('[data-status-chip]').forEach(chip => chip.classList.toggle('is-active', chip.dataset.statusChip === filters.status));
            loadBoard();
        }
    });
    root.addEventListener('click', event => {
        const chip = event.target.closest('[data-status-chip]');
        const toggle = event.target.closest('[data-toggle-filter]');
        const quick = event.target.closest('[data-quick-status]');
        if (chip) {
            filters.status = chip.dataset.statusChip;
            root.dataset.mobileStatus = filters.status || 'all';
            root.querySelector('[data-board-status]').value = filters.status;
            root.querySelectorAll('[data-status-chip]').forEach(item => item.classList.toggle('is-active', item === chip));
            loadBoard();
        } else if (toggle) {
            const key = toggle.dataset.toggleFilter;
            filters[key] = !filters[key];
            toggle.setAttribute('aria-pressed', String(filters[key]));
            toggle.classList.toggle('!border-red-500', filters[key]); toggle.classList.toggle('!bg-red-50', filters[key]); toggle.classList.toggle('!text-red-600', filters[key]);
            loadBoard();
        } else if (quick) changeStatus(quick.dataset.job, quick.dataset.quickStatus);
    });
    root.querySelector('[data-board-search]')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadBoard, 300); });

    root.addEventListener('dragstart', event => { const card = event.target.closest('[data-job-card]'); if (!card?.dataset.jobCard) return; draggedJob = card.dataset.jobCard; card.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; });
    root.addEventListener('dragend', event => { event.target.closest('[data-job-card]')?.classList.remove('is-dragging'); root.querySelectorAll('.board-column').forEach(column => column.classList.remove('is-over')); draggedJob = null; });
    root.querySelectorAll('.board-column').forEach(column => {
        column.addEventListener('dragover', event => { if (!draggedJob) return; event.preventDefault(); column.classList.add('is-over'); });
        column.addEventListener('dragleave', event => { if (!column.contains(event.relatedTarget)) column.classList.remove('is-over'); });
        column.addEventListener('drop', async event => { event.preventDefault(); column.classList.remove('is-over'); if (draggedJob) await changeStatus(draggedJob, column.dataset.boardColumn); });
    });

    const dialog = document.getElementById('quick-calculator');
    document.querySelectorAll('[data-calculator-open]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog?.querySelectorAll('[data-calculator-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    const repairValue = key => Number(dialog?.querySelector(`[data-calc="${key}"]`)?.value || 0);
    function calculateRepair() { const gross=repairValue('labor')+repairValue('parts'); const discount=dialog.querySelector('[data-calc="discount_type"]').value === 'fixed' ? repairValue('discount') : gross*repairValue('discount')/100; const total=Math.max(0,gross-discount); const balance=Math.max(0,total-repairValue('paid')); dialog.querySelector('[data-calc-gross]').textContent=money(gross); dialog.querySelector('[data-calc-total]').textContent=money(total); dialog.querySelector('[data-calc-balance]').textContent=money(balance); }
    dialog?.querySelectorAll('[data-calc]').forEach(input => input.addEventListener('input', calculateRepair));
    dialog?.querySelector('[data-calc="discount_type"]')?.addEventListener('change', calculateRepair);
    dialog?.querySelector('[data-copy-result]')?.addEventListener('click', () => navigator.clipboard.writeText(dialog.querySelector('[data-calc-balance]').textContent));
    let expression = '';
    dialog?.querySelectorAll('[data-calc-key]').forEach(key => key.addEventListener('click', () => { const display=dialog.querySelector('[data-basic-display]'); const value=key.dataset.calcKey; if(value==='C') expression=''; else if(value==='⌫') expression=expression.slice(0,-1); else if(value==='copy') navigator.clipboard.writeText(display.value); else if(value==='='){ try { const safe=expression.replaceAll('×','*').replaceAll('÷','/').replaceAll('−','-'); if(!/^[0-9+\-*/.% ()]+$/.test(safe)) throw new Error(); expression=String(Function(`"use strict";return (${safe})`)()); } catch { expression='Error'; } } else { if(expression==='Error') expression=''; expression+=value; } display.value=expression||'0'; }));

    function updateClock() { const clock=document.getElementById('board-clock'); if(clock) clock.textContent=new Intl.DateTimeFormat('en-GB',{dateStyle:'medium',timeStyle:'medium'}).format(new Date()); }
    updateClock(); setInterval(updateClock, 1000);
    loadBoard(); setInterval(loadBoard, root.dataset.fullscreen === '1' ? 30000 : 15000);
})();

function openDuePaymentModal(invoiceId, dueAmount, invoiceNumber) {
    const dialog = document.getElementById('due-payment-dialog');
    const form = document.getElementById('due-payment-form');
    
    form.action = `{{ url('customer-dues') }}/${invoiceId}/payment`;
    document.getElementById('due-payment-title').textContent = `Pay Bill ${invoiceNumber}`;
    document.getElementById('due-payment-outstanding-label').textContent = 'Rs. ' + parseFloat(dueAmount).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const amountInput = document.getElementById('due-payment-amount');
    amountInput.value = parseFloat(dueAmount).toFixed(2);
    amountInput.max = parseFloat(dueAmount);
    
    dialog.showModal();
}
</script>

{{-- Due Payment Dialog --}}
<dialog id="due-payment-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <form method="POST" id="due-payment-form" class="p-6">
        @csrf
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Payment Collection</p>
                <h2 class="mt-1 text-xl font-black text-slate-900" id="due-payment-title" style="color: #0f172a !important;">Receive Payment</h2>
            </div>
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button>
        </div>
        <div class="mt-4 rounded-2xl bg-red-50 p-4" style="background-color: #fef2f2 !important; border: 1px solid #fee2e2 !important;">
            <p class="text-xs font-bold text-red-500">Outstanding Due</p>
            <p class="mt-1 text-2xl font-black text-red-700" id="due-payment-outstanding-label">Rs. 0.00</p>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="label" style="color: #475569 !important;">Payment Amount *</span>
                <input type="number" step="0.01" min="0.01" name="amount" id="due-payment-amount" class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
            </label>
            <label class="block">
                <span class="label" style="color: #475569 !important;">Payment Method *</span>
                <select name="method" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    <option>Cash</option>
                    <option>Card</option>
                    <option>Bank Transfer</option>
                    <option>Cheque</option>
                    <option>QR</option>
                </select>
            </label>
            <label class="sm:col-span-2 block">
                <span class="label" style="color: #475569 !important;">Deposit Account *</span>
                <select name="account_id" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sm:col-span-2 block">
                <span class="label" style="color: #475569 !important;">Note</span>
                <textarea name="note" rows="2" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;"></textarea>
            </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button class="btn-primary">Save Payment</button>
        </div>
    </form>
</dialog>
