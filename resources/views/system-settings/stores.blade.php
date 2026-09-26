<x-layouts::app title="Branch / Store Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Branch & Store Settings</h1>
        <p class="page-subtitle">Manage branches, stores, and default stock locations</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Store Allocation:</strong> Use this page to manage stock locations. If only one store is enabled, "Main Store" will be selected automatically everywhere.
    </div>

    <x-flash />

    <div class="grid gap-6 lg:grid-cols-3">
      
      {{-- Left Columns: Store Control & Table --}}
      <div class="lg:col-span-2 space-y-6">
        
        {{-- Store Mode Settings --}}
        <form method="POST" action="{{ route('system-settings.save') }}" class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
          @csrf<x-submission-token/>
          <input type="hidden" name="group" value="stock">
          <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Store Options</h2>
          
          <div class="space-y-3">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="multiple_stores" value="0">
              <input type="checkbox" name="multiple_stores" value="1" @checked(($settings['multiple_stores'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Enable Multiple Stores / Branches</span>
            </label>
            
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="always_use_main_store" value="0">
              <input type="checkbox" name="always_use_main_store" value="1" @checked(($settings['always_use_main_store'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Always select default store automatically</span>
            </label>
            
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="ask_store_purchases" value="0">
              <input type="checkbox" name="ask_store_purchases" value="1" @checked(($settings['ask_store_purchases'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Prompt for store during Purchases</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input type="hidden" name="ask_store_pos" value="0">
              <input type="checkbox" name="ask_store_pos" value="1" @checked(($settings['ask_store_pos'] ?? 0) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
              <span class="text-xs font-bold text-slate-700">Prompt for store during POS Sales</span>
            </label>
          </div>

          <div class="pt-2">
            <button type="submit" class="btn-primary !min-h-9 !py-1 text-xs">Save Options</button>
          </div>
        </form>

        {{-- Store List Table --}}
        <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
          <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/50">
            <h3 class="text-sm font-black text-slate-800">Branch & Store Locations</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                <tr>
                  <th class="px-4 py-3 font-bold">Store Name</th>
                  <th class="px-4 py-3 font-bold">Default</th>
                  <th class="px-4 py-3 font-bold">Status</th>
                  <th class="px-4 py-3 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach($stores as $row)
                <tr class="hover:bg-slate-50/50 transition">
                  <td class="px-4 py-3 font-bold text-slate-900">{{ $row->name }}</td>
                  <td class="px-4 py-3">
                    @if($row->is_default)
                    <span class="text-[9px] font-black bg-blue-100 text-blue-700 border border-blue-200 px-2 py-0.5 rounded uppercase">Default</span>
                    @else
                    <span class="text-slate-400 text-xs">—</span>
                    @endif
                  </td>
                  <td class="px-4 py-3">
                    @if($row->active)
                    <span class="badge-sent">Active</span>
                    @else
                    <span class="badge-failed">Inactive</span>
                    @endif
                  </td>
                  <td class="px-4 py-3 text-right">
                    <button onclick="editStore('{{ $row->id }}', '{{ $row->name }}', '{{ $row->is_default }}', '{{ $row->active }}')" class="btn-secondary !py-0.5 !px-2.5 text-xs">Edit</button>
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>

      </div>

      {{-- Right Columns: Add/Edit Form --}}
      <div>
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
          <h3 id="formTitle" class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Add Store Branch</h3>
          
          <form id="storeForm" method="POST" action="{{ route('system-settings.stores.store') }}" class="space-y-4">
            @csrf<x-submission-token/>
            <input type="hidden" name="_method" id="formMethod" value="POST">
            
            <div>
              <label class="label">Store / Branch Name <span class="required-star">*</span></label>
              <input type="text" name="name" id="storeNameInput" required placeholder="e.g. Workshop Store" class="w-full">
            </div>

            <div>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" id="storeDefaultInput" value="1" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Set as default store</span>
              </label>
            </div>

            <div>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" id="storeActiveInput" value="1" checked class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                <span class="text-xs font-bold text-slate-700">Store location active</span>
              </label>
            </div>

            <div class="flex gap-2 pt-2">
              <button type="submit" class="btn-primary flex-1">Save Store</button>
              <button type="button" onclick="cancelEdit()" id="cancelBtn" class="btn-secondary hidden">Cancel</button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>

  <script>
    function editStore(id, name, isDefault, active) {
      document.getElementById('formTitle').textContent = 'Edit Store Branch';
      document.getElementById('storeForm').action = `/system-settings/stores/${id}`;
      document.getElementById('formMethod').value = 'PUT';
      
      document.getElementById('storeNameInput').value = name;
      document.getElementById('storeDefaultInput').checked = isDefault == '1';
      document.getElementById('storeActiveInput').checked = active == '1';
      
      document.getElementById('cancelBtn').classList.remove('hidden');
    }

    function cancelEdit() {
      document.getElementById('formTitle').textContent = 'Add Store Branch';
      document.getElementById('storeForm').action = "{{ route('system-settings.stores.store') }}";
      document.getElementById('formMethod').value = 'POST';
      
      document.getElementById('storeNameInput').value = '';
      document.getElementById('storeDefaultInput').checked = false;
      document.getElementById('storeActiveInput').checked = true;
      
      document.getElementById('cancelBtn').classList.add('hidden');
    }
  </script>
</x-layouts::app>
