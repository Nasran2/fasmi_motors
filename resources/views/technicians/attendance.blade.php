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

    @if($previewBatch)
    <div class="panel bg-yellow-50 border border-yellow-200 rounded-xl p-6 shadow-sm">
      <div class="flex flex-wrap justify-between items-end gap-4 mb-4">
        <div>
          <h2 class="text-lg font-black text-yellow-800">Review & Confirm Import</h2>
          <p class="text-sm text-yellow-700">Please review the parsed data from <b>{{ $previewBatch->file_name }}</b>. You can modify times and dates before finalizing.</p>
        </div>
        <form method="POST" action="{{ route('technicians.attendance.import.confirm', $previewBatch) }}" class="flex-shrink-0">
          @csrf<x-submission-token/>
          <button class="btn-primary !bg-yellow-600 hover:!bg-yellow-700 border-yellow-700 text-white">Confirm & Import {{ $previewBatch->total_rows }} Rows</button>
      </div>

      <div class="overflow-x-auto border border-yellow-200 rounded-lg bg-white max-h-[600px] relative">
        <table class="w-full text-left text-xs whitespace-nowrap">
          <thead class="bg-yellow-100 text-yellow-800 border-b border-yellow-200 sticky top-0 z-10 shadow-sm">
            <tr>
              <th class="px-4 py-2 font-bold">Row</th>
              <th class="px-4 py-2 font-bold">Employee in File</th>
              <th class="px-4 py-2 font-bold">Map to Technician</th>
              <th class="px-4 py-2 font-bold">Date</th>
              <th class="px-4 py-2 font-bold">Check In</th>
              <th class="px-4 py-2 font-bold">Check Out</th>
              <th class="px-4 py-2 font-bold">Duplicate?</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-yellow-100">
            @foreach($previewBatch->preview_data as $i => $row)
            <tr class="hover:bg-yellow-50/50 {{ $row['duplicate'] ? 'opacity-50' : '' }}">
              <td class="px-4 py-2 font-mono text-slate-500">{{ $row['row'] }}
                <input type="hidden" name="rows[{{ $row['row'] }}][row]" value="{{ $row['row'] }}">
              </td>
              <td class="px-4 py-2">
                <b class="text-slate-800">{{ $row['name'] ?: 'Unknown Name' }}</b><br>
                <small class="text-slate-400">ID: {{ $row['person_id'] ?: 'N/A' }}</small>
              </td>
              <td class="px-4 py-2">
                <select name="rows[{{ $row['row'] }}][technician_id]" class="w-full max-w-[200px] py-1 px-2 border-slate-300 rounded text-xs bg-white {{ $row['will_create_technician'] ? 'border-red-300 text-red-700 font-bold' : '' }}">
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
              <td class="px-4 py-2">
                <input type="date" name="rows[{{ $row['row'] }}][date]" value="{{ $row['date'] }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[120px]" required>
              </td>
              <td class="px-4 py-2">
                <input type="time" name="rows[{{ $row['row'] }}][check_in]" value="{{ $row['check_in'] ? substr($row['check_in'], 0, 5) : '' }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[100px]">
              </td>
              <td class="px-4 py-2">
                <input type="time" name="rows[{{ $row['row'] }}][check_out]" value="{{ $row['check_out'] ? substr($row['check_out'], 0, 5) : '' }}" class="py-1 px-2 border-slate-300 rounded text-xs w-[100px] {{ ($row['auto_filled_checkout'] ?? false) ? 'border-blue-300 bg-blue-50 text-blue-700' : '' }}">
                @if($row['auto_filled_checkout'] ?? false)
                  <span class="block mt-1 text-[9px] text-blue-600 font-bold uppercase tracking-wider leading-tight">Auto (Shop Close)</span>
                @endif
              </td>
              <td class="px-4 py-2">
                @if($row['duplicate'])
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-orange-100 text-orange-700 border border-orange-200">Will Skip</span>
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
    </div>
    @endif

    {{-- Stats Summary --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
      @foreach([
        ['Present Today', $stats['present'], 'text-green-600'],
        ['Absent Today', $stats['absent'], 'text-red-600'],
        ['Late Today', $stats['late'], 'text-orange-500'],
        ['On Leave Today', $stats['leave'], 'text-blue-600'],
        ['OT Hours (Month)', number_format($stats['ot'], 2), 'text-slate-800']
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
          <select name="status" id="statusSelect" onchange="calculateAttendanceFrontend()" class="w-full">
            @foreach(['Present','Absent','Late','Half Day','Morning Half Day','Evening Half Day','Leave','Paid Leave','No Pay Leave','Holiday','Early Leave'] as $status)
            <option value="{{ $status }}">{{ $status }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">In Time</label>
          <input type="time" name="in_time" id="inTimeInput" oninput="calculateAttendanceFrontend()" class="w-full">
        </div>

        <div>
          <label class="label">Out Time</label>
          <input type="time" name="out_time" id="outTimeInput" oninput="calculateAttendanceFrontend()" class="w-full">
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
          <label class="label">OT Minutes</label>
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
          @foreach(['Present','Absent','Late','Half Day','Morning Half Day','Evening Half Day','Leave','Paid Leave','No Pay Leave','Holiday','Early Leave'] as $status)
          <option value="{{ $status }}" @selected(request('status') == $status)>{{ $status }}</option>
          @endforeach
        </select>
      </div>
      <div class="pt-6">
        <button class="btn-secondary w-full">Apply Filters</button>
      </div>
    </form>

    {{-- Table List --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
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
                'Present', 'Early Leave' => 'badge-sent',
                'Absent', 'No Pay Leave' => 'badge-failed',
                'Late' => 'bg-orange-50 text-orange-700 border border-orange-200',
                'Half Day', 'Morning Half Day', 'Evening Half Day' => 'bg-purple-50 text-purple-700 border border-purple-200',
                'Leave', 'Paid Leave' => 'bg-blue-50 text-blue-700 border border-blue-200',
                default => 'bg-slate-50 text-slate-700 border border-slate-200'
              };
            @endphp
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 font-mono">{{ $row->date->format('d M Y') }}</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->technician->name }}</td>
              <td class="px-4 py-3 font-mono text-slate-500">{{ $row->in_time ? Carbon\Carbon::parse($row->in_time)->format('h:i A') : '—' }}</td>
              <td class="px-4 py-3 font-mono text-slate-500">{{ $row->out_time ? Carbon\Carbon::parse($row->out_time)->format('h:i A') : '—' }}</td>
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
                  <button type="button" onclick="editAttendance('{{ $row->technician_id }}', '{{ $row->date->toDateString() }}', '{{ $row->status }}', '{{ $row->in_time ? substr($row->in_time, 0, 5) : '' }}', '{{ $row->out_time ? substr($row->out_time, 0, 5) : '' }}', '{{ $row->ot_amount }}', '{{ $row->deduction_amount }}', '{{ $row->is_manual_edit }}', '{{ $row->note }}')" class="btn-secondary !py-0.5 !px-2 text-xs">Edit</button>
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

    function calculateAttendanceFrontend() {
      clearTimeout(attendancePreviewTimer);
      attendancePreviewAbort?.abort();
      const version = ++attendancePreviewVersion;
      const manual = document.getElementById('isManualEdit').checked;
      const save = document.getElementById('saveAttendanceBtn');
      const message = document.getElementById('attendancePreviewMessage');
      const fields = {workingHoursInput: 'working_hours', lateMinutesInput: 'late_minutes', earlyLeaveMinutesInput: 'early_leave_minutes', otMinutesInput: 'ot_minutes', otAmountInput: 'ot_amount', deductionAmountInput: 'deduction_amount'};
      for (const id of ['otAmountInput', 'deductionAmountInput']) {
        const input = document.getElementById(id);
        input.readOnly = !manual;
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
          message.textContent = 'Calculated from the saved attendance rules. Status: ' + data.status;
          save.disabled = false;
        } catch (error) {
          if (version !== attendancePreviewVersion || error.name === 'AbortError') return;
          message.textContent = error.message || 'Attendance preview failed. Change an input to retry.';
          save.disabled = true;
        }
      }, 200);
    }

    function editAttendance(techId, date, status, inTime, outTime, otAmount, deductionAmount, manual, note) {
      document.getElementById('formHeader').textContent = 'Edit Attendance Record';
      document.getElementById('techSelect').value = techId;
      document.getElementById('dateInput').value = date;
      document.getElementById('statusSelect').value = status;
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
      document.getElementById('editReasonInput').setAttribute('required', 'true');
      
      // Scroll to form
      form.scrollIntoView({ behavior: 'smooth' });
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
