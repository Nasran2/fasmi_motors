<x-layouts::app title="Add System User">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Add User</h1>
        <p class="page-subtitle">Create a new system login account</p>
      </div>
      <a href="{{ route('user-management.users.index') }}" class="btn-secondary flex items-center gap-1.5">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Users
      </a>
    </div>

    {{-- Main Grid --}}
    <div class="grid gap-6 lg:grid-cols-3">
      
      {{-- Form Column --}}
      <div class="lg:col-span-2 space-y-6">
        <form method="POST" action="{{ route('user-management.users.store') }}" class="space-y-6">
          @csrf<x-submission-token/>

          {{-- 1. Basic Information --}}
          <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-black text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
              👤 Basic Information
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="label">Full Name <span class="required-star">*</span></label>
                <input type="text" name="name" value="{{ old('name') }}" required placeholder="Enter full name" class="w-full">
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
              <div>
                <label class="label">Username <span class="required-star">*</span></label>
                <input type="text" name="username" value="{{ old('username') }}" required placeholder="Enter unique username" class="w-full">
                @error('username') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
              <div>
                <label class="label">Email Address</label>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="staff@fasmirmotors.com" class="w-full">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
              <div>
                <label class="label">Phone Number</label>
                <input type="text" name="phone" value="{{ old('phone') }}" placeholder="e.g. +94 77 XXXXXXX" class="w-full">
                @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
            </div>
          </div>

          {{-- 2. Login Details --}}
          <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-black text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
              🔐 Login details
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="relative">
                <label class="label">Password <span class="required-star">*</span></label>
                <input type="password" name="password" id="passwordInput" required placeholder="Choose a secure password" class="w-full">
                <button type="button" onclick="togglePassword('passwordInput')" class="absolute right-3 top-9 text-slate-400 hover:text-slate-600">
                  👁️
                </button>
                @error('password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
              <div class="relative">
                <label class="label">Confirm Password <span class="required-star">*</span></label>
                <input type="password" name="password_confirmation" id="passwordConfirmInput" required placeholder="Re-enter password" class="w-full">
                <button type="button" onclick="togglePassword('passwordConfirmInput')" class="absolute right-3 top-9 text-slate-400 hover:text-slate-600">
                  👁️
                </button>
              </div>
              <div class="sm:col-span-2 space-y-3">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                  <input type="checkbox" name="force_password_change" value="1" @checked(old('force_password_change')) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                  <span class="text-sm font-bold text-slate-700">Force password change on first login</span>
                </label>
                <div class="block">
                  <label class="label">Account Status</label>
                  <div class="flex gap-4">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                      <input type="radio" name="active" value="1" checked class="text-red-600 focus:ring-red-500 border-slate-300">
                      <span class="text-sm font-bold text-slate-700">Active</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                      <input type="radio" name="active" value="0" class="text-red-600 focus:ring-red-500 border-slate-300">
                      <span class="text-sm font-bold text-slate-700">Inactive</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {{-- 3. Role & Access --}}
          <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-black text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
              🛡️ Role & Access Control
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="label">User Role <span class="required-star">*</span></label>
                <select name="role_id" required class="w-full">
                  <option value="">Select a Role</option>
                  @foreach($roles as $role)
                  <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                  @endforeach
                </select>
                @error('role_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
              </div>
              <div>
                <label class="label">Branch / Store Access</label>
                <select name="store_access" class="w-full">
                  <option value="All Stores" @selected(old('store_access') == 'All Stores')>All Stores</option>
                  <option value="Main Store" @selected(old('store_access') == 'Main Store')>Main Store</option>
                  <option value="Workshop Store" @selected(old('store_access') == 'Workshop Store')>Workshop Store</option>
                </select>
              </div>
            </div>
          </div>

          {{-- Form Buttons --}}
          <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save User</button>
            <a href="{{ route('user-management.users.index') }}" class="btn-secondary">Cancel</a>
          </div>

        </form>
      </div>

      {{-- Preview & Security Tips Column --}}
      <div class="space-y-6">
        
        {{-- Preview Panel --}}
        <div class="panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm text-center space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-slate-500">Live Preview</h3>
          <div class="mx-auto grid size-20 place-items-center rounded-full bg-[#06162f] text-2xl font-black text-white border-2 border-red-500 shadow-md">
            FM
          </div>
          <div>
            <h4 class="text-lg font-black text-slate-900 leading-tight">New Account</h4>
            <p class="text-xs text-slate-500 mt-1">Pending registration...</p>
          </div>
        </div>

        {{-- Security Tips --}}
        <div class="panel bg-[#06162f] text-white rounded-xl p-6 shadow-lg space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-red-400">🛡️ Security Best Practices</h3>
          <ul class="text-xs text-slate-300 space-y-2 list-disc list-inside leading-relaxed">
            <li>Ensure password has uppercase, lowercase, numbers, and symbols.</li>
            <li>Enable "Force password change" so users choose their own credentials privately.</li>
            <li>Assign the minimum role required for daily tasks. Do not share accounts.</li>
            <li>Inactive accounts immediately lock out the user, blocking access instantly.</li>
          </ul>
        </div>

      </div>

    </div>
  </div>

  <script>
    function togglePassword(inputId) {
      const input = document.getElementById(inputId);
      input.type = input.type === 'password' ? 'text' : 'password';
    }
  </script>
</x-layouts::app>
