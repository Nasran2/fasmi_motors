<x-layouts::app title="Users Management">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Manage system users and access levels</p>
      </div>
      <a href="{{ route('user-management.users.create') }}" class="btn-primary flex items-center gap-1.5 shadow-lg shadow-red-200">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Add User
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>Manage Accounts:</strong> This page shows all staff accounts that can log in to the system. You can activate, deactivate, edit roles, or reset passwords.
    </div>

    <x-flash />

    {{-- Summary Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
      @foreach($stats as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black {{ str_contains($label, 'inactive') ? 'text-red-600' : (str_contains($label, 'active') || str_contains($label, 'online') ? 'text-green-600' : 'text-slate-800') }}">
          {{ number_format($value) }}
        </p>
      </div>
      @endforeach
    </div>

    {{-- Filters Panel --}}
    <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-4">
      <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
        <div class="lg:col-span-2">
          <label class="label">Search User</label>
          <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name, username, email, phone..." class="w-full">
        </div>
        <div>
          <label class="label">Filter Role</label>
          <select name="role_id" class="w-full">
            <option value="">All Roles</option>
            @foreach($roles as $role)
            <option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ $role->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="label">Status</label>
          <select name="status" class="w-full">
            <option value="">All Statuses</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            <option value="locked" @selected(request('status') === 'locked')>Locked</option>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary flex-1">Apply</button>
          <a href="{{ route('user-management.users.index') }}" class="btn-secondary">Reset</a>
        </div>
      </form>
      
      {{-- Quick Filter Tags --}}
      <div class="flex flex-wrap gap-2 mt-4 pt-3 border-t border-slate-100">
        <a href="{{ request()->fullUrlWithQuery(['quick' => '', 'page' => null]) }}" class="px-3 py-1 text-xs font-bold rounded-lg border {{ !request('quick') ? 'bg-[#06162f] text-white border-[#06162f]' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100' }}">All</a>
        <a href="{{ request()->fullUrlWithQuery(['quick' => 'online', 'page' => null]) }}" class="px-3 py-1 text-xs font-bold rounded-lg border {{ request('quick') === 'online' ? 'bg-green-600 text-white border-green-600' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100' }}">Online Today</a>
        <a href="{{ request()->fullUrlWithQuery(['quick' => 'never', 'page' => null]) }}" class="px-3 py-1 text-xs font-bold rounded-lg border {{ request('quick') === 'never' ? 'bg-[#06162f] text-white border-[#06162f]' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100' }}">Never Logged In</a>
      </div>
    </div>

    {{-- Users Table --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">User</th>
              <th class="px-4 py-3 font-bold">Username</th>
              <th class="px-4 py-3 font-bold">Email</th>
              <th class="px-4 py-3 font-bold">Phone</th>
              <th class="px-4 py-3 font-bold">Role</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold">Last Login</th>
              <th class="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 flex items-center gap-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-[#06162f] text-xs font-black text-white border border-slate-700">
                  {{ $row->initials() }}
                </span>
                <div>
                  <p class="font-bold text-slate-900 leading-tight">{{ $row->name }}</p>
                  <p class="text-[10px] text-slate-500">{{ $row->role?->name ?? 'No Role' }}</p>
                </div>
              </td>
              <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $row->username }}</td>
              <td class="px-4 py-3 text-slate-600">{{ $row->email ?: '—' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ $row->phone ?: '—' }}</td>
              <td class="px-4 py-3">
                <span class="text-xs font-bold text-slate-800 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                  {{ $row->role?->name ?? 'No Role' }}
                </span>
              </td>
              <td class="px-4 py-3">
                @if($row->locked_until && $row->locked_until->isFuture())
                  <span class="badge-pending">Locked</span>
                @elseif($row->active)
                  <span class="badge-sent">Active</span>
                @else
                  <span class="badge-failed">Inactive</span>
                @endif

                @if($row->last_login_at && $row->last_login_at->diffInMinutes(now()) < 15)
                  <span class="inline-block size-2 rounded-full bg-blue-500 animate-pulse ml-1.5" title="Online now"></span>
                @endif
              </td>
              <td class="px-4 py-3 text-slate-500 text-xs">
                {{ $row->last_login_at ? $row->last_login_at->format('d M Y h:i A') : 'Never logged in' }}
              </td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center gap-1.5">
                  <a href="{{ route('user-management.users.edit', $row->id) }}" class="btn-secondary !py-1 !px-2.5 text-xs">Edit</a>
                  
                  <form method="POST" action="{{ route('user-management.users.toggle-status', $row->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-secondary !py-1 !px-2.5 text-xs {{ $row->active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}">
                      {{ $row->active ? 'Deactivate' : 'Activate' }}
                    </button>
                  </form>

                  <button onclick="openPasswordModal('{{ $row->id }}', '{{ $row->username }}')" class="btn-secondary !py-1 !px-2.5 text-xs text-blue-600">Password</button>

                  <form method="POST" action="{{ route('user-management.users.destroy', $row->id) }}" class="inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn-secondary !py-1 !px-2.5 text-xs text-red-600 hover:bg-red-50" @disabled($row->id === auth()->id() || $row->role?->name === 'Super Admin')>
                      Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="100%" class="px-4 py-8 text-center text-slate-500">
                <p class="text-base font-bold text-slate-700">No user accounts found.</p>
                <p class="text-xs mt-1">Try changing the filters or create a new user account.</p>
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if(method_exists($rows, 'links'))
      <div class="p-4 border-t border-slate-100 bg-slate-50/50">
        {{ $rows->links() }}
      </div>
      @endif
    </div>

    {{-- Reset Password Modal --}}
    <dialog id="passwordModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-md">
      <h3 class="text-lg font-black text-slate-800 mb-2">🔑 Reset User Password</h3>
      <p class="text-xs text-slate-500 mb-4">Reset password for <strong id="modalUsername"></strong>. The user will be required to change password on next login.</p>
      
      <form id="passwordForm" method="POST" action="" class="space-y-4">
        @csrf
        <div>
          <label class="label">New Password <span class="required-star">*</span></label>
          <input type="password" name="password" id="modalPasswordInput" required placeholder="Enter new password" class="w-full">
        </div>
        <div>
          <label class="label">Confirm Password <span class="required-star">*</span></label>
          <input type="password" name="password_confirmation" required placeholder="Confirm new password" class="w-full">
        </div>
        
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closePasswordModal()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary">Reset Password</button>
        </div>
      </form>
    </dialog>

  </div>

  <script>
    function openPasswordModal(userId, username) {
      const modal = document.getElementById('passwordModal');
      const usernameSpan = document.getElementById('modalUsername');
      const form = document.getElementById('passwordForm');
      
      usernameSpan.textContent = username;
      form.action = `/user-management/users/${userId}/reset-password`;
      modal.showModal();
    }

    function closePasswordModal() {
      document.getElementById('passwordModal').close();
    }
  </script>
</x-layouts::app>
