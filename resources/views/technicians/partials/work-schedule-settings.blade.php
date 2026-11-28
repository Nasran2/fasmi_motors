@php
    $systemSchedule = \App\Support\StaffWorkSchedule::resolve();
    $scheduleStaff = $technician ?? null;
    $scheduleMode = old('work_schedule_mode', $scheduleStaff?->work_schedule_mode ?? 'system_default');
    $startTime = old('work_start_time', substr($scheduleStaff?->work_start_time ?: $systemSchedule['start'], 0, 5));
    $endTime = old('work_end_time', substr($scheduleStaff?->work_end_time ?: $systemSchedule['end'], 0, 5));
@endphp
<section class="panel" x-data="{ mode: @js($scheduleMode) }">
    <h2 class="text-lg font-extrabold">Staff Working Times</h2>
    <p class="mt-2 text-sm text-slate-500">Late arrivals, early leave, OT eligibility and automatic checkout use these times. Late and early thresholds move with this shift. For the default 15-minute thresholds, a 9 AM–6 PM shift is late from 9:15 AM and early at or before 5:45 PM. OT requires arrival by 9 AM and starts from 6 PM.</p>
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
    <div class="mt-5 border-t border-slate-200 pt-4">
        <h3 class="font-bold">Individual Late / Early Rules</h3>
        <p class="mt-1 text-xs text-slate-500">Leave a field empty to follow the company rule and this staff member’s shift. A fee of 0 explicitly waives the occurrence fee. Each completed set still converts to a half day under the company count rule.</p>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <label><span class="label">Late from (optional)</span>
                <input type="time" name="late_cutoff_time" value="{{ old('late_cutoff_time', $scheduleStaff?->late_cutoff_time ? substr($scheduleStaff->late_cutoff_time, 0, 5) : '') }}" class="field">
            </label>
            <label><span class="label">Early leave at or before (optional)</span>
                <input type="time" name="early_leave_cutoff_time" value="{{ old('early_leave_cutoff_time', $scheduleStaff?->early_leave_cutoff_time ? substr($scheduleStaff->early_leave_cutoff_time, 0, 5) : '') }}" class="field">
            </label>
            <label><span class="label">Late fee per arrival (Rs., optional)</span>
                <input type="number" name="late_fee" min="0" step="0.01" value="{{ old('late_fee', $scheduleStaff?->late_fee) }}" placeholder="Company default: {{ $systemSchedule['late_fee'] }}" class="field">
            </label>
            <label><span class="label">Early leave fee per departure (Rs., optional)</span>
                <input type="number" name="early_leave_fee" min="0" step="0.01" value="{{ old('early_leave_fee', $scheduleStaff?->early_leave_fee) }}" placeholder="Company default: {{ $systemSchedule['early_fee'] }}" class="field">
            </label>
        </div>
    </div>
    <p class="mt-3 text-sm text-blue-800" x-show="mode === 'system_default'">System times: {{ \Carbon\Carbon::parse($systemSchedule['start'])->format('h:i A') }} to {{ \Carbon\Carbon::parse($systemSchedule['end'])->format('h:i A') }}. Future system time changes apply automatically.</p>
    <p class="mt-3 text-xs text-slate-500">Choose an out time later than the coming time on the same day. After changing times, recalculate existing attendance and refresh any unpaid draft paysheet to update its calculation.</p>
</section>
