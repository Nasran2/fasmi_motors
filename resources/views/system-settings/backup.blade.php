<x-layouts::app title="Backup Settings">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
        <h1 class="page-title">Backup Settings</h1>
        <p class="page-subtitle">Manage automated system backups, cloud storage directories, and restore points</p>
      </div>
      <a href="{{ route('system-settings.index') }}" class="btn-secondary flex items-center gap-1.5">
        ⚙️ Settings Dashboard
      </a>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed">
      ℹ️ <strong>System Backups:</strong> Protect your store data. It is highly recommended to enable automated daily database backups and configure off-site storage.
    </div>

    <x-flash />

    <div class="grid gap-6 lg:grid-cols-3">
      
      {{-- Left Columns: Forms --}}
      <div class="lg:col-span-2 space-y-6">
        
        <form method="POST" action="{{ route('system-settings.save') }}" class="space-y-6">
          @csrf
          <input type="hidden" name="group" value="backup">

          {{-- Section 1: Backup rules --}}
          <div class="panel bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-base font-black text-slate-800 border-b border-slate-100 pb-3">⚙️ Backup Schedule</h2>
            
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="label">Backup Frequency</label>
                <select name="backup_frequency" class="w-full">
                  <option value="Daily" @selected(($settings['backup_frequency'] ?? 'Daily') === 'Daily')>Daily (Recommended)</option>
                  <option value="Weekly" @selected(($settings['backup_frequency'] ?? '') === 'Weekly')>Weekly</option>
                  <option value="Monthly" @selected(($settings['backup_frequency'] ?? '') === 'Monthly')>Monthly</option>
                </select>
              </div>
              <div>
                <label class="label">Backup Storage Target</label>
                <select name="backup_storage" class="w-full">
                  <option value="Local Only" @selected(($settings['backup_storage'] ?? 'Local Only') === 'Local Only')>Local Server Storage</option>
                  <option value="Google Drive" @selected(($settings['backup_storage'] ?? '') === 'Google Drive')>Google Drive Cloud</option>
                  <option value="AWS S3" @selected(($settings['backup_storage'] ?? '') === 'AWS S3')>Amazon S3 Bucket</option>
                </select>
              </div>
              <div>
                <label class="label">Retain Backups For (Days)</label>
                <input type="number" name="backup_retention_days" value="{{ $settings['backup_retention_days'] ?? 30 }}" class="w-full">
              </div>
              <div class="space-y-2 pt-6">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="hidden" name="backup_auto" value="0">
                  <input type="checkbox" name="backup_auto" value="1" @checked(($settings['backup_auto'] ?? 1) == 1) class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                  <span class="text-xs font-bold text-slate-700">Enable automatic background backups</span>
                </label>
              </div>
            </div>
          </div>

          {{-- Save Button --}}
          <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary shadow-lg shadow-red-200">Save Backup Settings</button>
            <a href="{{ route('system-settings.index') }}" class="btn-secondary">Cancel</a>
          </div>

        </form>

        {{-- Section 2: Backup History Table --}}
        <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white">
          <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
            <h3 class="text-sm font-black text-slate-800">Saved Backup Points</h3>
            <form method="POST" action="{{ route('system-settings.create-backup') }}">
              @csrf
              <button type="submit" class="btn-secondary !min-h-8 !py-1 !px-2.5 text-xs text-red-600 font-black">Create Backup Now</button>
            </form>
          </div>
          
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                <tr>
                  <th class="px-4 py-3 font-bold">Backup Date</th>
                  <th class="px-4 py-3 font-bold">Type</th>
                  <th class="px-4 py-3 font-bold">File Size</th>
                  <th class="px-4 py-3 font-bold">Status</th>
                  <th class="px-4 py-3 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach($backups as $row)
                <tr class="hover:bg-slate-50/50 transition">
                  <td class="px-4 py-3 text-xs font-mono text-slate-700">{{ $row['date'] }}</td>
                  <td class="px-4 py-3 text-xs text-slate-600">{{ $row['type'] }}</td>
                  <td class="px-4 py-3 text-xs text-slate-600">{{ $row['size'] }}</td>
                  <td class="px-4 py-3"><span class="badge-sent">{{ $row['status'] }}</span></td>
                  <td class="px-4 py-3 text-right">
                    <div class="inline-flex gap-1">
                      <button onclick="openRestoreModal('{{ $row['id'] }}')" class="btn-secondary !py-0.5 !px-2 text-xs text-blue-600">Restore</button>
                      <form method="POST" action="{{ route('system-settings.delete-backup') }}" class="inline">
                        @csrf
                        <button type="submit" class="btn-secondary !py-0.5 !px-2 text-xs text-red-600">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>

      </div>

      {{-- Right Columns: Recovery Warning --}}
      <div>
        <div class="panel bg-slate-900 text-white rounded-xl p-6 space-y-4">
          <h3 class="text-sm font-extrabold uppercase tracking-wider text-red-400">⚠️ Recovery Guidelines</h3>
          <p class="text-xs text-slate-300 leading-relaxed">Restoring past backups completely overwrites active ledgers, billing history, and stock records. Ensure you run a manual backup of the current database before triggering any restore points.</p>
        </div>
      </div>

    </div>

    {{-- Restore Confirmation Modal --}}
    <dialog id="restoreModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-md">
      <h3 class="text-lg font-black text-slate-800 mb-2">⚠️ Confirm Database Restore</h3>
      <p class="text-xs text-slate-500 mb-4">You are about to restore the database. This action is irreversible.</p>
      
      <form method="POST" action="{{ route('system-settings.restore-backup') }}" class="space-y-4">
        @csrf
        <div>
          <label class="label">Admin Password <span class="required-star">*</span></label>
          <input type="password" name="password" required class="w-full">
        </div>
        <div>
          <label class="label">Reason for Restore <span class="required-star">*</span></label>
          <input type="text" name="reason" required placeholder="e.g. Server corruption recovery" class="w-full">
        </div>
        <div>
          <label class="inline-flex items-center gap-2 cursor-pointer select-none">
            <input type="checkbox" required class="rounded border-slate-300 text-red-600 focus:ring-red-500">
            <span class="text-xs font-bold text-slate-700 text-red-600">I confirm that I want to overwrite active data</span>
          </label>
        </div>
        
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeRestoreModal()" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary !bg-red-600 hover:bg-red-700">Restore Now</button>
        </div>
      </form>
    </dialog>

  </div>

  <script>
    function openRestoreModal(id) {
      document.getElementById('restoreModal').showModal();
    }
    function closeRestoreModal() {
      document.getElementById('restoreModal').close();
    }
  </script>
</x-layouts::app>
