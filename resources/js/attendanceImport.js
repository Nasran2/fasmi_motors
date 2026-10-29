const weekDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function attendanceImportState(date, checkIn, checkOut, calendar, explicitLeave = null) {
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T00:00:00Z`) : null;
    const valid = parsed && !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date;
    const special = calendar.special_closed[date];
    const weekly = valid && calendar.weekly_closed.includes(weekDays[parsed.getUTCDay()]);
    const closed = Boolean(special || weekly);
    const hasPunch = Boolean(checkIn || checkOut);
    const future = date > calendar.today;
    const checked = Boolean(valid && !closed && !hasPunch && (explicitLeave === null ? !future : explicitLeave));
    const absence = valid && !closed && !hasPunch && !future && !checked;
    const colour = special ? 'bg-violet-50' : weekly ? 'bg-sky-50' : checked ? 'bg-amber-50' : absence ? 'bg-rose-50' : 'bg-white';
    const note = !valid ? 'Choose a valid date' : closed ? (hasPunch ? 'Shop closed · punches retained' : 'Shop closed · not personal leave')
        : hasPunch ? 'Attendance from punches' : checked ? 'Paid leave · no absence deduction' : future ? 'Future empty day · skipped' : 'Absent · normal deduction';
    return {
        colour,
        note,
        kind: special ? 'special' : (weekly ? 'weekly' : 'working'),
        closed,
        label: special ? special.name : (weekly ? 'Weekly shop closed' : 'Working day'),
        future,
        disabled: closed || hasPunch || !valid,
        checked,
    };
}

function initAttendanceImport() {
    const calendarElement = document.getElementById('attendance-shop-calendar');
    if (!calendarElement) return;
    const calendar = JSON.parse(calendarElement.textContent);
    for (const row of document.querySelectorAll('[data-import-row]')) {
        const date = row.querySelector('[data-import-date]');
        const checkIn = row.querySelector('[data-import-in]');
        const checkOut = row.querySelector('[data-import-out]');
        const leave = row.querySelector('[data-import-leave]');
        const label = row.querySelector('[data-shop-day-label]');
        const note = row.querySelector('[data-leave-note]');
        const update = (explicitLeave = null) => {
            const state = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar, explicitLeave);
            leave.checked = state.checked;
            leave.disabled = state.disabled;
            label.textContent = `${state.label}${state.future ? ' · Future date' : ''}`;
            if (note) note.textContent = state.note;
            row.classList.remove('bg-sky-50', 'bg-violet-50', 'bg-amber-50', 'bg-rose-50', 'bg-white');
            row.classList.add(state.colour);
        };
        [date, checkIn, checkOut].forEach(input => input.addEventListener('input', () => update()));
        leave.addEventListener('change', () => update(leave.checked));
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
