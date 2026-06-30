<x-layouts::app title="Permissions Matrix">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Permissions</h1>
        <p class="page-subtitle">Control page access and actions for each role</p>
      </div>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Access Control:</strong> Select a role from the left panel and configure their accessibility settings on the right panel. Remember, the <strong>Super Admin</strong> role automatically has full bypass permission.
    </div>

    <x-flash />

    {{-- Main Grid Layout --}}
    <div class="grid gap-6 lg:grid-cols-4">
      
      {{-- Left Panel: Role Selector --}}
      <div class="space-y-4">
        <h2 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Select Role</h2>
        <div class="flex flex-col gap-1.5 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
          @foreach($roles as $role)
          <a href="{{ route('user-management.permissions.index', ['role_id' => $role->id]) }}" 
             class="flex items-center justify-between rounded-lg px-4 py-2.5 text-sm font-bold transition
                    {{ $selectedRole->id === $role->id ? 'bg-[#06162f] text-white' : 'text-slate-700 hover:bg-slate-50' }}">
            <span>{{ $role->name }}</span>
            @if($role->name === 'Super Admin')
            <span class="text-[9px] uppercase tracking-wider bg-red-600 text-white px-1.5 py-0.5 rounded font-black">All Access</span>
            @endif
          </a>
          @endforeach
        </div>
      </div>

      {{-- Right Panel: Permission Matrix --}}
      <div class="lg:col-span-3 space-y-6">
        
        <form method="POST" action="{{ route('user-management.permissions.update') }}" class="space-y-6">
          @csrf
          <input type="hidden" name="role_id" value="{{ $selectedRole->id }}">

          <div class="flex flex-wrap items-center justify-between gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
            <div>
              <p class="text-xs text-slate-500 font-bold">Currently Editing Permissions for</p>
              <h3 class="text-lg font-black text-[#06162f]">{{ $selectedRole->name }}</h3>
            </div>
            <div class="flex gap-2">
              <button type="button" onclick="applyPreset('all')" class="btn-secondary !py-1.5 !px-3 text-xs" @disabled($selectedRole->name === 'Super Admin')>Select All Permissions</button>
              <button type="button" onclick="applyPreset('none')" class="btn-secondary !py-1.5 !px-3 text-xs" @disabled($selectedRole->name === 'Super Admin')>Clear All</button>
            </div>
          </div>

          @if($selectedRole->name === 'Super Admin')
          <div class="panel bg-slate-900 text-white rounded-xl p-8 text-center space-y-3">
            <span class="text-4xl">👑</span>
            <h3 class="text-xl font-black">Super Admin bypass active</h3>
            <p class="text-sm text-slate-400 max-w-md mx-auto leading-relaxed">This role possesses complete access to all pages, features, ledgers, settings, and database configurations. Permissions cannot be restricted.</p>
          </div>
          @else
          {{-- Group accordions --}}
          <div class="space-y-4">
            @foreach($permissionGroups as $module => $perms)
            <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden p-0">
              
              {{-- Header --}}
              <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-5 py-3.5">
                <h4 class="text-sm font-black text-slate-800 flex items-center gap-1.5">
                  📁 {{ $module }}
                </h4>
                <div class="flex items-center gap-2">
                  <button type="button" onclick="toggleModuleCheckboxes('{{ Str::slug($module) }}', true)" class="text-[10px] font-bold text-red-600 hover:underline">Select All</button>
                  <span class="text-slate-300">|</span>
                  <button type="button" onclick="toggleModuleCheckboxes('{{ Str::slug($module) }}', false)" class="text-[10px] font-bold text-slate-400 hover:underline">Clear</button>
                </div>
              </div>

              {{-- Checkbox grid --}}
              <div class="p-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" id="module-{{ Str::slug($module) }}">
                @foreach($perms as $key => $label)
                <label class="flex items-start gap-2.5 rounded-lg border border-slate-100 p-2.5 hover:bg-slate-50 transition cursor-pointer select-none">
                  <input type="checkbox" name="permissions[]" value="{{ $key }}" 
                         @checked(in_array($key, $selectedRole->permissions ?? []))
                         class="mt-0.5 rounded border-slate-300 text-red-600 focus:ring-red-500 size-4">
                  <span class="text-xs font-bold text-slate-700 leading-normal">{{ $label }}</span>
                </label>
                @endforeach
              </div>

            </div>
            @endforeach
          </div>

          {{-- Save Button --}}
          <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Permissions</button>
            <a href="{{ route('user-management.permissions.index', ['role_id' => $selectedRole->id]) }}" class="btn-secondary">Reset Changes</a>
          </div>
          @endif

        </form>

      </div>

    </div>
  </div>

  <script>
    function toggleModuleCheckboxes(moduleSlug, state) {
      const container = document.getElementById('module-' + moduleSlug);
      if (!container) return;
      const checkboxes = container.querySelectorAll('input[type="checkbox"]');
      checkboxes.forEach(cb => cb.checked = state);
    }

    function applyPreset(preset) {
      const checkboxes = document.querySelectorAll('input[name="permissions[]"]');
      checkboxes.forEach(cb => {
        cb.checked = (preset === 'all');
      });
    }
  </script>
</x-layouts::app>
