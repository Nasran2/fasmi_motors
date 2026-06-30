<x-layouts::app title="Profit & Loss Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Profit & Loss Report',
      'subtitle' => 'View business income, expenses, gross profit, and net profit',
    ])

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 print:grid-cols-3">
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
        <p class="text-xs text-slate-500 uppercase tracking-wider font-extrabold mb-1">Total Income</p>
        <p class="text-2xl font-black text-green-600">Rs. {{ number_format($totalIncome, 2) }}</p>
      </div>
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
        <p class="text-xs text-slate-500 uppercase tracking-wider font-extrabold mb-1">Total Expenses</p>
        <p class="text-2xl font-black text-red-600">Rs. {{ number_format($totalExpenses, 2) }}</p>
      </div>
      <div class="panel bg-[#06162f] text-white rounded-xl p-4 shadow-lg text-center">
        <p class="text-xs text-slate-400 uppercase tracking-wider font-extrabold mb-1">Net Profit / Loss</p>
        <p class="text-2xl font-black {{ $netProfit >= 0 ? 'text-green-400' : 'text-red-400' }}">Rs. {{ number_format($netProfit, 2) }}</p>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2 print:grid-cols-2">
      
      {{-- INCOME --}}
      <div class="panel p-0 border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
        <div class="p-4 border-b border-slate-100 bg-slate-50">
          <h2 class="text-lg font-black text-slate-800">1. Income & Revenue</h2>
        </div>
        <div class="p-4 space-y-4">
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-slate-600">Service Income (Direct)</span>
            <span class="font-bold">Rs. {{ number_format($serviceIncome, 2) }}</span>
          </div>
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-slate-600">Spare Parts Sales</span>
            <span class="font-bold">Rs. {{ number_format($partsSales, 2) }}</span>
          </div>
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-slate-600">Less: Cost of Goods Sold</span>
            <span class="font-bold text-red-600">- Rs. {{ number_format($partsCost, 2) }}</span>
          </div>
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-green-700">Spare Parts Gross Profit</span>
            <span class="font-bold text-green-700">Rs. {{ number_format($partsProfit, 2) }}</span>
          </div>
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-slate-600">Other Income</span>
            <span class="font-bold">Rs. {{ number_format($otherIncome, 2) }}</span>
          </div>
          <div class="flex justify-between items-center pt-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
            <span class="text-sm font-black uppercase tracking-wider text-slate-800">Total Income</span>
            <span class="text-lg font-black text-green-600">Rs. {{ number_format($totalIncome, 2) }}</span>
          </div>
        </div>
      </div>

      {{-- EXPENSES --}}
      <div class="panel p-0 border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
        <div class="p-4 border-b border-slate-100 bg-slate-50">
          <h2 class="text-lg font-black text-slate-800">2. Operating Expenses</h2>
        </div>
        <div class="p-4 space-y-4">
          @forelse($expenseByCategory as $exp)
          <div class="flex justify-between items-center pb-2 border-b border-slate-100 border-dashed">
            <span class="text-sm font-bold text-slate-600">{{ $exp->category ?? 'Uncategorized' }}</span>
            <span class="font-bold text-red-600">Rs. {{ number_format($exp->total, 2) }}</span>
          </div>
          @empty
          <p class="text-sm text-slate-500 py-2">No expenses recorded for this period.</p>
          @endforelse
          
          <div class="flex justify-between items-center pt-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
            <span class="text-sm font-black uppercase tracking-wider text-slate-800">Total Expenses</span>
            <span class="text-lg font-black text-red-600">Rs. {{ number_format($totalExpenses, 2) }}</span>
          </div>
        </div>
      </div>

      {{-- DRAWINGS (Does not affect net profit calculation directly but shown for cash flow info) --}}
      <div class="panel p-0 border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none col-span-full">
        <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
          <h2 class="text-lg font-black text-slate-800">3. Owner Drawings</h2>
          <span class="font-bold text-red-600">Rs. {{ number_format($totalDrawings, 2) }}</span>
        </div>
      </div>

    </div>
  </div>
</x-layouts::app>
