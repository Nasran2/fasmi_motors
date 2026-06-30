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
    <form id="salary-form" method="POST" action="{{ route('technicians.salaries.store') }}" class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
      @csrf
      <h2 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Generate Salary Sheet</h2>
      <p class="text-xs text-slate-400">Attendance, OT slabs, commission, advances, own-bike repair installments and EPF/ETF are computed automatically.</p>
      
      <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label">Technician <span class="required-star">*</span></label>
          <select name="technician_id" class="w-full" required>
            <option value="">Select technician</option>
            @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(request('technician') == $t->id)>{{ $t->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">Salary Month <span class="required-star">*</span></label>
          <input type="month" name="month" value="{{ now()->format('Y-m') }}" required class="w-full">
        </div>

        <div>
          <label class="label">Other Deductions (Rs.)</label>
          <input type="number" step="0.01" min="0" name="other_deductions" value="0" class="w-full">
        </div>

        <div>
          <label class="label">Paid Amount (Rs.)</label>
          <input type="number" step="0.01" min="0" name="paid_amount" value="0" class="w-full">
        </div>

        <div>
          <label class="label">Paid From Account</label>
          <select name="account_id" class="w-full">
            <option value="">Select when paying</option>
            @foreach($accounts as $acc)
            <option value="{{ $acc->id }}">{{ $acc->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="label">Payment Method</label>
          <select name="payment_method" class="w-full">
            @foreach(['Cash','Bank Transfer','Cheque','Card'] as $method)
            <option>{{ $method }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="mt-4 text-right">
        <button class="btn-primary">Calculate & Generate</button>
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
              <td class="px-4 py-3 font-mono">{{ $sheet->period_start->format('F Y') }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->gross_salary, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->advances, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($sheet->deductions, 2) }}</td>
              <td class="px-4 py-3 text-slate-500">Rs. {{ number_format($sheet->epf_employee, 2) }}</td>
              <td class="px-4 py-3 font-black text-slate-900">Rs. {{ number_format($sheet->net_payable, 2) }}</td>
              <td class="px-4 py-3 text-emerald-600 font-bold">Rs. {{ number_format($sheet->paid_amount, 2) }}</td>
              <td class="px-4 py-3 text-red-600 font-bold">Rs. {{ number_format($sheet->balance_amount, 2) }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider {{ $sheet->status === 'Paid' ? 'badge-sent' : 'badge-pending' }}">
                  {{ $sheet->status }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex gap-1">
                  <button type="button" onclick="viewBreakdown('{{ $sheet->number }}', '{{ $sheet->technician->name }}', '{{ $sheet->period_start->format('F Y') }}', '{{ $sheet->basic_salary }}', '{{ $sheet->ot_amount }}', '{{ $sheet->commission }}', '{{ $sheet->advances }}', '{{ $sheet->late_deduction }}', '{{ $sheet->early_leave_deduction }}', '{{ $sheet->half_day_deduction }}', '{{ $sheet->no_pay_deduction }}', '{{ $sheet->own_bike_deduction }}', '{{ $sheet->epf_employee }}', '{{ $sheet->other_deductions }}', '{{ $sheet->net_payable }}', '{{ $sheet->paid_amount }}', '{{ $sheet->balance_amount }}', '{{ $sheet->payment_method }}', '{{ $sheet->created_at->format('d M Y') }}')" class="btn-secondary !px-2.5 !py-1 text-xs">
                    🔎 View Details
                  </button>
                  <button type="button" onclick="window.print()" class="btn-secondary !px-2.5 !py-1 text-xs">
                    🖨️ Print
                  </button>
                </div>
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="12" class="px-4 py-10 text-center text-slate-400">
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
  </script>
</x-layouts::app>
