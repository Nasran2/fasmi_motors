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
      <p class="text-xs text-slate-400">Attendance, OT, advances, own-bike repair installments and EPF/ETF are computed automatically. Commission is paid separately from the Commission page.</p>
      
      <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label">Technician <span class="required-star">*</span></label>
          <select name="technician_id" class="w-full" required onchange="loadSalaryPreview()">
            <option value="">Select technician</option>
            @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(old('technician_id', request('technician')) == $t->id)>{{ $t->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">Salary Month <span class="required-star">*</span></label>
          <input type="month" name="month" value="{{ old('month', now()->format('Y-m')) }}" required class="w-full" onchange="loadSalaryPreview()">
        </div>

        <div class="col-span-full rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <h3 class="font-bold text-emerald-800">Additional earning (optional)</h3>
          <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <label><span class="label">Description</span><input name="addition_name" maxlength="150" value="{{ old('addition_name') }}" placeholder="e.g. Performance bonus" class="field"></label>
            <label><span class="label">Amount · LKR</span><input name="manual_additions" value="{{ old('manual_additions', 0) }}" type="number" min="0" max="999999999999.99" step="0.01" oninput="updateSalaryDeductionPreview()" class="field"></label>
            <label><span class="label">Note printed on payslip</span><input name="addition_reason" maxlength="500" value="{{ old('addition_reason') }}" class="field"></label>
          </div>
          <p class="mt-2 text-xs text-emerald-800">After saving, choose View to add more described earnings or deductions.</p>
        </div>

        <div class="col-span-full md:col-span-2" x-data="{
            deductions: @js(old('manual_deductions', [])),
            addDeduction() { this.deductions.push({ name: '', amount: '', reason: '' }); },
            removeDeduction(index) { this.deductions.splice(index, 1); this.$nextTick(() => updateSalaryDeductionPreview()); }
        }">
          <div class="flex items-center justify-between">
              <label class="label font-bold">Manual Deductions <span class="text-[10px] text-slate-400 font-normal ml-1">(Optional)</span></label>
              <button type="button" @click="addDeduction()" class="text-[10px] uppercase tracking-wider text-blue-600 font-bold hover:text-blue-800 transition">+ Add Deduction</button>
          </div>
          
          <div class="mt-2 space-y-2">
              <template x-for="(deduction, index) in deductions" :key="index">
                  <div class="flex flex-wrap gap-2 items-center">
                      <input type="text" maxlength="150" x-model="deduction.name" :name="'manual_deductions['+index+'][name]'" placeholder="Deduction details (e.g. Loan, uniform)" class="w-3/5" required>
                      <input type="number" step="0.01" min="0" x-model="deduction.amount" @input="updateSalaryDeductionPreview()" :name="'manual_deductions['+index+'][amount]'" placeholder="Rs. Amount" max="999999999999.99" class="w-2/5" required>
                      <input type="text" x-model="deduction.reason" :name="'manual_deductions['+index+'][reason]'" maxlength="500" placeholder="Note printed on payslip (optional)" class="w-full">
                      <button type="button" @click="removeDeduction(index)" class="text-red-500 font-bold px-2 hover:bg-red-50 rounded-lg py-1 transition">✕</button>
                  </div>
              </template>
              <div x-show="deductions.length === 0" class="text-xs text-slate-400 p-2.5 bg-slate-50/50 rounded-xl border border-dashed border-slate-200">
                  No manual deductions. Click "+ Add Deduction" to add one.
              </div>
          </div>
        </div>

      </div>

      <section class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 space-y-3" id="old-balance-section">
        <h3 class="font-black text-amber-950">Old balance owed to shop</h3>
        <p class="text-sm text-amber-900">Enter an opening debt once. Choose how much to deduct this month; 0 skips recovery. The rest remains for the next month. Salary advances and repair bills already listed below must not be entered again.</p>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label><span class="label">New old balance · LKR</span><input name="old_balance_amount" type="number" min="0" max="999999999999.99" step="0.01" value="{{ old('old_balance_amount', 0) }}" class="field" oninput="updateSalaryDeductionPreview()"></label>
          <label><span class="label">Deduct this month · LKR</span><input name="old_balance_deduction" type="number" min="0" step="0.01" value="{{ old('old_balance_deduction', 0) }}" class="field" oninput="updateSalaryDeductionPreview()"></label>
          <label><span class="label">Description</span><input name="old_balance_description" maxlength="150" value="{{ old('old_balance_description') }}" placeholder="Old balance owed to shop" class="field"></label>
          <label><span class="label">Note on payslip</span><input name="old_balance_note" maxlength="500" value="{{ old('old_balance_note') }}" class="field"></label>
        </div>
        <div id="old-balance-installments" class="grid gap-3 sm:grid-cols-2"></div>
      </section>
      <section id="debt-recovery-preview" class="hidden rounded-2xl border-2 border-red-300 bg-red-50 p-4 space-y-3" aria-live="polite">
        <h3 class="font-black text-red-900">Old balances + repairs</h3>
        <div class="grid gap-3 sm:grid-cols-3"><div><p class="text-sm font-bold">Total owed before this paysheet</p><strong id="debt-opening" class="text-2xl font-black"></strong></div><div><p class="text-sm font-bold">Deducted from this salary</p><strong id="debt-deducted" class="text-2xl font-black text-emerald-800"></strong></div><div><p class="text-sm font-bold text-red-800">Balance carried to next month</p><strong id="debt-remaining" class="text-3xl font-black text-red-800"></strong></div></div>
        <p id="debt-shortfall" class="text-sm font-bold text-red-800"></p>
      </section>
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
            <p id="preview-pay-basis" class="mt-1 text-xs text-slate-700"></p>
            <p id="preview-cycle-period" class="mt-0.5 text-xs font-bold text-slate-600">Select technician and month to preview.</p>
          </div>
          <span id="preview-cycle-badge" class="rounded-full bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wider text-blue-700 shadow-sm">Calendar Month Salary</span>
        </div>
        <div class="mt-4 grid gap-2 text-xs sm:grid-cols-2 xl:grid-cols-6">
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Working days</span><strong id="preview-working-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Leave days</span><strong id="preview-leave-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Present days</span><strong id="preview-present-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Absent days</span><strong id="preview-absent-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">Half days</span><strong id="preview-half-days" class="text-slate-900">0</strong></div>
          <div class="rounded-xl bg-white p-3"><span class="block text-slate-400">OT hours</span><strong id="preview-ot-hours" class="text-slate-900">0</strong></div>
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
                  @if(in_array($sheet->status, ['Draft', 'Finalized', 'Partially Paid', 'Paid'], true))
                  <button type="button" class="btn-secondary !px-2.5 !py-1 text-xs text-blue-700" onclick="openSalaryRecalculation('{{ route('technicians.salaries.refresh-attendance', $sheet) }}', '{{ $sheet->number }}')">Recalculate</button>
                  @endif
                  @if($sheet->status === 'Draft')
                  <a href="{{ route('technicians.salaries.show', $sheet) }}#salary-adjustments" class="btn-secondary !px-2.5 !py-1 text-xs">Add / Deduct</a>
                  <form method="POST" action="{{ route('technicians.salaries.destroy', $sheet) }}" data-confirm="Delete this draft paysheet?">
                    @csrf<x-submission-token/> @method('DELETE')
                    <button type="submit" class="btn-secondary !px-2.5 !py-1 text-xs text-red-600">Delete</button>
                  </form>
                  <button type="button" onclick="openPaymentModal('finalize', '{{ route('technicians.salaries.finalize', $sheet) }}', '{{ $sheet->balance_amount }}', '{{ $sheet->number }}', '{{ route('technicians.salaries.repair-deductions', $sheet) }}')" class="btn-primary !px-2.5 !py-1 text-xs">Finalize</button>
                  @elseif($sheet->status !== 'Paid')
                  @if((float) $sheet->balance_amount > 0)
                  <button type="button" onclick="openPaymentModal('pay', '{{ route('technicians.salaries.pay', $sheet) }}', '{{ $sheet->balance_amount }}', '{{ $sheet->number }}', '{{ route('technicians.salaries.repair-deductions', $sheet) }}')" class="btn-secondary !px-2.5 !py-1 text-xs text-emerald-600">Pay / Partial Pay</button>
                  @else
                  <span class="text-xs text-slate-500">No cash due</span>
                  @endif
                  @if($sheet->status === 'Finalized' && (float) $sheet->paid_amount === 0.0)
                  <form method="POST" action="{{ route('technicians.salaries.reopen', $sheet) }}" data-confirm="Reopen this unpaid paysheet? Its deductions will be reversed until you finalize it again.">
                    @csrf<x-submission-token/>
                    <input type="hidden" name="reason" value="Review salary repair deductions before payment">
                    <button class="btn-secondary !px-2.5 !py-1 text-xs">Reopen to change deductions</button>
                  </form>
                  @endif
                  @endif
                  
                  <a href="{{ route('technicians.salaries.show', $sheet) }}" class="btn-secondary !px-2.5 !py-1 text-xs">View</a>
                  <a target="_blank" href="{{ route('technicians.salaries.slip', ['sheet' => $sheet, 'print' => 1]) }}" class="btn-secondary !px-2.5 !py-1 text-xs" aria-label="Print salary slip {{ $sheet->number }}">🖨️</a>
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

  </div>

  <script>
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
        const oldAmountInput = document.querySelector('[name="old_balance_amount"]');
        const newChoice = document.querySelector('[name="old_balance_deduction"]');
        newChoice.max = oldAmountInput.value || '0';
        const oldInputs = [...document.querySelectorAll('#old-balance-installments input'), newChoice];
        const repairs = [...document.querySelectorAll('#bike-installment-inputs input')];
        const oldSummary = salaryPreviewData.debt_summary || {};
        const originalRepair = (salaryPreviewData.pending_deductions?.items || []).filter(item => item.kind === 'bike_installment').reduce((sum,item) => sum + salaryCents(item.amount),0);
        const fixed = salaryCents(salaryPreviewData.total_deductions) - originalRepair - salaryCents(oldSummary.applied_old_balance)
            + manualSalaryDeductionTotal() - salaryCents(salaryPreviewData.manual_deductions);
        const gross = salaryCents(salaryPreviewData.gross_salary) + salaryCents(document.querySelector('[name="manual_additions"]').value) - salaryCents(salaryPreviewData.manual_additions);
        let capacity = Math.max(0, gross - fixed);
        let oldTotal = salaryCents(oldAmountInput.value), repairTotal = 0, appliedOld = 0, appliedRepair = 0, requested = 0;
        let invalid = Boolean(validateSalaryAmount(oldAmountInput));
        oldInputs.forEach(input => {
            invalid = Boolean(validateSalaryAmount(input)) || invalid;
            if (input !== newChoice) oldTotal += salaryCents(input.max);
            const amount = Math.max(0, salaryCents(input.value));
            requested += amount;
            const applied = Math.min(capacity, amount); capacity -= applied; appliedOld += applied;
        });
        repairs.forEach(input => {
            invalid = Boolean(validateSalaryAmount(input)) || invalid;
            const amount = Math.max(0, salaryCents(input.value)), remaining = salaryCents(input.max);
            requested += amount; repairTotal += remaining;
            const applied = Math.min(capacity, amount); capacity -= applied; appliedRepair += applied;
            input.closest('[data-repair-preview]').querySelector('[data-repair-balance]').textContent = salaryMoney((remaining - applied) / 100);
        });
        const total = fixed + appliedOld + appliedRepair;
        const exceedsSalary = fixed > gross;
        const net = Math.max(0, gross - total), balance = net - salaryCents(salaryPreviewData.paid_amount);
        document.getElementById('preview-gross').textContent = salaryMoney(gross / 100);
        document.getElementById('preview-deductions').textContent = salaryMoney(total / 100);
        document.getElementById('preview-net').textContent = salaryMoney(net / 100);
        document.getElementById('preview-balance-label').textContent = balance < 0 ? 'Overpaid' : 'Salary balance to pay';
        document.getElementById('preview-balance').textContent = salaryMoney(Math.abs(balance) / 100);
        document.getElementById('bike-deduction-preview-total').textContent = salaryMoney(appliedRepair / 100);
        document.getElementById('bike-deduction-preview-balance').textContent = salaryMoney((repairTotal - appliedRepair) / 100);
        document.getElementById('debt-recovery-preview').classList.toggle('hidden', oldTotal + repairTotal <= 0);
        document.getElementById('debt-opening').textContent = salaryMoney((oldTotal + repairTotal) / 100);
        document.getElementById('debt-deducted').textContent = salaryMoney((appliedOld + appliedRepair) / 100);
        document.getElementById('debt-remaining').textContent = salaryMoney((oldTotal + repairTotal - appliedOld - appliedRepair) / 100);
        document.getElementById('debt-shortfall').textContent = requested > gross - fixed
            ? `Salary after requested deductions: ${salaryMoney((gross - fixed - requested) / 100)}. Recovery is limited to this salary; the remaining debt carries forward.`
            : 'Other deductions are applied first, then old balances and repairs. Remaining debt is offered next month; choose the amount to deduct then.';
        document.getElementById('repair-deduction-warning').textContent = invalid ? 'An amount is invalid or exceeds its outstanding balance.' : '';
        const blocked = salaryPreviewData.existing_sheet_status && !(salaryPreviewData.existing_sheet_status === 'Draft' && salaryPreviewData.existing_sheet_can_replace);
        document.getElementById('save-draft-btn').disabled = salaryPreviewLoading || salaryPreviewFailed || invalid || exceedsSalary || Boolean(blocked);
        showSalarySaveAlert('salary-save-alert', invalid ? 'Cannot save: correct the highlighted deduction amounts.'
            : exceedsSalary ? 'Cannot save: ordinary deductions exceed salary. Enter recoverable old debts in Old balance owed to shop instead of Manual Deductions.' : '');
    }

    function loadSalaryPreview() {
        const requestId = ++salaryPreviewRequest;
        const technician_id = document.querySelector('select[name="technician_id"]').value;
        const month = document.querySelector('input[name="month"]').value;
        const context = `${technician_id}:${month}`;
        if (context !== salaryPreviewContext) {
            if (salaryPreviewContext) {
                for (const name of ['old_balance_amount','old_balance_deduction']) document.querySelector(`[name="${name}"]`).value = '0';
                for (const name of ['old_balance_description','old_balance_note']) document.querySelector(`[name="${name}"]`).value = '';
            }
            document.getElementById('old-balance-installments').replaceChildren();
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
        
        for (const name of ['old_balance_amount','old_balance_deduction','old_balance_description','old_balance_note']) params.set(name, document.querySelector(`[name="${name}"]`).value || (name.endsWith('amount') || name.endsWith('deduction') ? '0' : ''));
        document.querySelectorAll('#old-balance-installments input').forEach(input => params.set(input.name, input.value));
        params.set('manual_addition', document.querySelector('[name="manual_additions"]').value || '0');
        params.set('manual_deduction', (manualSalaryDeductionTotal() / 100).toFixed(2));
        
        fetch(`{{ route('technicians.salaries.preview') }}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(async res => {
            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Server returned an unexpected response. Please refresh and try again.');
            }
            const data = await res.json();
            if (!res.ok) {
                const message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Salary preview could not be loaded.';
                throw new Error(message);
            }
            return data;
        })
        .then(data => {
            if (requestId !== salaryPreviewRequest) return;
            salaryPreviewLoading = false;
            salaryPreviewData = data;
            renderSalaryPreview(data);
            const oldHolder = document.getElementById('old-balance-installments');
            const oldChoices = new Map([...oldHolder.querySelectorAll('input')].map(input => [input.name, input.value]));
            oldHolder.replaceChildren();
            (data.old_balances || []).filter(item => item.id !== 'new').forEach(item => {
                const label = document.createElement('label'); label.className = 'rounded-xl border border-amber-200 bg-white p-3';
                const title = document.createElement('span'); title.className = 'block font-bold'; title.textContent = `${item.description} · ${item.reference || ''}`;
                const info = document.createElement('span'); info.className = 'block text-sm text-amber-900'; info.textContent = `Remaining ${salaryMoney(item.remaining_balance)} · Deduct this month (0 to skip)`;
                const input = document.createElement('input');
                const name = `old_balance_installments[${item.id}]`;
                Object.assign(input, {name, type:'number', min:'0', max:String(item.remaining_balance), step:'0.01', value:oldChoices.get(name) ?? item.requested_amount ?? 0, required:true, className:'field mt-2'});
                input.addEventListener('input', updateSalaryDeductionPreview); label.append(title, info, input); oldHolder.append(label);
            });
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
                Object.assign(input, { type: 'number', min: '0', max: item.remaining_balance, step: '0.01', name: `bike_installments[${item.id}]`, value: item.requested_amount ?? item.amount, className: 'field mt-2 text-lg font-black text-red-700', required: true });
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

        document.getElementById('preview-pay-basis').textContent = data.pay_basis || '';
        document.getElementById('preview-cycle-label').textContent = 'Salary summary';
        document.getElementById('preview-cycle-badge').textContent = data.salary_month_label || 'Salary';
        document.getElementById('preview-cycle-period').textContent = `Salary Month: ${data.salary_month_label || ''} · Salary Period: ${fmtDate(data.salary_period_start)} to ${fmtDate(data.salary_period_end)}`;
        document.getElementById('preview-working-days').textContent = parseFloat(attendance.total_working_days ?? data.total_working_days ?? 0);
        document.getElementById('preview-leave-days').textContent = parseFloat(attendance.leave_days ?? data.leave_days ?? 0);
        document.getElementById('preview-present-days').textContent = parseFloat(attendance.present_days ?? data.total_present_days ?? 0);
        document.getElementById('preview-absent-days').textContent = parseFloat(attendance.absent_days ?? data.total_absent_days ?? 0);
        document.getElementById('preview-half-days').textContent = parseFloat(attendance.half_days ?? data.half_days ?? 0);
        document.getElementById('preview-ot-hours').textContent = parseFloat(attendance.total_ot_hours ?? data.total_ot_hours ?? 0);
        document.getElementById('preview-gross').textContent = money(data.gross_salary);
        document.getElementById('preview-deductions').textContent = money(data.total_deductions);
        document.getElementById('preview-net').textContent = money(data.net_payable);
        document.getElementById('preview-balance-label').textContent = parseFloat(data.balance_amount || 0) < 0 ? 'Overpaid' : 'Balance';
        document.getElementById('preview-balance').textContent = money(Math.abs(parseFloat(data.balance_amount || 0)));
    }

    let repairPaymentRequest = 0;
    let repairPaymentData = null;
    let repairPaymentLoading = false;
    function openSalaryRecalculation(url, number) {
        const form = document.getElementById('salary-recalculate-form');
        form.action = url;
        form.querySelector('[name="reason"]').value = '';
        document.getElementById('salary-recalculate-number').textContent = number;
        Flux.modal('salary-recalculate-modal').show();
    }

    function updateSalaryPaymentChoice() {
        const choice = document.getElementById('salary-payment-choice').value;
        const input = document.getElementById('payment-amount');
        input.readOnly = choice !== 'partial';
        input.value = choice === 'unpaid' ? '0.00' : choice === 'full' ? input.max : '';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        updatePaymentRequiredFields();
    }

    function openPaymentModal(action, url, balance, sheetNumber, repairUrl) {
        const requestId = ++repairPaymentRequest;
        repairPaymentData = null;
        repairPaymentLoading = true;
        showSalarySaveAlert('payment-save-alert', '');
        const form = document.getElementById('payment-form');
        form.action = url;
        form.dataset.paymentAction = action;
        const amountInput = document.getElementById('payment-amount');
        amountInput.value = balance;
        amountInput.readOnly = true;
        const choice = document.getElementById('salary-payment-choice');
        choice.value = 'full';
        choice.querySelector('[value="unpaid"]').hidden = action !== 'finalize';
        choice.querySelector('[value="unpaid"]').disabled = action !== 'finalize';
        document.getElementById('payment-balance-before').textContent = salaryMoney(balance);
        form.querySelector('[data-outgoing-funding]').dispatchEvent(new CustomEvent('funding-reset'));
        amountInput.max = Math.max(0, Number(balance));
        
        const desc = document.getElementById('payment-modal-desc');
        const title = document.getElementById('payment-modal-title');
        const btn = document.getElementById('payment-submit-btn');
        
        if (action === 'finalize') {
            title.textContent = 'Finalize Paysheet ' + sheetNumber;
            desc.textContent = 'Choose full payment, partial payment or finalize only. For a payment, select its date and source.';
            btn.textContent = 'Finalize & Save';
            amountInput.required = false;
            amountInput.min = 0;
        } else {
            title.textContent = 'Pay Balance for ' + sheetNumber;
            desc.textContent = 'Choose full or partial payment, then select the payment date and source. Previous payments stay recorded.';
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
        fetch(repairUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(async response => {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Server returned an unexpected response. Please refresh and try again.');
            }
            const data = await response.json();
            if (!response.ok) {
                const message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Repair balances could not be loaded.';
                throw new Error(message);
            }
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
            else {
                amountInput.max = Number(data.balance_amount).toFixed(2);
                if (choice.value === 'full') amountInput.value = amountInput.max;
                document.getElementById('payment-balance-before').textContent = salaryMoney(data.balance_amount);
                amountInput.dispatchEvent(new Event('input', {bubbles: true}));
                updatePaymentRequiredFields();
            }
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
        amountInput.max = balance.toFixed(2);
        form.dataset.suggestedBalance = balance;
        if (document.getElementById('salary-payment-choice').value === 'full') amountInput.value = balance.toFixed(2);
        document.getElementById('payment-balance-before').textContent = salaryMoney(balance);
        amountInput.dispatchEvent(new Event('input', {bubbles: true}));
        document.getElementById('payment-repair-total').textContent = `Repair deduction applied Rs. ${Math.min(total, data.net_before_repairs).toFixed(2)} · Salary balance Rs. ${balance.toFixed(2)}${total > data.net_before_repairs ? ' · Unrecovered repair balance carries forward' : ''}`;
        updatePaymentRequiredFields();
    }

    function updatePaymentRequiredFields() {
        const amountInput = document.getElementById('payment-amount');
        const amount = parseFloat(amountInput.value) || 0;
        const isPay = amount > 0;
        const requireAmount = document.getElementById('salary-payment-choice').value === 'partial'
            || document.getElementById('payment-form').dataset.paymentAction === 'pay';
        amountInput.required = requireAmount;
        amountInput.min = requireAmount ? '0.01' : '0';
        document.getElementById('salary-payment-funding').disabled = !isPay;
        document.getElementById('payment-account').required = isPay;
        document.getElementById('payment-method-modal').required = isPay;
        document.getElementById('salary-payment-date').required = isPay;
        const remaining = Math.max(0, salaryCents(amountInput.max) - salaryCents(amountInput.value));
        document.getElementById('payment-balance-after').textContent = salaryMoney(remaining / 100);
        let invalidRepair = false;
        let repairTotal = 0;
        document.querySelectorAll('#payment-repair-rows input').forEach(input => {
            invalidRepair = Boolean(validateSalaryAmount(input)) || invalidRepair;
            repairTotal += salaryCents(input.value);
        });
        const exceedsSalary = false; // Excess requested repair recovery stays on its repair plan.
        const paymentError = validateSalaryAmount(amountInput);
        const message = invalidRepair ? 'Cannot save: a repair deduction is invalid or exceeds the repair balance. Correct the highlighted amount.'
            : exceedsSalary ? 'Cannot save: repair deductions exceed the salary available. Reduce the deduction amounts.'
            : paymentError ? `Cannot save payment. ${paymentError}` : '';
        document.getElementById('payment-submit-btn').disabled = repairPaymentLoading || !repairPaymentData || Boolean(message);
        showSalarySaveAlert('payment-save-alert', repairPaymentData ? message : '');
    }
  </script>

  <flux:modal name="salary-recalculate-modal" class="w-full max-w-xl">
      <form method="POST" id="salary-recalculate-form" class="space-y-4">
          @csrf<x-submission-token/>
          <h2 class="text-lg font-black">Recalculate <span id="salary-recalculate-number"></span></h2>
          <p class="text-sm text-slate-600">Update attendance, OT, full basic salary and contributions using current rules for this paysheet's saved period. Manual adjustments stay. Finalized sheets keep applied advances, repair deductions and old-balance recoveries. Payments and their dates stay recorded.</p>
          <p class="text-sm text-amber-800">The balance and payment status will update. Any salary overpayment is recorded as a balance for later recovery. This action does not pay or refund money.</p>
          <label class="block"><span class="label">Reason for recalculation *</span><textarea name="reason" class="field" required minlength="5" maxlength="500"></textarea></label>
          <div class="flex justify-end gap-2"><flux:modal.close><button type="button" class="btn-secondary">Cancel</button></flux:modal.close><button class="btn-primary">Recalculate Paysheet</button></div>
      </form>
  </flux:modal>

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

          <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm">
              Balance before payment: <strong id="payment-balance-before"></strong>
              <label class="mt-3 block"><span class="label">Payment option</span><select id="salary-payment-choice" class="w-full" onchange="updateSalaryPaymentChoice()"><option value="full">Pay full balance</option><option value="partial">Pay part of the balance</option><option value="unpaid">Finalize only — leave unpaid</option></select></label>
          </div>
          <div>
              <label class="label">Amount (Rs.) <span class="required-star">*</span></label>
              <input type="number" step="0.01" min="0" name="amount" id="payment-amount" class="w-full font-mono font-bold text-lg text-emerald-700" required>
          </div>
          
          <p class="text-sm">Balance after this payment: <strong id="payment-balance-after"></strong></p>
          <fieldset id="salary-payment-funding">
              <x-outgoing-funding default="main_cashier" account-id="payment-account" date-name="payment_date"/>
          </fieldset>

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
                  <input type="date" name="payment_date" id="salary-payment-date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="w-full" required>
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
