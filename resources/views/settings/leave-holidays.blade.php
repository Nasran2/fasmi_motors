<x-layouts::app title="Leave & Holiday Settings">
    <div class="mx-auto max-w-7xl">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Settings Center</p>
            <h1 class="page-title">Leave & Holiday Settings</h1>
            <p class="page-subtitle">Manage weekly leaves and public holidays used by attendance and salary calculations.</p>
        </div>

        <div class="mt-8 space-y-8">
            <!-- Weekly Leave Days -->
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-5 flex items-center gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-slate-800">Weekly Leave Days</h2>
                        <p class="text-sm text-slate-500">Select the default weekly off days (e.g. Friday). These days will be marked as Leave without absent deductions.</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('system-settings.leave-holiday-settings.weekly') }}" class="mt-4">
                    @csrf
                    <div class="flex flex-wrap gap-4">
                        @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
                            <label class="relative flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-slate-100 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/50 has-[:checked]:ring-1 has-[:checked]:ring-blue-500">
                                <input type="checkbox" name="days[]" value="{{ $day }}" class="size-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ in_array($day, $weeklyLeaves) ? 'checked' : '' }}>
                                <span class="font-bold text-slate-700">{{ $day }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-5 text-right">
                        <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 font-bold text-white shadow-md shadow-blue-200 hover:bg-blue-700">Save Weekly Leaves</button>
                    </div>
                </form>
            </section>

            <!-- Holidays -->
            <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-purple-50 text-purple-600">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/></svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-slate-800">Company & Public Holidays</h2>
                                <p class="text-sm text-slate-500">Manage Sri Lankan Public, Poya, and Custom holidays.</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('system-settings.leave-holiday-settings.import-sl') }}">
                                @csrf
                                <input type="hidden" name="year" value="{{ date('Y') }}">
                                <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-50 px-5 font-bold text-blue-700 shadow-sm hover:bg-blue-100" onclick="return confirm('This will import Google Calendar public holidays for Sri Lanka for the current year. Proceed?');">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Import SL Holidays
                                </button>
                            </form>
                            <button type="button" onclick="document.getElementById('add-holiday-modal').showModal()" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 font-bold text-white shadow-md hover:bg-slate-800">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Holiday
                            </button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="border-b border-slate-200 bg-slate-50/50 text-xs font-bold uppercase text-slate-500">
                            <tr>
                                <th class="whitespace-nowrap px-6 py-4">Date</th>
                                <th class="whitespace-nowrap px-6 py-4">Holiday Name</th>
                                <th class="whitespace-nowrap px-6 py-4">Type</th>
                                <th class="whitespace-nowrap px-6 py-4">Leave Enabled</th>
                                <th class="whitespace-nowrap px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($holidays as $holiday)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="font-bold text-slate-800">{{ $holiday->holiday_date->format('d M Y') }}</div>
                                        <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $holiday->holiday_date->format('l') }}</div>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-slate-800">{{ $holiday->holiday_name }}</td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                            {{ $holiday->holiday_type }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <form method="POST" action="{{ route('system-settings.leave-holiday-settings.holidays.toggle', $holiday) }}">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full outline-none ring-slate-200 transition-colors focus:ring-4 {{ $holiday->is_leave_enabled ? 'bg-green-500' : 'bg-slate-300' }}">
                                                <span class="sr-only">Toggle Leave</span>
                                                <span class="absolute left-1 grid size-4 origin-left place-items-center rounded-full bg-white transition-all {{ $holiday->is_leave_enabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <form method="POST" action="{{ route('system-settings.leave-holiday-settings.holidays.destroy', $holiday) }}" onsubmit="return confirm('Are you sure you want to delete this holiday?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="inline-flex size-8 items-center justify-center rounded-lg text-red-500 hover:bg-red-50 hover:text-red-700" title="Delete">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center">
                                        <div class="mx-auto grid size-16 place-items-center rounded-full bg-slate-100 text-slate-400">
                                            <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/></svg>
                                        </div>
                                        <h3 class="mt-4 text-sm font-bold text-slate-800">No holidays added yet</h3>
                                        <p class="mt-1 text-xs text-slate-500">Add Sri Lankan holidays to avoid absent deductions on those days.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <dialog id="add-holiday-modal" class="w-full max-w-md rounded-3xl p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-sm">
        <form method="POST" action="{{ route('system-settings.leave-holiday-settings.holidays') }}">
            @csrf
            <div class="border-b border-slate-100 p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-slate-900">Add New Holiday</h3>
                    <button type="button" onclick="document.getElementById('add-holiday-modal').close()" class="grid size-8 place-items-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
                </div>
            </div>
            <div class="space-y-5 p-6">
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">Holiday Name <span class="text-red-500">*</span></label>
                    <input type="text" name="holiday_name" required placeholder="e.g. Vesak Full Moon Poya Day" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 outline-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">Date <span class="text-red-500">*</span></label>
                    <input type="date" name="holiday_date" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 outline-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10" onclick="this.showPicker()">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">Type <span class="text-red-500">*</span></label>
                    <select name="holiday_type" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 outline-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10">
                        <option value="Public">Public Holiday</option>
                        <option value="Poya">Poya Day</option>
                        <option value="Company">Company Leave</option>
                        <option value="Custom">Custom Holiday</option>
                    </select>
                </div>
                <div class="pt-2">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="is_leave_enabled" value="1" checked class="size-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-bold text-slate-700">Treat this day as a Leave Day</span>
                    </label>
                    <p class="pl-8 pt-1 text-xs text-slate-500">If enabled, attendance is not required and no absent deductions will apply.</p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50/50 p-6">
                <button type="button" onclick="document.getElementById('add-holiday-modal').close()" class="rounded-xl px-5 py-2.5 font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                <button type="submit" class="rounded-xl bg-slate-900 px-6 py-2.5 font-bold text-white hover:bg-slate-800">Add Holiday</button>
            </div>
        </form>
    </dialog>
</x-layouts::app>
