<x-layouts::app title="Salary Payments">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap justify-between items-start gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Technicians / Payroll</p>
        <h1 class="page-title">Salary Payments</h1>
        <p class="page-subtitle">Generate monthly paysheets and record technician salary disbursements</p>
      </div>
      <a href="#salary-form" class="btn-primary">＋ Generate Salary Sheet</a>
    </div>

    <x-flash />

    {{-- Stats Cards --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
      @foreach([
        ['Salary Payable', 'Rs. ' . number_format($stats['payable'], 2), 'text-slate-800'],
        ['Salary Paid', 'Rs. ' . number_format($stats['paid'], 2), 'text-green-600'],
        ['Salary Balance', 'Rs. ' . number_format($stats['balance'], 2), 'text-red-600'],
        ['Employee EPF Contribution', 'Rs. ' . number_format($stats['employee_epf'], 2), 'text-blue-600'],
        ['Company EPF / ETF', 'Rs. ' . number_format($stats['company'], 2), 'text-slate-500']
      ] as [$label, $value, $colorClass])
      <article class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
        <p class="mt-2 text-xl font-black {{ $colorClass }}">{{ $value }}</p>
      </article>
      @endforeach
    </div>

    {{-- Generate Salary Form --}}
    <form id="salary-form" method="POST" action="{{ route('technicians.salaries.store') }}" onsubmit="updateSalaryDeductionPreview(); return !document.getElementById('save-draft-btn').disabled;" class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
      @csrf<x-submission-token/>
      <h2 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Generate Salary Sheet</h2>
      <p class="text-xs text-slate-400">Attendance, OT slabs, commission, advances, own-bike repair installments and EPF/ETF are computed automatically.</p>
      
      <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label">Technician <span class="required-star">*</span></label>
          <select name="technician_id" class="w-full" required onchange="loadSalaryPreview()">
            <option value="">Select technician</option>
            @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(request('technician') == $t->id)>{{ $t->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">Salary Month <span class="required-star">*</span></label>
          <input type="month" name="month" value="{{ now()->format('Y-m') }}" required class="w-full" onchange="loadSalaryPreview()">
        </div>

        <div class="col-span-full md:col-span-2" x-data="{
            deductions: [],
            addDeduction() { this.deductions.push({ name: '', amount: '' }); },
            removeDeduction(index) { this.deductions.splice(index, 1); this.$nextTick(() => updateSalaryDeductionPreview()); }
        }">
          <div class="flex items-center justify-between">
              <label class="label font-bold">Manual Deductions <span class="text-[10px] text-slate-400 font-normal ml-1">(Optional)</span></label>
              <button type="button" @click="addDeduction()" class="text-[10px] uppercase tracking-wider text-blue-600 font-bold hover:text-blue-800 transition">+ Add Deduction</button>
          </div>
          
          <div class="mt-2 space-y-2">
              <template x-for="(deduction, index) in deductions" :key="index">
                  <div class="flex gap-2 items-center">
                      <input type="text" x-model="deduction.name" :name="'manual_deductions['+index+'][name]'" placeholder="Deduction details (e.g. Loan, uniform)" class="w-3/5" required>
                      <input type="number" step="0.01" min="0" x-model="deduction.amount" @input="updateSalaryDeductionPreview()" :name="'manual_deductions['+index+'][amount]'" placeholder="Rs. Amount" class="w-2/5" required>
                      <button type="button" @click="removeDeduction(index)" class="text-red-500 font-bold px-2 hover:bg-red-50 rounded-lg py-1 transition">✕</button>
                  </div>
              </template>
              <div x-show="deductions.length === 0" class="text-xs text-slate-400 p-2.5 bg-slate-50/50 rounded-xl border border-dashed border-slate-200">
                  No manual deductions. Click "+ Add Deduction" to add one.
              </div>
          </div>
        </div>

      </div>

      <section id="bike-deduction-preview" class="hidden space-y-3 rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-black text-amber-950">Staff bike salary deductions</h3><span class="rounded-full bg-white px-3 py-1 text-xs font-extrabold text-amber-900">Planned — not yet deducted</span></div>
        <p class="text-sm text-amber-900">These amounts reduce this paysheet's net salary when finalized. Enter 0 to skip a repair this month.</p>
        <p id="repair-deduction-warning" role="status" class="mb-3 text-sm font-semibold text-red-700"></p>
        <div id="bike-installment-inputs" class="grid gap-3 sm:grid-cols-2"></div>
        <div class="grid gap-3 sm:grid-cols-2" aria-live="polite" aria-atomic="true">
          <div class="rounded-xl border border-red-200 bg-white p-4"><p class="text-sm font-bold text-red-700">Staff bike deduction this paysheet</p><strong id="bike-deduction-preview-total" class="mt-1 block text-2xl font-black tabular-nums text-red-700 sm:text-3xl">Rs. 0.00</strong></div>
          <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p class="text-sm font-bold text-emerald-900">Repair balance remaining after this paysheet</p><strong id="bike-deduction-preview-balance" class="mt-1 block text-2xl font-black tabular-nums text-emerald-900 sm:text-3xl">Rs. 0.00</strong></div>
        </div>
      </section>
      <div id="salary-cycle-preview" class="hidden rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-blue-700">Salary Cycle</p>
            <h3 id="preview-cycle-label" class="mt-1 text-base font-black text-slate-900">Calendar Month Salary</h3>
            <p id="preview-cycle-period" class="mt-0.5 text-xs font-bold text-slate-600">Select technician and month to preview.</p>
          </div>
          <span id="preview-cycle-badge" class="rounded-full bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wider text-blue-700 shadow-sm">Calendar Month Salary</span>
        </div>
        <div class="mt-4 grid gap-2 text-xs sm:grid-cols-2 xl:grid-cols-6">
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Period days</span><strong id="preview-period-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Working days</span><strong id="preview-working-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Leave days</span><strong id="preview-leave-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Present days</span><strong id="preview-present-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Absent days</span><strong id="preview-absent-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">OT hours</span><strong id="preview-ot-hours" class="text-slate-900">0</strong></div>
        </div>
        <div class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Previous month days included</span><strong id="preview-prev-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Current month days included</span><strong id="preview-current-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Carried forward / excluded</span><strong id="preview-excluded-days" class="text-red-600">—</strong></div>
        </div>
        <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4" aria-live="polite" aria-atomic="true">
          <div class="rounded-xl bg-emerald-50 p-4"><span class="block font-bold text-emerald-700">Gross salary</span><strong id="preview-gross" class="mt-1 block text-xl font-black tabular-nums text-emerald-800">Rs. 0.00</strong></div>
          <div class="rounded-xl bg-red-50 p-4"><span class="block font-bold text-red-700">Total deductions</span><strong id="preview-deductions" class="mt-1 block text-xl font-black tabular-nums text-red-800">Rs. 0.00</strong></div>
          <div class="rounded-xl border border-slate-200 bg-white p-4"><span class="block font-bold text-slate-600">Net payable</span><strong id="preview-net" class="mt-1 block text-2xl font-black tabular-nums text-slate-900">Rs. 0.00</strong></div>
          <div class="rounded-xl border-2 border-red-300 bg-red-50 p-4"><span id="preview-balance-label" class="block font-bold text-red-700">Salary balance to pay</span><strong id="preview-balance" class="mt-1 block text-2xl font-black tabular-nums text-red-800">Rs. 0.00</strong></div>
        </div>
      </div>

      <p id="salary-save-alert" role="alert" class="hidden rounded-xl border-2 border-red-300 bg-red-50 p-4 text-sm font-bold text-red-800"></p>
      <div class="mt-4 flex flex-col sm:flex-row items-center justify-end gap-3 text-right">
        <div id="existing-sheet-notice" class="hidden text-xs font-bold px-3 py-2 rounded-lg"></div>
        <div>
            <button type="button" onclick="loadSalaryPreview()" class="btn-secondary">Preview Calculation</button>
            <button type="submit" id="save-draft-btn" class="btn-primary salary-save-button ml-2" disabled>Save Draft Paysheet</button>
        </div>
      </div>
    </form>

    {{-- Paysheets Table List --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Paysheet #</th>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">Month</th>
              <th class="px-4 py-3 font-bold">Salary Period</th>
              <th class="px-4 py-3 font-bold">Cycle Mode</th>
              <th class="px-4 py-3 font-bold">Gross Salary</th>
              <th class="px-4 py-3 font-bold">Advances</th>
              <th class="px-4 py-3 font-bold">Total Deductions</th>
              <th class="px-4 py-3 font-bold">Employee EPF</th>
              <th class="px-4 py-3 font-bold text-red-600 font-extrabold">Net Payable</th>
              <th class="px-4 py-3 font-bold text-green-600">Paid</th>
              <th class="px-4 py-3 font-bold text-red-600">Balance</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($sheets as $sheet)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 font-mono font-bold text-red-600">{{ $sheet->number }}</td>
              <td class="px-4 py-3 font-bold text-slate-800">{{ $sheet->technician->name }}</td>
              <td class="px-4 py-3 font-mono">{{ \Carbon\Carbon::parse(data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_start))->format('F Y') }}</td>
              <td class="px-4 py-3">
                <div class="font-bold text-slate-800">{{ $sheet->period_start->format('d M Y') }}</div>
                <div class="text-slate-400">to {{ $sheet->period_end->format('d M Y') }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase {{ $sheet->salary_cycle_mode !== 'normal_calendar' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}">
                  {{ match ($sheet->salary_cycle_mode) { 'custom_cutoff' => 'Custom Cut-Off', 'custom_start' => 'Custom Start Day', default => 'Calendar' } }}
                </span>
              </td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->gross_salary, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->advances, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->total_deductions, 2) }}@if($sheet->repair_deduction_amount > 0)<a class="mt-1 block rounded-lg border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-bold text-amber-900" href="{{ route('technicians.salaries.slip',$sheet) }}#staff-bike-repair-deductions">{{ $sheet->status === 'Draft' ? 'Planned staff bike deduction' : 'Deducted for staff bike' }}: Rs. {{ number_format($sheet->repair_deduction_amount,2) }}</a>@endif</td>
              <td class="px-4 py-3 text-slate-500">Rs. {{ number_format($sheet->epf_employee, 2) }}</td>
              <td class="px-4 py-3 font-black text-slate-900">Rs. {{ number_format($sheet->net_payable, 2) }}</td>
              <td class="px-4 py-3 text-emerald-600 font-bold">Rs. {{ number_format($sheet->paid_amount, 2) }}</td>
              <td class="px-4 py-3 text-red-600 font-bold">
                @if($sheet->balance_amount < 0)
                  Overpaid Rs. {{ number_format(abs($sheet->balance_amount), 2) }}
                @else
                  Rs. {{ number_format($sheet->balance_amount, 2) }}
                @endif
              </td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider {{ $sheet->status === 'Paid' ? 'badge-sent' : 'badge-pending' }}">
                  {{ $sheet->status }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex gap-1 items-center">
                  <a href="{{ route('technicians.salaries.slip', $sheet) }}" class="btn-secondary !px-2.5 !py-1 text-xs">Slip</a>
                  @if($sheet->status === 'Draft')
                  <button type="button" onclick="populateForm({{ json_encode($sheet) }})" class="btn-secondary !px-2.5 !py-1 text-xs">Edit</button>
                  <form method="POST" action="{{ route('technicians.salaries.destroy', $sheet) }}" data-confirm="Delete this draft paysheet?">
                    @csrf<x-submission-token/> @method('DELETE')
                    <button type="submit" class="btn-secondary !px-2.5 !py-1 text-xs text-red-600">Delete</button>
                  </form>
                  <button type="button" onclick="openPaymentModal('finalize', '{{ route('technicians.salaries.finalize', $sheet) }}', '{{ $sheet->balance_amount }}', '{{ $sheet->number }}', '{{ route('technicians.salaries.repair-deductions', $sheet) }}')" class="btn-primary !px-2.5 !py-1 text-xs">Finalize</button>
                  @elseif($sheet->status !== 'Paid')
                  <button type="button" onclick="openPaymentModal('pay', '{{ route('technicians.salaries.pay', $sheet) }}', '{{ $sheet->balance_amount }}', '{{ $sheet->number }}', '{{ route('technicians.salaries.repair-deductions', $sheet) }}')" class="btn-secondary !px-2.5 !py-1 text-xs text-emerald-600">Mark Paid</button>
                  @if($sheet->status === 'Finalized' && (float) $sheet->paid_amount === 0.0)
                  <form method="POST" action="{{ route('technicians.salaries.reopen', $sheet) }}" data-confirm="Reopen this unpaid paysheet? Its deductions will be reversed until you finalize it again.">
                    @csrf<x-submission-token/>
                    <input type="hidden" name="reason" value="Review salary repair deductions before payment">
                    <button class="btn-secondary !px-2.5 !py-1 text-xs">Reopen to change deductions</button>
                  </form>
                  @endif
                  @endif
                  
                  <button type="button" onclick="viewBreakdown('{{ $sheet->number }}', '{{ $sheet->technician->name }}', '{{ $sheet->period_start->format('F Y') }}', '{{ $sheet->basic_salary }}', '{{ $sheet->ot_amount }}', '{{ $sheet->commission }}', '{{ $sheet->advances }}', '{{ $sheet->late_deduction }}', '{{ $sheet->early_leave_deduction }}', '{{ $sheet->half_day_deduction }}', '{{ $sheet->no_pay_deduction }}', '{{ $sheet->own_bike_deduction }}', '{{ $sheet->epf_employee }}', '{{ $sheet->other_deductions }}', '{{ $sheet->net_payable }}', '{{ $sheet->paid_amount }}', '{{ $sheet->balance_amount }}', '{{ $sheet->payment_method }}', '{{ $sheet->created_at->format('d M Y') }}')" class="btn-secondary !px-2.5 !py-1 text-xs">
                    🔎 View
                  </button>
                  <button type="button" onclick="window.print()" class="btn-secondary !px-2.5 !py-1 text-xs">
                    🖨️
                  </button>
                </div>
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="14" class="px-4 py-10 text-center text-slate-400">
                No salary sheets generated yet. Set up records above.
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="p-4 border-t border-slate-100 bg-slate-50/50">
        {{ $sheets->links() }}
      </div>
    </div>

    {{-- Paysheet Detailed Breakdown Modal --}}
    <dialog id="breakdownModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-lg print:border-none print:shadow-none print:p-0">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4 print:hidden">
        <h3 class="text-base font-black text-slate-800">📄 Paysheet Breakdown</h3>
        <button type="button" onclick="closeBreakdown()" class="text-slate-400 font-bold hover:text-slate-700">✕</button>
      </div>

      <div class="space-y-4 text-xs">
        {{-- Invoice styled header for Print --}}
        <div class="text-center pb-4 border-b border-slate-100">
          <h2 class="text-lg font-black tracking-tight text-red-600 uppercase">Fasmir Motors</h2>
          <p class="text-[10px] text-slate-400">Bike Repair Center & Spare Parts</p>
          <div class="mt-2 text-slate-800 font-bold">PAYSHEET: <span id="mdNumber" class="text-red-600"></span></div>
        </div>

        <div class="grid grid-cols-2 gap-2 border-b border-slate-100 pb-3">
          <div><span class="text-slate-400">Technician:</span> <strong id="mdTech" class="text-slate-900 block text-sm"></strong></div>
          <div class="text-right">
            <div><span class="text-slate-400">Salary Period:</span> <strong id="mdPeriod" class="text-slate-900 block"></strong></div>
            <div><span class="text-slate-400">Generated Date:</span> <span id="mdDate" class="text-slate-500"></span></div>
          </div>
        </div>

        {{-- Additions --}}
        <div>
          <h4 class="font-extrabold text-green-700 uppercase tracking-wide border-b border-green-100 pb-1 mb-2">➕ Income / Additions</h4>
          <table class="w-full text-slate-700">
            <tr>
              <td class="py-1">Basic Salary</td>
              <td class="text-right font-mono font-bold">Rs. <span id="mdBasic"></span></td>
            </tr>
            <tr>
              <td class="py-1">Overtime Payout (OT)</td>
              <td class="text-right font-mono font-bold text-green-600">Rs. <span id="mdOt"></span></td>
            </tr>
            <tr>
              <td class="py-1">Commissions</td>
              <td class="text-right font-mono font-bold text-green-600">Rs. <span id="mdCommission"></span></td>
            </tr>
            <tr class="border-t border-slate-100 font-extrabold text-slate-900">
              <td class="py-2">Gross Salary</td>
              <td class="text-right font-mono">Rs. <span id="mdGross"></span></td>
            </tr>
          </table>
        </div>

        {{-- Deductions --}}
        <div>
          <h4 class="font-extrabold text-red-700 uppercase tracking-wide border-b border-red-100 pb-1 mb-2">➖ Salary Deductions</h4>
          <table class="w-full text-slate-700">
            <tr>
              <td class="py-1">Advances Paid</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdAdvances"></span></td>
            </tr>
            <tr>
              <td class="py-1">Late Coming Deductions</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdLate"></span></td>
            </tr>
            <tr>
              <td class="py-1">Early Leave Deductions</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdEarly"></span></td>
            </tr>
            <tr>
              <td class="py-1">Half Day Deductions</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdHalf"></span></td>
            </tr>
            <tr>
              <td class="py-1">No Pay / Absent Deductions</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdNoPay"></span></td>
            </tr>
            <tr>
              <td class="py-1">Own Bike Repair deductions</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdBike"></span></td>
            </tr>
            <tr>
              <td class="py-1">Employee EPF Contribution (8%)</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdEpf"></span></td>
            </tr>
            <tr>
              <td class="py-1">Manual Deductions / Other</td>
              <td class="text-right font-mono font-bold text-red-600">Rs. <span id="mdOther"></span></td>
            </tr>
            <tr class="border-t border-slate-100 font-extrabold text-slate-900">
              <td class="py-2">Total Deductions</td>
              <td class="text-right font-mono">Rs. <span id="mdTotalDeductions"></span></td>
            </tr>
          </table>
        </div>

        {{-- Final Net Payable --}}
        <div class="border-t-2 border-dashed border-slate-200 pt-3">
          <table class="w-full text-sm">
            <tr class="font-black text-slate-900 text-base">
              <td>Net Salary Payable</td>
              <td class="text-right text-red-600 font-mono">Rs. <span id="mdNet"></span></td>
            </tr>
            <tr class="text-slate-600 font-bold">
              <td>Paid Amount</td>
              <td class="text-right text-emerald-600 font-mono">Rs. <span id="mdPaid"></span></td>
            </tr>
            <tr class="text-slate-600 font-bold border-b border-slate-100 pb-2">
              <td>Balance Due</td>
              <td class="text-right text-red-500 font-mono">Rs. <span id="mdBalance"></span></td>
            </tr>
            <tr class="text-[10px] text-slate-400">
              <td class="pt-2">Payment Method: <strong id="mdMethod" class="text-slate-600"></strong></td>
              <td class="text-right pt-2">Authorized Signature: ________________</td>
            </tr>
          </table>
        </div>

        {{-- Print Action --}}
        <div class="flex justify-end gap-2 pt-4 print:hidden">
          <button type="button" onclick="closeBreakdown()" class="btn-secondary">Close</button>
          <button type="button" onclick="window.print()" class="btn-primary shadow-lg shadow-red-200">🖨️ Print Paysheet</button>
        </div>
      </div>
    </dialog>

  </div>

  <script>
    function viewBreakdown(number, name, period, basic, ot, commission, advances, late, early, half, noPay, bike, epf, other, net, paid, balance, method, date) {
      const basicVal = parseFloat(basic) || 0;
      const otVal = parseFloat(ot) || 0;
      const commVal = parseFloat(commission) || 0;
      const advancesVal = parseFloat(advances) || 0;
      const lateVal = parseFloat(late) || 0;
      const earlyVal = parseFloat(early) || 0;
      const halfVal = parseFloat(half) || 0;
      const noPayVal = parseFloat(noPay) || 0;
      const bikeVal = parseFloat(bike) || 0;
      const epfVal = parseFloat(epf) || 0;
      const otherVal = parseFloat(other) || 0;

      const gross = basicVal + otVal + commVal;
      const totalDeductions = advancesVal + lateVal + earlyVal + halfVal + noPayVal + bikeVal + epfVal + otherVal;

      document.getElementById('mdNumber').textContent = number;
      document.getElementById('mdTech').textContent = name;
      document.getElementById('mdPeriod').textContent = period;
      document.getElementById('mdDate').textContent = date;
      document.getElementById('mdMethod').textContent = method || 'Not disbursed';

      document.getElementById('mdBasic').textContent = basicVal.toFixed(2);
      document.getElementById('mdOt').textContent = otVal.toFixed(2);
      document.getElementById('mdCommission').textContent = commVal.toFixed(2);
      document.getElementById('mdGross').textContent = gross.toFixed(2);

      document.getElementById('mdAdvances').textContent = advancesVal.toFixed(2);
      document.getElementById('mdLate').textContent = lateVal.toFixed(2);
      document.getElementById('mdEarly').textContent = earlyVal.toFixed(2);
      document.getElementById('mdHalf').textContent = halfVal.toFixed(2);
      document.getElementById('mdNoPay').textContent = noPayVal.toFixed(2);
      document.getElementById('mdBike').textContent = bikeVal.toFixed(2);
      document.getElementById('mdEpf').textContent = epfVal.toFixed(2);
      document.getElementById('mdOther').textContent = otherVal.toFixed(2);
      document.getElementById('mdTotalDeductions').textContent = totalDeductions.toFixed(2);

      document.getElementById('mdNet').textContent = (parseFloat(net) || 0).toFixed(2);
      document.getElementById('mdPaid').textContent = (parseFloat(paid) || 0).toFixed(2);
      document.getElementById('mdBalance').textContent = (parseFloat(balance) || 0).toFixed(2);

      document.getElementById('breakdownModal').showModal();
    }

    function closeBreakdown() {
      document.getElementById('breakdownModal').close();
    }

    function populateForm(sheet) {
        document.querySelector('select[name="technician_id"]').value = sheet.technician_id;
        document.querySelector('input[name="month"]').value = sheet.period_start.substring(0, 7);
        
        if (sheet.additions.length > 0) {
            document.querySelector('input[name="addition_name"]').value = sheet.additions[0].addition_name || '';
            document.querySelector('input[name="manual_additions"]').value = sheet.additions[0].amount || '0';
        }
        
        // Note: Dynamic deductions (Alpine repeater) must be re-added manually when editing a draft.
        
        document.querySelector('input[name="allowances"]').value = sheet.allowances || '0';
        
        // Payment fields were removed from this form.
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
        loadSalaryPreview();
    }

    let salaryPreviewRequest = 0;
    let salaryPreviewData = null;
    let salaryPreviewContext = '';
    let salaryPreviewLoading = false;
    let salaryPreviewFailed = false;
    const salaryMoney = value => 'Rs. ' + (Number(value) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const salaryCents = value => Math.round((Number(value) || 0) * 100);

    function showSalarySaveAlert(id, message) {
        const alert = document.getElementById(id);
        alert.textContent = message;
        alert.classList.toggle('hidden', !message);
    }

    function validateSalaryAmount(input) {
        input.setCustomValidity('');
        const validity = input.validity;
        let message = '';
        if (validity.rangeOverflow) {
            message = `Maximum allowed: ${salaryMoney(input.max)}. Reduce this amount by ${salaryMoney((salaryCents(input.value) - salaryCents(input.max)) / 100)}.`;
        } else if (validity.rangeUnderflow) {
            message = `Enter at least ${salaryMoney(input.min)}.`;
        } else if (validity.badInput || validity.stepMismatch || validity.valueMissing) {
            message = 'Enter a valid amount with up to two decimal places. Enter 0 to skip a repair.';
        }
        input.setCustomValidity(message);
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        const row = input.closest('[data-repair-preview], [data-payment-repair]');
        if (row) {
            row.classList.toggle('salary-deduction-invalid', Boolean(message));
            const error = row.querySelector('[data-salary-amount-error]');
            error.textContent = message;
            error.classList.toggle('hidden', !message);
        }
        return message;
    }

    function manualSalaryDeductionTotal() {
        return Array.from(document.querySelectorAll('input[name^="manual_deductions"][name$="[amount]"]'))
            .reduce((sum, input) => sum + salaryCents(input.value), 0);
    }

    function updateSalaryDeductionPreview() {
        if (!salaryPreviewData) return;
        let bikeTotal = 0;
        let remainingTotal = 0;
        let invalid = false;
        document.querySelectorAll('#bike-installment-inputs input').forEach(input => {
            const amount = salaryCents(input.value);
            const remaining = salaryCents(input.max);
            const message = validateSalaryAmount(input);
            invalid ||= Boolean(message);
            bikeTotal += amount;
            remainingTotal += Math.max(0, remaining - amount);
            input.closest('[data-repair-preview]').querySelector('[data-repair-balance]').textContent = salaryMoney(Math.max(0, remaining - amount) / 100);
        });
        const originalBikeTotal = (salaryPreviewData.pending_deductions?.items || [])
            .filter(item => item.kind === 'bike_installment')
            .reduce((sum, item) => sum + salaryCents(item.amount), 0);
        const delta = bikeTotal - originalBikeTotal + manualSalaryDeductionTotal() - salaryCents(salaryPreviewData.manual_deductions);
        const totalDeductions = salaryCents(salaryPreviewData.total_deductions) + delta;
        const exceedsSalary = totalDeductions > salaryCents(salaryPreviewData.gross_salary);
        const net = Math.max(0, salaryCents(salaryPreviewData.net_payable) - delta);
        const balance = net - salaryCents(salaryPreviewData.paid_amount);
        document.getElementById('bike-deduction-preview-total').textContent = salaryMoney(bikeTotal / 100);
        document.getElementById('bike-deduction-preview-balance').textContent = salaryMoney(remainingTotal / 100);
        document.getElementById('preview-deductions').textContent = salaryMoney(totalDeductions / 100);
        document.getElementById('preview-net').textContent = salaryMoney(net / 100);
        document.getElementById('preview-balance-label').textContent = balance < 0 ? 'Overpaid' : 'Salary balance to pay';
        document.getElementById('preview-balance').textContent = salaryMoney(Math.abs(balance) / 100);
        document.getElementById('repair-deduction-warning').textContent = invalid
            ? 'A repair deduction exceeds its balance or is invalid. Correct the amount before saving.'
            : exceedsSalary ? 'Deductions exceed gross salary. Reduce the selected deductions before saving.'
            : salaryPreviewData.repair_deduction_warning || '';
        const blocked = salaryPreviewData.existing_sheet_status && !(salaryPreviewData.existing_sheet_status === 'Draft' && salaryPreviewData.existing_sheet_can_replace);
        document.getElementById('save-draft-btn').disabled = salaryPreviewLoading || salaryPreviewFailed || invalid || exceedsSalary || Boolean(blocked);
        showSalarySaveAlert('salary-save-alert', invalid
            ? 'Cannot save: a repair deduction is invalid or exceeds the repair balance. Correct the highlighted amount.'
            : exceedsSalary ? 'Cannot save: total deductions exceed the salary available. Reduce the deduction amounts.' : '');
    }

    function loadSalaryPreview() {
        const requestId = ++salaryPreviewRequest;
        const technician_id = document.querySelector('select[name="technician_id"]').value;
        const month = document.querySelector('input[name="month"]').value;
        const context = `${technician_id}:${month}`;
        if (context !== salaryPreviewContext) {
            salaryPreviewData = null;
            salaryPreviewContext = context;
            document.getElementById('bike-installment-inputs').replaceChildren();
            document.getElementById('bike-deduction-preview').classList.add('hidden');
            document.getElementById('salary-cycle-preview').classList.add('hidden');
            document.getElementById('existing-sheet-notice').classList.add('hidden');
            showSalarySaveAlert('salary-save-alert', '');
        }
        document.getElementById('save-draft-btn').disabled = true;
        salaryPreviewLoading = false;
        if (!technician_id || !month) return;
        salaryPreviewLoading = true;
        salaryPreviewFailed = false;
        
        const params = new URLSearchParams({ technician_id, month });
        document.querySelectorAll('#bike-installment-inputs input').forEach(input => {
            if (input.dataset.technician === technician_id && input.dataset.month === month) params.set(input.name, input.value);
        });
        
        params.set('manual_deduction', (manualSalaryDeductionTotal() / 100).toFixed(2));
        
        fetch(`{{ route('technicians.salaries.preview') }}?${params.toString()}`)
        .then(async res => {
            const data = await res.json();
            if (!res.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Salary preview could not be loaded.');
            return data;
        })
        .then(data => {
            if (requestId !== salaryPreviewRequest) return;
            salaryPreviewLoading = false;
            salaryPreviewData = data;
            renderSalaryPreview(data);
            const holder = document.getElementById('bike-installment-inputs');
            const bikeItems = (data.pending_deductions?.items || []).filter(item => item.kind === 'bike_installment');
            document.getElementById('bike-deduction-preview').classList.toggle('hidden', !bikeItems.length);
            const eligibleNames = new Set(bikeItems.map(item => `bike_installments[${item.id}]`));
            holder.querySelectorAll('input').forEach(input => {
                if (!eligibleNames.has(input.name)) input.closest('[data-repair-preview]').remove();
            });
            bikeItems.forEach(item => {
                // Keep the input node and its latest value when a refresh finishes during typing.
                const existing = Array.from(holder.querySelectorAll('input')).find(input => input.name === `bike_installments[${item.id}]`);
                if (existing) {
                    existing.max = item.remaining_balance;
                    existing.closest('[data-repair-preview]').querySelector('[data-repair-before]').textContent = salaryMoney(item.remaining_balance);
                    return;
                }
                const card = document.createElement('div');
                card.className = 'space-y-3 rounded-xl border border-amber-200 bg-white p-4';
                card.dataset.repairPreview = '';
                const title = document.createElement('p');
                title.className = 'text-sm font-extrabold text-slate-900';
                title.textContent = `${item.job_number || item.reference_no} · ${item.bike_number || 'Staff bike'}`;
                const before = document.createElement('p');
                before.className = 'text-sm font-bold text-slate-600';
                before.textContent = 'Repair balance before deduction';
                const beforeAmount = document.createElement('strong');
                beforeAmount.className = 'mt-1 block text-xl font-black tabular-nums text-slate-900';
                beforeAmount.dataset.repairBefore = '';
                beforeAmount.textContent = salaryMoney(item.remaining_balance);
                before.append(beforeAmount);
                const label = document.createElement('label');
                label.className = 'block text-sm font-extrabold text-red-700';
                label.textContent = 'Deduct from this salary (LKR)';
                const input = document.createElement('input');
                Object.assign(input, { type: 'number', min: '0', max: item.remaining_balance, step: '0.01', name: `bike_installments[${item.id}]`, value: item.amount, className: 'field mt-2 text-lg font-black text-red-700', required: true });
                input.dataset.technician = technician_id;
                input.dataset.month = month;
                input.addEventListener('input', updateSalaryDeductionPreview);
                label.append(input);
                const error = document.createElement('p');
                error.id = `draft-repair-error-${item.id}`;
                error.dataset.salaryAmountError = '';
                error.className = 'hidden text-sm font-bold text-red-800';
                input.setAttribute('aria-describedby', error.id);
                const after = document.createElement('p');
                after.className = 'rounded-lg bg-emerald-50 p-3 text-sm font-bold text-emerald-900';
                after.textContent = 'Repair balance after deduction';
                const afterAmount = document.createElement('strong');
                afterAmount.className = 'mt-1 block text-2xl font-black tabular-nums';
                afterAmount.dataset.repairBalance = '';
                after.append(afterAmount);
                card.append(title, before, label, error, after);
                holder.append(card);
            });
            const notice = document.getElementById('existing-sheet-notice');
            if (data.existing_sheet_status) {
                notice.classList.remove('hidden');
                if (data.existing_sheet_status === 'Draft' && data.existing_sheet_can_replace) {
                    notice.classList.add('bg-orange-100', 'text-orange-800');
                    notice.classList.remove('bg-red-100', 'text-red-800');
                    notice.innerHTML = '⚠️ A Draft paysheet already exists for this month. Saving will overwrite it.';
                } else {
                    notice.classList.add('bg-red-100', 'text-red-800');
                    notice.classList.remove('bg-orange-100', 'text-orange-800');
                    notice.textContent = 'A ' + data.existing_sheet_status + ' paysheet already covers this month or overlaps these dates. Resolve that sheet before generating another salary.';
                    document.getElementById('save-draft-btn').disabled = true;
                }
            } else {
                notice.classList.add('hidden');
            }
            updateSalaryDeductionPreview();
        }).catch(err => {
            if (requestId !== salaryPreviewRequest) return;
            salaryPreviewLoading = false;
            salaryPreviewFailed = true;
            document.getElementById('save-draft-btn').disabled = true;
            workshopNotify(err.message);
        });
    }

    function renderSalaryPreview(data) {
        const box = document.getElementById('salary-cycle-preview');
        box.classList.remove('hidden');
        const money = value => 'Rs. ' + (parseFloat(value) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const fmtDate = value => value ? new Date(value + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
        const excluded = Array.isArray(data.excluded_date_labels) && data.excluded_date_labels.length
            ? data.excluded_date_labels.join(', ')
            : '—';
        const attendance = data.attendance_summary || data.attendance || {};

        document.getElementById('preview-cycle-label').textContent = data.salary_cycle_label || 'Calendar Month Salary';
        document.getElementById('preview-cycle-badge').textContent = data.salary_cycle_label || 'Calendar Month Salary';
        document.getElementById('preview-cycle-period').textContent = `Salary Month: ${data.salary_month_label || ''} · Salary Period: ${fmtDate(data.salary_period_start)} to ${fmtDate(data.salary_period_end)}`;
        document.getElementById('preview-period-days').textContent = data.salary_period_days || 0;
        document.getElementById('preview-working-days').textContent = attendance.total_working_days || data.total_working_days || 0;
        document.getElementById('preview-leave-days').textContent = attendance.leave_days || data.leave_days || 0;
        document.getElementById('preview-present-days').textContent = attendance.present_days || data.total_present_days || 0;
        document.getElementById('preview-absent-days').textContent = attendance.absent_days || data.total_absent_days || 0;
        document.getElementById('preview-ot-hours').textContent = attendance.total_ot_hours || data.total_ot_hours || 0;
        document.getElementById('preview-prev-days').textContent = data.carried_from_previous_month_days || 0;
        document.getElementById('preview-current-days').textContent = data.current_month_days_included || 0;
        document.getElementById('preview-excluded-days').textContent = excluded;
        document.getElementById('preview-gross').textContent = money(data.gross_salary);
        document.getElementById('preview-deductions').textContent = money(data.total_deductions);
        document.getElementById('preview-net').textContent = money(data.net_payable);
        document.getElementById('preview-balance-label').textContent = parseFloat(data.balance_amount || 0) < 0 ? 'Overpaid' : 'Balance';
        document.getElementById('preview-balance').textContent = money(Math.abs(parseFloat(data.balance_amount || 0)));
    }

    let repairPaymentRequest = 0;
    let repairPaymentData = null;
    let repairPaymentLoading = false;
    function openPaymentModal(action, url, balance, sheetNumber, repairUrl) {
        const requestId = ++repairPaymentRequest;
        repairPaymentData = null;
        repairPaymentLoading = true;
        showSalarySaveAlert('payment-save-alert', '');
        const form = document.getElementById('payment-form');
        form.action = url;
        const amountInput = document.getElementById('payment-amount');
        amountInput.value = balance;
        amountInput.max = Math.max(0, Number(balance));
        
        const desc = document.getElementById('payment-modal-desc');
        const title = document.getElementById('payment-modal-title');
        const btn = document.getElementById('payment-submit-btn');
        
        if (action === 'finalize') {
            title.textContent = 'Finalize Paysheet ' + sheetNumber;
            desc.textContent = 'You can process the payment now, or leave the amount as 0 to just finalize and lock it.';
            btn.textContent = 'Finalize & Save';
            amountInput.required = false;
            amountInput.min = 0;
        } else {
            title.textContent = 'Pay Balance for ' + sheetNumber;
            desc.textContent = 'Record a payment for this finalized paysheet.';
            btn.textContent = 'Record Payment';
            amountInput.required = true;
            amountInput.min = 0.01;
        }
        
        updatePaymentRequiredFields();
        Flux.modal('payment-modal').show();
        const holder = document.getElementById('payment-repair-rows');
        holder.replaceChildren();
        document.getElementById('payment-repair-total').textContent = '';
        document.getElementById('payment-repair-summary').textContent = 'Loading repair balances…';
        document.getElementById('repair-selection-reviewed').disabled = true;
        btn.disabled = true;
        fetch(repairUrl).then(async response => {
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Repair balances could not be loaded.');
            return data;
        }).then(data => {
            if (requestId !== repairPaymentRequest) return;
            repairPaymentData = data;
            repairPaymentLoading = false;
            document.getElementById('repair-selection-reviewed').disabled = !data.editable;
            form.dataset.suggestedBalance = balance;
            document.getElementById('payment-repair-summary').textContent = data.editable
                ? 'Choose an amount against each repair, or 0 to skip. Finalizing salary applies these deductions to the bills.'
                : 'These repair deductions were already applied at finalization. This payment will not deduct them again.';
            (data.rows || []).forEach(item => {
                const row = document.createElement('div');
                row.className = 'rounded-xl border border-slate-200 p-3 space-y-2';
                row.dataset.paymentRepair = '';
                const title = document.createElement('p');
                title.className = 'font-bold text-sm';
                title.textContent = `${item.job_number || item.reference_no} · ${item.bike_number || 'Staff bike'}`;
                const detail = document.createElement('p');
                detail.className = 'text-xs text-slate-500';
                detail.textContent = `${item.invoice_number || item.reference_no} · Repair balance Rs. ${Number(item.remaining_balance).toFixed(2)}`;
                row.append(title, detail);
                const label = document.createElement('label');
                label.className = 'block text-xs font-semibold';
                label.textContent = data.editable ? 'Deduct from this salary (LKR)' : `Rs. ${Number(item.amount).toFixed(2)} deducted from ${item.salary_number}`;
                if (data.editable) {
                    const input = document.createElement('input');
                    Object.assign(input, {type:'number', min:'0', max:String(item.remaining_balance), step:'0.01', name:`bike_installments[${item.id}]`, value:item.amount, className:'field mt-1', required:true});
                    input.addEventListener('input', () => updateRepairPaymentTotal(data));
                    input.setAttribute('aria-describedby', `payment-repair-error-${item.id}`);
                    label.append(input);
                }
                row.append(label);
                const error = document.createElement('p');
                error.id = `payment-repair-error-${item.id}`;
                error.dataset.salaryAmountError = '';
                error.className = 'hidden text-sm font-bold text-red-800';
                row.append(error);
                if (item.correction_url) {
                    const link = document.createElement('a');
                    link.href = item.correction_url;
                    link.className = 'inline-block text-sm font-bold text-blue-700 underline';
                    link.textContent = 'Correct deduction with reason';
                    row.append(link);
                }
                holder.append(row);
            });
            if (!data.rows.length) document.getElementById('payment-repair-summary').textContent = 'No staff bike repair deductions for this paysheet.';
            if (data.editable) updateRepairPaymentTotal(data);
            else updatePaymentRequiredFields();
        }).catch(error => {
            if (requestId !== repairPaymentRequest) return;
            document.getElementById('payment-repair-summary').textContent = error.message;
            showSalarySaveAlert('payment-save-alert', 'Cannot save: repair balances could not be checked. Close and reopen this payment to retry.');
        });
    }

    function updateRepairPaymentTotal(data) {
        const form = document.getElementById('payment-form');
        const total = [...document.querySelectorAll('#payment-repair-rows input')].reduce((sum, input) => sum + (Number(input.value) || 0), 0);
        const balance = Math.max(0, Math.round((data.net_before_repairs - total - data.paid_amount) * 100) / 100);
        const amountInput = document.getElementById('payment-amount');
        if (Number(amountInput.value) === Number(form.dataset.suggestedBalance)) amountInput.value = balance.toFixed(2);
        amountInput.max = balance.toFixed(2);
        form.dataset.suggestedBalance = balance;
        document.getElementById('payment-repair-total').textContent = `Repair deductions Rs. ${total.toFixed(2)} · Salary balance Rs. ${balance.toFixed(2)}`;
        updatePaymentRequiredFields();
    }

    function updatePaymentRequiredFields() {
        const amountInput = document.getElementById('payment-amount');
        const amount = parseFloat(amountInput.value) || 0;
        const isPay = amount > 0;
        document.getElementById('payment-account').required = isPay;
        document.getElementById('payment-method-modal').required = isPay;
        let invalidRepair = false;
        let repairTotal = 0;
        document.querySelectorAll('#payment-repair-rows input').forEach(input => {
            invalidRepair = Boolean(validateSalaryAmount(input)) || invalidRepair;
            repairTotal += salaryCents(input.value);
        });
        const exceedsSalary = repairPaymentData?.editable && repairTotal > salaryCents(repairPaymentData.net_before_repairs);
        const paymentError = validateSalaryAmount(amountInput);
        const message = invalidRepair ? 'Cannot save: a repair deduction is invalid or exceeds the repair balance. Correct the highlighted amount.'
            : exceedsSalary ? 'Cannot save: repair deductions exceed the salary available. Reduce the deduction amounts.'
            : paymentError ? `Cannot save payment. ${paymentError}` : '';
        document.getElementById('payment-submit-btn').disabled = repairPaymentLoading || !repairPaymentData || Boolean(message);
        showSalarySaveAlert('payment-save-alert', repairPaymentData ? message : '');
    }
  </script>

  <flux:modal name="payment-modal" class="w-full max-w-2xl max-h-[85vh] overflow-y-auto" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important;">
      <form method="POST" action="" id="payment-form" onsubmit="updatePaymentRequiredFields(); return !document.getElementById('payment-submit-btn').disabled;" class="space-y-4">
          @csrf<x-submission-token/>
          
          <h2 class="text-base font-black text-slate-800" id="payment-modal-title">Process Payment</h2>
          <p class="text-xs text-slate-500 pb-2" id="payment-modal-desc">Enter payment details.</p>
          <section class="space-y-3 rounded-xl bg-slate-50 p-3">
              <h3 class="font-bold text-sm">Staff bike repairs</h3>
              <p id="payment-repair-summary" class="text-xs text-slate-500"></p>
              <input type="hidden" name="repair_selection_reviewed" value="1" id="repair-selection-reviewed" disabled>
              <div id="payment-repair-rows" class="space-y-2"></div>
              <p id="payment-repair-total" class="text-sm font-bold"></p>
          </section>

          <div>
              <label class="label">Amount (Rs.) <span class="required-star">*</span></label>
              <input type="number" step="0.01" min="0" name="amount" id="payment-amount" class="w-full font-mono font-bold text-lg text-emerald-700" required>
          </div>
          
          <div>
              <label class="label">Account <span class="required-star">*</span></label>
              <select name="account_id" id="payment-account" class="w-full" required>
                  <option value="">Select account</option>
                  @foreach($accounts as $account)
                  <option value="{{ $account->id }}" @selected($account->code === 'MAIN' || $account->type === 'main')>{{ $account->name }}</option>
                  @endforeach
              </select>
          </div>

          <div class="grid grid-cols-2 gap-4">
              <div>
                  <label class="label">Payment Method <span class="required-star">*</span></label>
                  <select name="payment_method" id="payment-method-modal" class="w-full" required>
                      @foreach(['Cash','Bank Transfer','Cheque','Card','Online','Other'] as $method)
                      <option>{{ $method }}</option>
                      @endforeach
                  </select>
              </div>
              <div>
                  <label class="label">Payment Date <span class="required-star">*</span></label>
                  <input type="date" name="payment_date" value="{{ today()->toDateString() }}" class="w-full" required>
              </div>
          </div>

          <div>
              <label class="label">Note (Optional)</label>
              <input type="text" name="payment_note" class="w-full" placeholder="Internal note">
          </div>

          <p id="payment-save-alert" role="alert" class="hidden rounded-xl border-2 border-red-300 bg-red-50 p-4 text-sm font-bold text-red-800"></p>
          <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
              <flux:modal.close>
                  <button type="button" class="btn-secondary !px-4 !py-2">Cancel</button>
              </flux:modal.close>
              <button type="submit" id="payment-submit-btn" class="btn-primary salary-save-button !px-4 !py-2" disabled>Finalize & Save</button>
          </div>
      </form>
  </flux:modal>
  
  <script>
    document.getElementById('payment-amount').addEventListener('input', updatePaymentRequiredFields);
  </script>
</x-layouts::app>
