<tr data-import-row data-row-key="{{ $row['row'] }}" data-new-row="{{ !empty($row['is_new']) ? '1' : '0' }}" class="{{ $row['row_colour'] }} {{ $row['duplicate'] ? 'opacity-50' : '' }}">
              <td class="px-4 py-2 font-mono text-slate-500"><span data-row-number>{{ !empty($row['is_new']) ? 'New' : $row['row'] }}</span>
                <input data-row-id type="hidden" name="rows[{{ $row['row'] }}][row]" value="{{ $row['row'] }}">
                <input type="hidden" name="rows[{{ $row['row'] }}][is_new]" value="{{ !empty($row['is_new']) ? '1' : '0' }}">
              </td>
              <td class="px-4 py-2">
                <b data-import-person class="text-slate-800">{{ $row['name'] ?: 'Unknown Name' }}</b><br>
                <small class="text-slate-400">ID: {{ $row['person_id'] ?: 'N/A' }}</small>
              </td>
              <td class="px-4 py-2">
                <select data-import-technician @required(!empty($row['is_new'])) name="rows[{{ $row['row'] }}][technician_id]" class="w-full max-w-[200px] py-1 px-2 border-slate-300 rounded text-xs bg-white {{ ($row['will_create_technician'] ?? false) ? 'border-red-300 text-red-700 font-bold' : '' }}">
                  <option value="">{{ !empty($row['is_new']) ? '-- Select staff --' : '-- Create New Technician --' }}</option>
                  @foreach($technicians as $t)
                  <option value="{{ $t->id }}" @selected($row['technician_id'] == $t->id)>{{ $t->name }}</option>
                  @endforeach
                </select>
                @if($row['identity_warning'] ?? null)<p class="mt-1 text-xs text-red-700">{{ $row['identity_warning'] }}</p>
                @elseif($row['will_create_technician'] ?? false)
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
                <span data-import-duplicate>
                @if($row['duplicate'])
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-orange-100 text-orange-700 border border-orange-200">Will Update</span>
                @else
                <span class="text-slate-300">—</span>
                @endif
                </span>
              </td>
              <td class="px-4 py-2"><button type="button" data-import-delete class="font-semibold text-red-700 underline" aria-label="Delete preview row for {{ $row['name'] }} on {{ $row['date'] }}">Delete row</button></td>
            </tr>
