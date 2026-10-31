@php
    $systemSchedule = \App\Support\StaffWorkSchedule::resolve();
    $scheduleStaff = $technician ?? null;
    $scheduleMode = old('work_schedule_mode', $scheduleStaff?->work_schedule_mode ?? 'system_default');
    $startTime = old('work_start_time', substr($scheduleStaff?->work_start_time ?: $systemSchedule['start'], 0, 5));
    $endTime = old('work_end_time', substr($scheduleStaff?->work_end_time ?: $systemSchedule['end'], 0, 5));
@endphp
<section class="panel" x-data="{ mode: @js($scheduleMode) }">
    <h2 class="text-lg font-extrabold">Staff Working Times</h2>
    <p class="mt-2 text-sm text-slate-500">Late arrivals, early leave, OT eligibility and automatic checkout use these times. Grace periods and OT rounding follow the system settings.</p>
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <label><span class="label">Working time rule *</span>
            <select name="work_schedule_mode" x-model="mode" required class="field">
                <option value="system_default" @selected($scheduleMode === 'system_default')>Use system default</option>
                <option value="custom" @selected($scheduleMode === 'custom')>Custom staff times</option>
            </select>
        </label>
        <label x-show="mode === 'custom'" x-cloak><span class="label">Coming time *</span>
            <input type="time" name="work_start_time" value="{{ $startTime }}" :disabled="mode !== 'custom'" :required="mode === 'custom'" @disabled($scheduleMode !== 'custom') class="field">
        </label>
        <label x-show="mode === 'custom'" x-cloak><span class="label">Closing / out time *</span>
            <input type="time" name="work_end_time" value="{{ $endTime }}" :disabled="mode !== 'custom'" :required="mode === 'custom'" @disabled($scheduleMode !== 'custom') class="field">
        </label>
    </div>
    <p class="mt-3 text-sm text-blue-800" x-show="mode === 'system_default'">System times: {{ \Carbon\Carbon::parse($systemSchedule['start'])->format('h:i A') }} to {{ \Carbon\Carbon::parse($systemSchedule['end'])->format('h:i A') }}. Future system time changes apply automatically.</p>
    <p class="mt-3 text-xs text-slate-500">Choose an out time later than the coming time on the same day. After changing times, recalculate existing attendance and refresh any unpaid draft paysheet to update its calculation.</p>
</section>
