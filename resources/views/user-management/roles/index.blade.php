<x-layouts::app title="Role Management">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Roles</h1>
        <p class="page-subtitle">Manage user roles and access levels</p>
      </div>
      <button onclick="openRoleModal()" class="btn-primary flex items-center gap-1.5 shadow-lg shadow-red-200">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Add Role
      </button>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Role-Based Access:</strong> Roles control what users can see and do inside the system. You can create custom roles, but default system roles cannot be deleted.
    </div>

    <x-flash />

    {{-- Summary Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      @foreach($stats as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black text-slate-800">
          {{ number_format($value) }}
        </p>
      </div>
      @endforeach
    </div>

    {{-- Roles Table --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Role Name</th>
              <th class="px-4 py-3 font-bold">Description</th>
              <th class="px-4 py-3 font-bold">Assigned Users</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->name }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs max-w-md truncate">{{ $row->description ?: 'No description provided.' }}</td>
              <td class="px-4 py-3 text-slate-700">
                <span class="font-bold text-slate-900">{{ $row->users_count }}</span> users
              </td>
              <td class="px-4 py-3">
                <span class="badge-sent">Active</span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center gap-1.5">
                  <a href="{{ route('user-management.permissions.index', ['role_id' => $row->id]) }}" class="btn-secondary !py-1 !px-2.5 text-xs text-red-600">Permissions</a>
                  
                  <button onclick="openRoleModal('{{ $row->id }}', '{{ $row->name }}', '{{ $row->description }}')" class="btn-secondary !py-1 !px-2.5 text-xs" @disabled($row->name === 'Super Admin')>Edit</button>

                  <form method="POST" action="{{ route('user-management.roles.destroy', $row->id) }}" class="inline" data-confirm="Are you sure you want to delete this role?">
                    @csrf<x-submission-token/> @method('DELETE')
                    <button type="submit" class="btn-secondary !py-1 !px-2.5 text-xs text-red-600 hover:bg-red-50" @disabled($row->users_count > 0 || in_array($row->name, ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Technician', 'Accountant']))>
                      Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    {{-- Add/Edit Role Modal --}}
    <dialog id="roleModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-md">
      <h3 id="modalTitle" class="text-lg font-black text-slate-800 mb-4">🛡️ Add System Role</h3>
      
      <form id="roleForm" method="POST" action="{{ route('user-management.roles.store') }}" class="space-y-4">
        @csrf<x-submission-token/>
        <input type="hidden" name="_method" id="formMethod" value="POST">
        <div>
          <label class="label">Role Name <span class="required-star">*</span></label>
          <input type="text" name="name" id="roleNameInput" required placeholder="e.g. Sales Assistant" class="w-full">
        </div>
        <div>
          <label class="label">Description</label>
          <textarea name="description" id="roleDescInput" rows="3" placeholder="Brief explanation of what this role accesses..." class="w-full"></textarea>
        </div>
        
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeRoleModal()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary">Save Role</button>
        </div>
      </form>
    </dialog>

  </div>

  <script>
    function openRoleModal(id = '', name = '', desc = '') {
      const modal = document.getElementById('roleModal');
      const title = document.getElementById('modalTitle');
      const form = document.getElementById('roleForm');
      const method = document.getElementById('formMethod');
      const nameInput = document.getElementById('roleNameInput');
      const descInput = document.getElementById('roleDescInput');

      if (id) {
        title.textContent = '🛡️ Edit System Role';
        form.action = `/user-management/roles/${id}`;
        method.value = 'PUT';
        nameInput.value = name;
        descInput.value = desc;
      } else {
        title.textContent = '🛡️ Add System Role';
        form.action = "{{ route('user-management.roles.store') }}";
        method.value = 'POST';
        nameInput.value = '';
        descInput.value = '';
      }

      modal.showModal();
    }

    function closeRoleModal() {
      document.getElementById('roleModal').close();
    }
  </script>
</x-layouts::app>
