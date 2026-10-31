<x-layouts::app title="Technician Attendance">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap justify-between items-start gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Technicians / Attendance</p>
        <h1 class="page-title">Technician Attendance</h1>
        <p class="page-subtitle">Track technician In/Out punches, working minutes, OT slabs, and attendance status</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="{{ route('technicians.attendance.export', request()->query()) }}" class="btn-secondary flex items-center gap-1.5 text-emerald-700 hover:text-emerald-800" title="Export matching attendance to Excel">
          📊 Export Excel
        </a>
        <a href="{{ route('technicians.attendance.export', array_merge(request()->query(), ['format' => 'pdf'])) }}" target="_blank" class="btn-secondary flex items-center gap-1.5 text-rose-700 hover:text-rose-800" title="Export matching attendance to PDF">
          📄 Export PDF
        </a>
        <button type="button" onclick="openImportModal()" class="btn-secondary flex items-center gap-1">
          📥 Import Excel
        </button>
        <button type="button" onclick="openBulkModal()" class="btn-secondary flex items-center gap-1">
          👥 Bulk Mark Attendance
        </button>
        <a href="#attendance-form" class="btn-primary flex items-center gap-1">
          ＋ Add Attendance
        </a>
      </div>
    </div>

    <x-flash />

    <script type="application/json" id="attendance-shop-calendar">@json($shopCalendar)</script>
    <script type="application/json" id="attendance-staff-schedules">@json($staffSchedules)</script>
    <section class="panel space-y-4 border border-slate-200 bg-white p-5">
      <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-black">Shop closed days</h2>@if(auth()->user()->canAccess('settings.salary'))<a class="text-sm font-semibold text-blue-700 underline" href="{{ route('system-settings.salary-settings') }}">Weekly work-day settings</a>@endif</div>
      <x-attendance-colour-key />
      <p class="text-sm text-slate-600">Weekly closed days: {{ implode(', ', $shopCalendar['weekly_closed']) ?: 'None' }}. Missing attendance on a working day is checked as paid leave with no absence deduction. Uncheck it to import an absence with the normal deduction. Shop closed days are excluded. Future days are not checked automatically; empty future working days are skipped unless you explicitly approve leave.</p>
      @if(auth()->user()->canAccess('settings.salary'))
      <form method="POST" action="{{ route('technicians.attendance.closed-days.store') }}" data-closure-form class="grid items-end gap-3 md:grid-cols-4">
        @csrf<x-submission-token/>@if($previewBatch)<input type="hidden" name="batch" value="{{ $previewBatch->id }}">@endif
        <label><span class="label">Closure month</span><input type="month" name="month" value="{{ $from->format('Y-m') }}" required class="field"></label>
        <label><span class="label">Special closed date</span><input type="date" name="closure_date" required min="{{ $from->copy()->startOfMonth()->toDateString() }}" max="{{ $from->copy()->endOfMonth()->toDateString() }}" class="field"></label>
        <label><span class="label">Reason / name</span><input name="closure_name" maxlength="255" required class="field" placeholder="e.g. Shop maintenance"></label>
        <button class="btn-secondary">Add special closed day</button>
      </form>
      @endif
      <div class="flex flex-wrap gap-2 text-xs">@forelse($specialClosures as $closure)<span class="rounded-lg bg-violet-50 px-3 py-2 font-semibold text-violet-800">{{ $closure->holiday_date->format('d M') }} · {{ $closure->holiday_name }}</span>@empty<span class="text-slate-500">No special closed days for the selected period.</span>@endforelse</div>
      @if(auth()->user()->canAccess('settings.salary'))<a class="text-xs font-semibold text-violet-700 underline" href="{{ route('system-settings.leave-holiday-settings') }}">Manage special closed days and holidays</a>@endif
    </section>

    @if($previewBatch)
    <form method="POST" action="{{ route('technicians.attendance.import.confirm', $previewBatch) }}" id="attendance-import-form" class="panel bg-yellow-50 border border-yellow-200 rounded-xl p-6 shadow-sm">
      @csrf<x-submission-token/><input type="hidden" name="calendar_version" value="{{ $calendarVersion }}"><input type="hidden" name="schedule_version" value="{{ $scheduleVersion }}">
      <div class="flex flex-wrap justify-between items-end gap-4 mb-4">
        <div>
          <h2 class="text-lg font-black text-yellow-800">Review & Confirm Import</h2>
          <p class="text-sm text-yellow-700">Please review the parsed data from <b>{{ $previewBatch->file_name }}</b>. You can modify times and dates before finalizing. Forgotten fingerprint? Check Worked / fill times to fill missing coming and out times from the staff schedule. Normal working days receive normal attendance; only shop-closed work earns extra daily pay. Existing actual punches are retained.</p>
        </div>
        <div class="flex-shrink-0">
          <button class="btn-primary !bg-yellow-600 hover:!bg-yellow-700 border-yellow-700 text-white">Confirm & Import {{ $previewBatch->total_rows }} Rows</button>
        </div>
      </div>

      <div class="mb-4"><x-attendance-colour-key /></div>
      <div class="overflow-x-auto border border-yellow-200 rounded-lg bg-white max-h-[600px] relative">
        <table class="w-full text-left text-xs whitespace-nowrap">
          <thead class="bg-yellow-100 text-yellow-800 border-b border-yellow-200 sticky top-0 z-10 shadow-sm">
            <tr>
              <th class="px-4 py-2 font-bold">Row</th>
              <th class="px-4 py-2 font-bold">Employee in File</th>
              <th class="px-4 py-2 font-bold">Map to Technician</th>
              <th class="px-4 py-2 font-bold">Paid leave</th><th class="px-4 py-2 font-bold">Worked / fill times</th><th class="px-4 py-2 font-bold">Date / shop day</th>
              <th class="px-4 py-2 font-bold">Check In</th>
              <th class="px-4 py-2 font-bold">Check Out</th>
              <th class="px-4 py-2 font-bold">Duplicate?</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-yellow-100">
            @foreach($previewRows as $i => $row)
            <tr data-import-row class="{{ $row['row_colour'] }} {{ $row['duplicate'] ? 'opacity-50' : '' }}">
              <td class="px-4 py-2 font-mono text-slate-500">{{ $row['row'] }}
                <input type="hidden" name="rows[{{ $row['row'] }}][row]" value="{{ $row['row'] }}">
              </td>
              <td class="px-4 py-2">
                <b class="text-slate-800">{{ $row['name'] ?: 'Unknown Name' }}</b><br>
                <small class="text-slate-400">ID: {{ $row['person_id'] ?: 'N/A' }}</small>
              </td>
              <td class="px-4 py-2">
                <select data-import-technician name="rows[{{ $row['row'] }}][technician_id]" class="w-full max-w-[200px] py-1 px-2 border-slate-300 rounded text-xs bg-white {{ $row['will_create_technician'] ? 'border-red-300 text-red-700 font-bold' : '' }}">
                  <option value="">-- Create New Technician --</option>
                  @foreach($technicians as $t)
                  <option value="{{ $t->id }}" @selected($row['technician_id'] == $t->id)>{{ $t->name }}</option>
                  @endforeach
                </select>
                @if($row['identity_warning'] ?? null)<p class="mt-1 text-xs text-red-700">{{ $row['identity_warning'] }}</p>
                @elseif($row['will_create_technician'])
                <span class="block mt-1 text-[9px] text-red-600 font-bold uppercase tracking-wider">Will Auto-Create</span>
                @endif
              </td>
              <td class="px-4 py-2 text-center whitespace-normal min-w-[150px]">
                <input type="hidden" name="rows[{{ $row['row'] }}][mark_leave]" value="0">
                <input type="checkbox" data-import-leave name="rows[{{ $row['row'] }}][mark_leave]" value="1" @checked($row['mark_leave']) @disabled($row['shop_day']['closed'] || $row['check_in'] || $row['check_out']) aria-label="Paid leave for {{ $row['name'] }} on {{ $row['date'] }}" class="size-4 rounded text-amber-600"><span data-leave-note class="mt-1 block text-[10px] leading-snug text-slate-600">{{ $row['leave_note'] }}</span>
              </td>
              <td class="px-4 py-2 text-center">
                <input type="hidden" name="rows[{{ $row['row'] }}][mark_worked]" value="0">
                <input type="checkbox" data-import-worked name="rows[{{ $row['row'] }}][mark_worked]" value="1" @checked($row['mark_worked']) @disabled($row['future_day']) aria-label="Mark worked and fill missing scheduled times for {{ $row['name'] }} on {{ $row['date'] }}" class="size-4 rounded text-emerald-600">
                <span data-schedule-label class="mt-1 block text-[10px] text-slate-600">{{ $row['work_schedule']['mode'] === 'custom' ? 'Custom' : 'System' }}: {{ substr($row['work_schedule']['start'], 0, 5) }}–{{ substr($row['work_schedule']['end'], 0, 5) }}</span>
                <input data-auto-checkout type="hidden" name="rows[{{ $row['row'] }}][auto_filled_checkout]" value="{{ (int) ($row['auto_filled_checkout'] ?? false) }}">
                <input data-schedule-in type="hidden" name="rows[{{ $row['row'] }}][schedule_filled_in]" value="{{ (int) ($row['schedule_filled_in'] ?? false) }}">
              </td>
              <td class="px-4 py-2">
                <input data-import-date type="date" name="rows[{{ $row['row'] }}][date]" value="{{ $row['date'] }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[120px]" required><span data-shop-day-label class="mt-1 block text-xs font-semibold">{{ $row['shop_day']['label'] }}{{ $row['future_day'] ? ' · Future date' : '' }}</span>
              </td>
              <td class="px-4 py-2">
                <input data-import-in type="time" name="rows[{{ $row['row'] }}][check_in]" value="{{ $row['check_in'] ? substr($row['check_in'], 0, 5) : '' }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[100px]">
              </td>
              <td class="px-4 py-2">
                <input data-import-out type="time" name="rows[{{ $row['row'] }}][check_out]" value="{{ $row['check_out'] ? substr($row['check_out'], 0, 5) : '' }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[100px] {{ ($row['auto_filled_checkout'] ?? false) ? 'border-blue-300 bg-blue-50 text-blue-700' : '' }}">
                  <span data-auto-checkout-note @if(! ($row['auto_filled_checkout'] ?? false)) hidden @endif class="block mt-1 text-[9px] text-blue-600 font-bold uppercase tracking-wider leading-tight">Auto (scheduled close)</span>
              </td>
              <td class="px-4 py-2">
                @if($row['duplicate'])
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-orange-100 text-orange-700 border border-orange-200">Will Update</span>
                @else
                <span class="text-slate-300">—</span>
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </form>
    @endif

    {{-- Stats Summary --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
      @foreach([
        ['Present (filtered)', $stats['present'], 'text-green-600'],
        ['Absent / unpaid (filtered)', $stats['absent'], 'text-red-600'],
        ['Late (filtered)', $stats['late'], 'text-orange-500'],
        ['Paid leave (filtered)', $stats['leave'], 'text-blue-600'],
        ['OT hours (filtered)', number_format($stats['ot'], 2), 'text-slate-800']
      ] as [$label, $value, $colorClass])
      <article class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm relative overflow-hidden">
        <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
        <p class="mt-2 text-2xl font-black {{ $colorClass }}">{{ $value }}</p>
      </article>
      @endforeach
    </div>

    {{-- Mark/Edit Attendance Form --}}
    <form id="attendance-form" method="POST" action="{{ route('technicians.attendance.store') }}" class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
      @csrf<x-submission-token/>
      <input type="hidden" name="auto_filled_checkout" id="attendanceAutoCheckout" value="0">
      <h2 id="formHeader" class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Mark or Edit Attendance</h2>
      
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        
        <div>
          <label class="label">Technician <span class="required-star">*</span></label>
          <select name="technician_id" id="techSelect" onchange="calculateAttendanceFrontend()" required class="w-full">
            <option value="">Select technician</option>
            @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(request('technician') == $t->id)>{{ $t->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">Attendance Date <span class="required-star">*</span></label>
          <input type="date" name="date" id="dateInput" value="{{ $date->toDateString() }}" onchange="checkFinalizedStatus(); calculateAttendanceFrontend();" required class="w-full">
        </div>

        <div>
          <label class="label">Attendance Status <span class="required-star">*</span></label>
          <select name="status" id="statusSelect" onchange="attendanceStatusChanged()" class="w-full">
            @foreach(['Present','Absent','Late','Half Day','Morning Half Day','Evening Half Day','Leave','Paid Leave','No Pay Leave','Holiday','Early Leave','Worked on Leave Day','Not Worked'] as $status)
            <option value="{{ $status }}">{{ $status === 'Not Worked' ? 'Not worked · shop closed / holiday' : $status }}</option>
            @endforeach
          </select>
          <p class="mt-2 text-xs text-slate-500">Absent, unpaid leave, paid leave and holiday statuses clear coming/out times, hours and OT. Not worked is for a shop-closed day. Choose Present or Worked on Leave Day to fill missing scheduled times; adjust actual times if needed.</p>
        </div>

        <div>
          <label class="label">In Time</label>
          <input type="time" name="in_time" id="inTimeInput" oninput="calculateAttendanceFrontend()" class="w-full">
        </div>

        <div>
          <label class="label">Out Time</label>
          <input type="time" name="out_time" id="outTimeInput" oninput="document.getElementById('attendanceAutoCheckout').value = '0'; calculateAttendanceFrontend()" class="w-full">
        </div>

        <div>
          <label class="label">Working Hours</label>
          <input type="text" id="workingHoursInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div>
          <label class="label">Late Minutes</label>
          <input type="text" id="lateMinutesInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div>
          <label class="label">Early Leave Minutes</label>
          <input type="text" id="earlyLeaveMinutesInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div>
          <label class="label">Payable OT Minutes</label>
          <input type="text" id="otMinutesInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div>
          <label class="label">OT Amount (Rs.)</label>
          <input type="number" step="0.01" name="ot_amount" id="otAmountInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div>
          <label class="label">Deduction Amount (Rs.)</label>
          <input type="number" step="0.01" name="deduction_amount" id="deductionAmountInput" readonly class="w-full bg-slate-50 cursor-not-allowed">
        </div>

        <div class="md:col-span-2">
          <label class="label">Attendance Note</label>
          <input type="text" name="note" class="w-full">
        </div>

        <div>
          <label class="label">Edit Reason (Required when modifying)</label>
          <input type="text" name="edit_reason" id="editReasonInput" placeholder="Required when correcting a punch" class="w-full">
        </div>

        <div class="md:col-span-2 pt-6 flex items-center gap-4">
          <label class="flex items-center gap-2 cursor-pointer select-none">
            <input type="checkbox" name="is_manual_edit" id="isManualEdit" value="1" onchange="calculateAttendanceFrontend()" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
            <span class="text-xs font-bold text-slate-700 text-blue-600">Manual Amount Override</span>
          </label>
        </div>

      </div>

      <p id="attendancePreviewMessage" role="status" aria-live="polite" class="mb-3 text-sm text-slate-600"></p>
      <div class="border-t border-slate-100 pt-4 flex justify-between items-center">
        <div id="finalizedWarning" class="text-xs font-bold text-red-600 hidden">
          ⚠️ Warning: This date is included in finalized salary sheet. Unlock paysheet before saving.
        </div>
        <div class="flex gap-2 ml-auto">
          <button type="button" onclick="resetAttendanceForm()" class="btn-secondary">Reset Form</button>
          <button type="submit" id="saveAttendanceBtn" class="btn-primary">Save Attendance</button>
        </div>
      </div>
    </form>

    {{-- Filter Search --}}
    <form class="panel bg-white border border-slate-200 rounded-xl p-5 shadow-sm grid gap-3 md:grid-cols-4">
      @if($previewBatch)<input type="hidden" name="batch" value="{{ $previewBatch->id }}">@endif
      <div>
        <label class="label">Month Filter</label>
        <div class="relative">
          <input type="month" name="month" value="{{ request('month', $from->format('Y-m')) }}" class="w-full">
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
            📅
          </span>
        </div>
      </div>
      <div>
        <label class="label">Technician</label>
        <select name="technician" class="w-full">
          <option value="">All technicians</option>
          @foreach($technicians as $t)
          <option value="{{ $t->id }}" @selected(request('technician') == $t->id)>{{ $t->name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="label">Status</label>
        <select name="status" class="w-full">
          <option value="">All statuses</option>
          @foreach(['Present','Absent','Late','Half Day','Morning Half Day','Evening Half Day','Leave','Paid Leave','No Pay Leave','Holiday','Early Leave','Worked on Leave Day','Not Worked'] as $status)
          <option value="{{ $status }}" @selected(request('status') == $status)>{{ $status }}</option>
          @endforeach
        </select>
      </div>
      <div class="pt-6 flex gap-2">
        <button type="submit" class="btn-secondary flex-1">Apply Filters</button>
        <a href="{{ route('technicians.attendance.export', request()->query()) }}" class="btn-secondary flex items-center justify-center px-3 text-emerald-700 hover:text-emerald-800 font-bold" title="Export filtered records to Excel (.xlsx)">
          📊 Excel
        </a>
        <a href="{{ route('technicians.attendance.export', array_merge(request()->query(), ['format' => 'pdf'])) }}" target="_blank" class="btn-secondary flex items-center justify-center px-3 text-rose-700 hover:text-rose-800 font-bold" title="Export filtered records to PDF">
          📄 PDF
        </a>
      </div>
    </form>

    <section class="panel space-y-3 border border-blue-200 bg-blue-50 p-5">
      <h2 class="font-black text-blue-900">Recalculate All Attendance</h2>
      <p class="text-sm text-slate-600">Apply current attendance and OT rules to all matching records across every page. Apply the filters above first to choose staff and status. Finalized salary attendance is skipped; manual amount overrides are retained.</p>
      <form method="POST" action="{{ route('technicians.attendance.recalculate-all') }}" class="grid gap-3 md:grid-cols-3">
        @csrf<x-submission-token />
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <input type="hidden" name="technician" value="{{ request('technician') }}">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <label class="block"><span class="label">Dates to recalculate</span><select name="scope" class="field" required><option value="filtered">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} · applied filters</option><option value="all_dates">All recorded dates · selected staff/status</option></select></label>
        <label class="block"><span class="label">Reason for recalculation</span><input name="reason" value="{{ old('reason') }}" class="field" minlength="5" maxlength="1000" placeholder="e.g. Apply the updated 8:00 AM OT rule" required></label>
        <div class="flex items-end"><button class="btn-primary w-full">Recalculate All</button></div>
      </form>
      <p class="text-xs text-slate-500">This updates attendance only. Refresh existing draft paysheets from their salary View page before finalizing.</p>
    </section>

    {{-- Table List --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
      <div class="flex flex-wrap items-center justify-between gap-3 p-3.5 border-b border-slate-200 bg-slate-50/70">
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold text-slate-700">Attendance Records</span>
          <span class="rounded-full bg-slate-200/80 px-2 py-0.5 text-[11px] font-extrabold text-slate-700">{{ $records->total() }}</span>
        </div>
        <div class="flex items-center gap-3 text-xs">
          <a href="{{ route('technicians.attendance.export', request()->query()) }}" class="font-bold text-emerald-700 hover:text-emerald-800 hover:underline flex items-center gap-1">
            📊 Export Excel
          </a>
          <span class="text-slate-300">|</span>
          <a href="{{ route('technicians.attendance.export', array_merge(request()->query(), ['format' => 'pdf'])) }}" target="_blank" class="font-bold text-rose-700 hover:text-rose-800 hover:underline flex items-center gap-1">
            📄 Export PDF
          </a>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">In Time</th>
              <th class="px-4 py-3 font-bold">Out Time</th>
              <th class="px-4 py-3 font-bold">Hours</th>
              <th class="px-4 py-3 font-bold">Late Mins</th>
              <th class="px-4 py-3 font-bold">Early Leave</th>
              <th class="px-4 py-3 font-bold">OT</th>
              <th class="px-4 py-3 font-bold">Deduction</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold">Note</th>
              <th class="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($records as $row)
            @php
              $statusClass = match($row->status) {
                'Present', 'Early Leave', 'Auto Closed' => 'badge-sent',
                'Absent', 'No Pay Leave' => 'badge-failed',
                'Late' => 'bg-orange-50 text-orange-700 border border-orange-200',
                'Half Day', 'Morning Half Day', 'Evening Half Day' => 'bg-purple-50 text-purple-700 border border-purple-200',
                'Leave', 'Paid Leave' => 'bg-blue-50 text-blue-700 border border-blue-200',
                default => 'bg-slate-50 text-slate-700 border border-slate-200'
              };
              $shopDay = app(\App\Services\HolidayService::class)->dayInfo($row->date->toDateString(), $shopCalendar);
              $editData = [$row->technician_id, $row->date->toDateString(), $row->status, $row->in_time ? substr($row->in_time, 0, 5) : "", $row->out_time ? substr($row->out_time, 0, 5) : "", $row->ot_amount, $row->deduction_amount, (int) $row->is_manual_edit, $row->note, (int) $row->is_auto_checkout];
              $notWorkedData = [$row->technician_id, $row->date->toDateString(), "Not Worked", "", "", 0, 0, 0, $row->note, 0];
            @endphp
            <tr class="{{ $shopDay['kind'] === 'special' ? 'bg-violet-50' : ($shopDay['kind'] === 'weekly' ? 'bg-sky-50' : (in_array($row->status, ['Absent', 'No Pay Leave']) ? 'bg-rose-50' : (in_array($row->status, ['Leave', 'Paid Leave']) ? 'bg-amber-50' : 'bg-white'))) }} transition">
              <td class="px-4 py-3 font-mono">{{ $row->date->format('d M Y') }}@if($shopDay['closed'])<span class="mt-1 block text-xs font-semibold">{{ $shopDay['label'] }}</span>@endif</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->technician->name }}</td>
              <td class="px-4 py-3 font-mono text-slate-500">{{ $row->in_time ? Carbon\Carbon::parse($row->in_time)->format('h:i A') : '—' }}</td>
              <td class="px-4 py-3 font-mono text-slate-500">
                {{ $row->out_time ? Carbon\Carbon::parse($row->out_time)->format('h:i A') : '—' }}
                @if($row->is_auto_checkout)
                  <span class="block text-[10px] text-blue-600 font-semibold">Auto (scheduled close)</span>
                @endif
              </td>
              <td class="px-4 py-3">{{ number_format($row->working_hours, 2) }}</td>
              <td class="px-4 py-3">
                @if($row->late_minutes > 0)
                <span class="text-orange-600 font-bold font-mono">{{ $row->late_minutes }} min</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3">
                @if($row->early_leave_minutes > 0)
                <span class="text-orange-500 font-bold font-mono">{{ $row->early_leave_minutes }} min</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3">
                @if($row->ot_amount > 0)
                <span class="text-green-600 font-bold">Rs. {{ number_format($row->ot_amount, 2) }}</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3">
                @if($row->deduction_amount > 0)
                <span class="text-red-600 font-bold">Rs. {{ number_format($row->deduction_amount, 2) }}</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider {{ $statusClass }}">
                  {{ $row->status }}
                </span>
                @if($row->is_manual_edit)
                <span class="ml-1 text-[9px] font-black bg-blue-100 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded uppercase">Manual</span>
                @endif
              </td>
              <td class="px-4 py-3 max-w-[150px] truncate text-slate-500" title="{{ $row->note }}">{{ $row->note ?: '—' }}</td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex gap-1.5">
                  <button type="button" data-attendance-edit='@json($editData)' onclick="editAttendance(...JSON.parse(this.dataset.attendanceEdit))" class="btn-secondary !py-0.5 !px-2 text-xs">Edit</button>
                  @if($shopDay['closed'] && ($row->in_time || $row->out_time || $row->working_minutes > 0 || $row->status === 'Worked on Leave Day'))
                  <button type="button" data-attendance-edit='@json($notWorkedData)' onclick="editAttendance(...JSON.parse(this.dataset.attendanceEdit))" class="btn-secondary !py-0.5 !px-2 text-xs text-amber-800">Mark not worked</button>
                  @endif
                  <form method="POST" action="{{ route('technicians.attendance.recalculate', $row) }}" class="inline">
                    @csrf<x-submission-token/>
                    <button type="submit" class="btn-secondary !py-0.5 !px-2 text-xs text-blue-600">Recalculate</button>
                  </form>
                </div>
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="12" class="px-4 py-8 text-center text-slate-500">
                <p class="text-sm font-bold text-slate-700">No attendance recorded for this filter range.</p>
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="p-4 border-t border-slate-100 bg-slate-50/50">
        {{ $records->links() }}
      </div>
    </div>

    {{-- Bulk Mark Attendance Dialog Modal --}}
    {{-- Import Excel Modal --}}
    <dialog id="importModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-lg backdrop:bg-slate-900/50 open:animate-in open:fade-in open:zoom-in-95">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
        <h3 class="font-extrabold text-lg text-slate-800">📥 Import Attendance (Excel/CSV)</h3>
        <button type="button" onclick="closeImportModal()" class="text-slate-400 hover:text-slate-600 transition">✕</button>
      </div>
      <form method="POST" action="{{ route('technicians.attendance.import.preview') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf<x-submission-token/>
        <div>
          <label class="label">Upload File <span class="required-star">*</span></label>
          <input type="file" name="file" accept=".csv,.xlsx,.xls,.txt" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100 cursor-pointer">
          <p class="text-xs text-slate-400 mt-2">Supported formats: .xlsx, .xls, .csv, .txt (up to 20MB)</p>
        </div>
        <div>
          <label class="label">Specific Technician (Optional)</label>
          <select name="technician_id" class="w-full">
            <option value="">Auto-match by name/ID from file</option>
            @foreach($technicians as $t)
              <option value="{{ $t->id }}">{{ $t->name }}</option>
            @endforeach
          </select>
          <p class="text-[10px] text-slate-400 mt-1">If selected, all records in the file will be mapped to this technician regardless of the names in the file.</p>
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 mt-6">
          <button type="button" onclick="closeImportModal()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary">Upload & Preview</button>
        </div>
      </form>
    </dialog>

    <dialog id="bulkModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-lg">
      <h3 class="text-base font-black text-slate-800 mb-2">👥 Bulk Mark Attendance</h3>
      <p class="text-xs text-slate-500 mb-4">Quickly log attendance punches for multiple technicians simultaneously.</p>
      
      <form method="POST" action="{{ route('technicians.attendance.store') }}" class="space-y-4">
        @csrf<x-submission-token/>
        
        <div>
          <label class="label">Select Technicians <span class="required-star">*</span></label>
          <div class="grid grid-cols-2 gap-2 mt-1 max-h-36 overflow-y-auto border border-slate-200 rounded-lg p-2 bg-slate-50">
            @foreach($technicians as $t)
            <label class="flex items-center gap-1.5 cursor-pointer text-xs font-bold text-slate-700">
              <input type="checkbox" name="technician_ids[]" value="{{ $t->id }}" class="rounded text-red-600 focus:ring-red-500">
              {{ $t->name }}
            </label>
            @endforeach
          </div>
        </div>

        <div class="grid gap-3 grid-cols-2">
          <div>
            <label class="label">Date *</label>
            <input type="date" name="date" value="{{ today()->toDateString() }}" required class="w-full">
          </div>
          <div>
            <label class="label">Status *</label>
            <select name="status" class="w-full">
              <option value="Present">Present</option>
              <option value="Absent">Absent</option>
              <option value="Leave">Leave</option>
              <option value="Holiday">Holiday</option>
            </select>
          </div>
          <div>
            <label class="label">In Time</label>
            <input type="time" name="in_time" class="w-full">
          </div>
          <div>
            <label class="label">Out Time</label>
            <input type="time" name="out_time" class="w-full">
          </div>
        </div>

        <div>
          <label class="label">Deduction Override / Note</label>
          <input type="text" name="note" placeholder="Optional remark" class="w-full">
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeBulkModal()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary">Mark Attendance</button>
        </div>
      </form>
    </dialog>

  </div>

  <script>
    let attendancePreviewTimer;
    let attendancePreviewAbort;
    let attendancePreviewVersion = 0;

    function checkFinalizedStatus() {}

    const nonWorkAttendanceStatuses = @json(\App\Services\AttendanceCalculationService::NON_WORK_STATUSES);

    function attendanceStatusChanged() {
      const status = document.getElementById('statusSelect').value;
      document.getElementById('isManualEdit').checked = false;
      if (['Present', 'Worked on Leave Day'].includes(status)) {
        const schedules = JSON.parse(document.getElementById('attendance-staff-schedules').textContent);
        const staffId = document.getElementById('techSelect').value;
        const schedule = schedules.staff[staffId] || schedules.default;
        if (staffId && schedule) {
          const coming = document.getElementById('inTimeInput');
          const leaving = document.getElementById('outTimeInput');
          coming.value ||= schedule.start.slice(0, 5);
          if (!leaving.value) {
            leaving.value = schedule.end.slice(0, 5);
            document.getElementById('attendanceAutoCheckout').value = '1';
          }
        }
      }
      calculateAttendanceFrontend();
    }

    function calculateAttendanceFrontend() {
      clearTimeout(attendancePreviewTimer);
      attendancePreviewAbort?.abort();
      const version = ++attendancePreviewVersion;
      const selectedStatus = document.getElementById('statusSelect').value;
      const notWorked = selectedStatus === 'Not Worked';
      const nonWorking = nonWorkAttendanceStatuses.includes(selectedStatus);
      const manualInput = document.getElementById('isManualEdit');
      manualInput.disabled = notWorked;
      if (notWorked) manualInput.checked = false;
      for (const id of ['inTimeInput', 'outTimeInput']) {
        const input = document.getElementById(id);
        input.disabled = nonWorking;
        if (nonWorking) input.value = '';
      }
      if (nonWorking) {
        document.getElementById('attendanceAutoCheckout').value = '0';
        for (const id of ['workingHoursInput', 'lateMinutesInput', 'earlyLeaveMinutesInput', 'otMinutesInput', 'otAmountInput']) {
          document.getElementById(id).value = '0';
        }
      }
      const manual = manualInput.checked;
      const save = document.getElementById('saveAttendanceBtn');
      const message = document.getElementById('attendancePreviewMessage');
      const fields = {workingHoursInput: 'working_hours', lateMinutesInput: 'late_minutes', earlyLeaveMinutesInput: 'early_leave_minutes', otMinutesInput: 'ot_minutes', otAmountInput: 'ot_amount', deductionAmountInput: 'deduction_amount'};
      for (const id of ['otAmountInput', 'deductionAmountInput']) {
        const input = document.getElementById(id);
        input.readOnly = !manual || (nonWorking && id === 'otAmountInput');
        input.classList.toggle('bg-slate-50', !manual);
        input.classList.toggle('cursor-not-allowed', !manual);
      }
      if (manual) {
        save.disabled = false;
        message.textContent = 'Manual amounts will override the calculated amounts.';
        return;
      }
      const params = new URLSearchParams({
        technician_id: document.getElementById('techSelect').value,
        date: document.getElementById('dateInput').value,
        status: document.getElementById('statusSelect').value,
        in_time: document.getElementById('inTimeInput').value,
        out_time: document.getElementById('outTimeInput').value,
        auto_filled_checkout: document.getElementById('attendanceAutoCheckout').value,
      });
      Object.keys(fields).forEach(id => document.getElementById(id).value = '');
      if (!params.get('technician_id') || !params.get('date')) {
        save.disabled = true;
        message.textContent = 'Select a staff member and date to calculate attendance.';
        return;
      }
      save.disabled = true;
      message.textContent = 'Calculating attendance…';
      attendancePreviewTimer = setTimeout(async () => {
        const controller = new AbortController();
        attendancePreviewAbort = controller;
        try {
          const response = await fetch(@json(route('technicians.attendance.preview')) + '?' + params, {headers: {Accept: 'application/json'}, signal: controller.signal});
          const data = await response.json();
          if (version !== attendancePreviewVersion) return;
          if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Attendance could not be calculated.');
          Object.entries(fields).forEach(([id, key]) => {
            document.getElementById(id).value = ['working_hours', 'ot_amount', 'deduction_amount'].includes(key) ? Number(data[key]).toFixed(2) : data[key];
          });
          if (data.is_auto_checkout) {
            message.textContent = 'Auto-filled scheduled closing time (' + (data.out_time || '—').slice(0, 5) + '). Status: ' + data.status;
          } else {
            message.textContent = 'Calculated from the saved attendance rules. Status: ' + data.status;
          }
          save.disabled = false;
        } catch (error) {
          if (version !== attendancePreviewVersion || error.name === 'AbortError') return;
          message.textContent = error.message || 'Attendance preview failed. Change an input to retry.';
          save.disabled = true;
        }
      }, 200);
    }

    function editAttendance(techId, date, status, inTime, outTime, otAmount, deductionAmount, manual, note, autoCheckout = 0) {
      document.getElementById('formHeader').textContent = 'Edit Attendance Record';
      document.getElementById('techSelect').value = techId;
      document.getElementById('dateInput').value = date;
      document.getElementById('statusSelect').value = status === 'Auto Closed' ? 'Present' : status;
      document.getElementById('attendanceAutoCheckout').value = autoCheckout ? '1' : '0';
      document.getElementById('inTimeInput').value = inTime;
      document.getElementById('outTimeInput').value = outTime;
      document.getElementById('isManualEdit').checked = manual == '1';
      
      const form = document.getElementById('attendance-form');
      form.querySelector('input[name="note"]').value = note;
      
      if (manual == '1') {
        document.getElementById('otAmountInput').value = otAmount;
        document.getElementById('deductionAmountInput').value = deductionAmount;
      }
      
      calculateAttendanceFrontend();
      document.getElementById('editReasonInput').value = '';
      document.getElementById('editReasonInput').setAttribute('required', 'true');
      
      // Scroll to form
      form.scrollIntoView({ behavior: 'smooth' });
      if (status === 'Not Worked') document.getElementById('editReasonInput').focus({preventScroll: true});
    }

    function resetAttendanceForm() {
      document.getElementById('formHeader').textContent = 'Mark or Edit Attendance';
      document.getElementById('attendance-form').reset();
      document.getElementById('editReasonInput').removeAttribute('required');
      calculateAttendanceFrontend();
    }

    calculateAttendanceFrontend();

    function openBulkModal() {
      document.getElementById('bulkModal').showModal();
    }
    function closeBulkModal() {
      document.getElementById('bulkModal').close();
    }
    function openImportModal() {
      document.getElementById('importModal').showModal();
    }
    function closeImportModal() {
      document.getElementById('importModal').close();
    }
  </script>
</x-layouts::app>
