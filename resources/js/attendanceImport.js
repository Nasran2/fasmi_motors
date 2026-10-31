const weekDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function attendanceImportState(date, checkIn, checkOut, calendar, explicitLeave = null, explicitWorked = null) {
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T00:00:00Z`) : null;
    const valid = parsed && !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date;
    const special = calendar.special_closed[date];
    const weekly = valid && calendar.weekly_closed.includes(weekDays[parsed.getUTCDay()]);
    const closed = Boolean(special || weekly);
    const hasPunch = Boolean(checkIn || checkOut);
    const future = date > calendar.today;
    const worked = Boolean(valid && !future && (explicitWorked === null ? closed && hasPunch : explicitWorked));
    const checked = Boolean(valid && !closed && !hasPunch && !worked && (explicitLeave === null ? !future : explicitLeave));
    const absence = valid && !closed && !hasPunch && !future && !checked && !worked;
    const colour = worked && closed ? 'bg-emerald-50' : special ? 'bg-violet-50' : weekly ? 'bg-sky-50' : checked ? 'bg-amber-50' : absence ? 'bg-rose-50' : 'bg-white';
    const note = !valid ? 'Choose a valid date' : worked && closed ? 'Worked on shop-closed day · extra daily pay'
        : worked ? 'Worked · missing punches filled from staff schedule'
        : closed ? 'Shop closed · not worked, no extra daily pay'
        : hasPunch ? 'Attendance from punches' : checked ? 'Paid leave · no absence deduction' : future ? 'Future empty day · skipped' : 'Absent · normal deduction';
    return {
        colour, note, worked,
        workedDisabled: !valid || future,
        kind: special ? 'special' : (weekly ? 'weekly' : 'working'),
        closed,
        label: special ? special.name : (weekly ? 'Weekly shop closed' : 'Working day'),
        future,
        disabled: closed || hasPunch || worked || !valid,
        checked,
    };
}

function initAttendanceImport() {
    const calendarElement = document.getElementById('attendance-shop-calendar');
    if (!calendarElement) return;
    const calendar = JSON.parse(calendarElement.textContent);
    const schedulesElement = document.getElementById('attendance-staff-schedules');
    const schedules = schedulesElement ? JSON.parse(schedulesElement.textContent) : null;
    for (const row of document.querySelectorAll('[data-import-row]')) {
        const date = row.querySelector('[data-import-date]');
        const checkIn = row.querySelector('[data-import-in]');
        const checkOut = row.querySelector('[data-import-out]');
        const leave = row.querySelector('[data-import-leave]');
        const worked = row.querySelector('[data-import-worked]');
        const staff = row.querySelector('[data-import-technician]');
        const autoCheckout = row.querySelector('[data-auto-checkout]');
        const scheduledIn = row.querySelector('[data-schedule-in]');
        const autoNote = row.querySelector('[data-auto-checkout-note]');
        const scheduleLabel = row.querySelector('[data-schedule-label]');
        const label = row.querySelector('[data-shop-day-label]');
        const note = row.querySelector('[data-leave-note]');
        let leaveChoice = leave.checked;
        let workedChoice = worked?.checked ?? null;
        const schedule = () => schedules?.staff?.[staff?.value] ?? schedules?.default;
        const fillTimes = () => {
            const times = schedule();
            if (!times) return;
            if (!checkIn.value || scheduledIn?.value === '1') {
                checkIn.value = times.start.slice(0, 5);
                if (scheduledIn) scheduledIn.value = '1';
            }
            if (!checkOut.value || autoCheckout?.value === '1') {
                checkOut.value = times.end.slice(0, 5);
                if (autoCheckout) autoCheckout.value = '1';
            }
        };
        const update = () => {
            const state = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar, leaveChoice, workedChoice);
            leave.checked = state.checked;
            leave.disabled = state.disabled;
            if (worked) {
                worked.checked = state.worked;
                worked.disabled = state.workedDisabled;
            }
            label.textContent = `${state.label}${state.future ? ' · Future date' : ''}`;
            if (note) note.textContent = state.note;
            const times = schedule();
            if (scheduleLabel && times) scheduleLabel.textContent = `${times.mode === 'custom' ? 'Custom' : 'System'}: ${times.start.slice(0, 5)}–${times.end.slice(0, 5)}`;
            if (autoNote) autoNote.hidden = autoCheckout?.value !== '1';
            row.classList.remove('bg-sky-50', 'bg-violet-50', 'bg-amber-50', 'bg-rose-50', 'bg-emerald-50', 'bg-white');
            row.classList.add(state.colour);
        };
        worked?.addEventListener('change', () => {
            workedChoice = worked.checked;
            leaveChoice = null;
            if (workedChoice) fillTimes();
            else {
                const state = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar);
                if (state.closed || scheduledIn?.value === '1') checkIn.value = '';
                if (state.closed || autoCheckout?.value === '1') checkOut.value = '';
                if (autoCheckout) autoCheckout.value = '0';
                if (scheduledIn) scheduledIn.value = '0';
            }
            update();
        });
        staff?.addEventListener('change', () => {
            // Remap only generated times; keep manually entered or imported actual punches.
            const times = schedule();
            if (times && scheduledIn?.value === '1') checkIn.value = times.start.slice(0, 5);
            if (times && autoCheckout?.value === '1') checkOut.value = times.end.slice(0, 5);
            update();
        });
        date.addEventListener('input', () => {
            const state = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar);
            if (!state.closed && scheduledIn?.value === '1') {
                checkIn.value = '';
                scheduledIn.value = '0';
                if (autoCheckout?.value === '1') { checkOut.value = ''; autoCheckout.value = '0'; }
            }
            workedChoice = null;
            leaveChoice = null;
            update();
        });
        checkIn.addEventListener('input', () => {
            if (scheduledIn) scheduledIn.value = '0';
            workedChoice = null;
            leaveChoice = null;
            update();
        });
        checkOut.addEventListener('input', () => {
            if (autoCheckout) autoCheckout.value = '0';
            workedChoice = null;
            leaveChoice = null;
            update();
        });
        leave.addEventListener('change', () => { leaveChoice = leave.checked; update(); });
        update();
    }
    for (const form of document.querySelectorAll('[data-closure-form]')) {
        const month = form.querySelector('[name="month"]');
        const date = form.querySelector('[name="closure_date"]');
        month.addEventListener('change', () => {
            if (!/^\d{4}-\d{2}$/.test(month.value)) return;
            date.min = `${month.value}-01`;
            const [year, monthNumber] = month.value.split('-').map(Number);
            date.max = `${month.value}-${new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()}`;
            if (date.value && !date.value.startsWith(month.value)) date.value = '';
        });
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAttendanceImport);
    else initAttendanceImport();
}
