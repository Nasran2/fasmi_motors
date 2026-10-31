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
    const form = document.getElementById('attendance-import-form');
    const body = form?.querySelector('[data-import-body]');
    const template = form?.querySelector('[data-import-row-template]');
    const count = form?.querySelector('[data-import-count]');
    const confirm = form?.querySelector('[data-import-confirm]');
    const undo = form?.querySelector('[data-import-undo]');
    const message = form?.querySelector('[data-import-row-message]');
    const manifest = form?.querySelector('[name="review_manifest"]');
    const deleted = [];
    const removedIds = new Set();
    const rows = () => Array.from(body?.querySelectorAll('[data-import-row]') || []);
    let nextId = Math.max(0, ...rows().map(row => Number(row.dataset.rowKey))) + 1;
    const refreshRows = () => {
        const current = rows();
        if (count) count.textContent = String(current.length);
        if (undo) undo.disabled = deleted.length === 0;
        if (manifest) manifest.value = JSON.stringify({active: current.map(row => Number(row.dataset.rowKey)), removed: [...removedIds]});
        const keys = new Map();
        for (const row of current) {
            const staffId = row.querySelector('[data-import-technician]').value;
            const dateValue = row.querySelector('[data-import-date]').value;
            if (!staffId || !dateValue) continue;
            const key = `${staffId}|${dateValue}`;
            keys.set(key, (keys.get(key) || 0) + 1);
        }
        let duplicate = false;
        for (const row of current) {
            const staffId = row.querySelector('[data-import-technician]').value;
            const dateValue = row.querySelector('[data-import-date]').value;
            const target = row.querySelector('[data-import-duplicate]');
            const repeated = staffId && dateValue && keys.get(`${staffId}|${dateValue}`) > 1;
            duplicate ||= Boolean(repeated);
            if (target) {
                target.textContent = repeated ? 'Same staff / date twice' :
                    (row.dataset.originalDate === dateValue && row.dataset.originalStaff === staffId ? row.dataset.originalDuplicate : 'Checked on import');
                target.classList.toggle('text-red-700', Boolean(repeated));
                target.classList.toggle('font-bold', Boolean(repeated));
            }
        }
        if (confirm) confirm.disabled = !current.length || duplicate;
        if (message) message.textContent = duplicate ? 'Two rows have the same staff and date. Delete one or change its date before importing.'
            : !current.length ? 'Add or restore a row before confirming.' : 'Rows sort by date. Deleted rows are excluded from this import; saved attendance is kept.';
    };
    const sortRows = (followRow = null) => {
        if (!body) return;
        const focused = document.activeElement;
        const ordered = rows().sort((left, right) => {
            const leftDate = left.querySelector('[data-import-date]').value || '9999-99-99';
            const rightDate = right.querySelector('[data-import-date]').value || '9999-99-99';
            return leftDate.localeCompare(rightDate) || Number(left.dataset.rowKey) - Number(right.dataset.rowKey);
        });
        ordered.forEach(row => body.appendChild(row));
        if (focused && body.contains(focused)) focused.focus({preventScroll: true});
        followRow?.scrollIntoView({block: 'nearest'});
        refreshRows();
    };
    const initRow = row => {
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
        row.dataset.originalDate = date.value;
        row.dataset.originalStaff = staff.value;
        row.dataset.originalDuplicate = row.querySelector('[data-import-duplicate]')?.textContent.trim() || 'Checked on import';
        let leaveChoice = leave.checked;
        let workedChoice = worked?.checked ?? null;
        let previousDayClosed = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar).closed;
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
            if (row.dataset.newRow === '1') {
                const person = row.querySelector('[data-import-person]');
                if (person) person.textContent = staff.value ? staff.selectedOptions[0].textContent : 'Manual row';
            }
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
            refreshRows();
        });
        date.addEventListener('input', () => {
            const state = attendanceImportState(date.value, checkIn.value, checkOut.value, calendar);
            if (previousDayClosed && !state.closed && scheduledIn?.value === '1') {
                checkIn.value = '';
                scheduledIn.value = '0';
                if (autoCheckout?.value === '1') { checkOut.value = ''; autoCheckout.value = '0'; }
            }
            previousDayClosed = state.closed;
            workedChoice = null;
            leaveChoice = null;
            update();
        });
        date.addEventListener('change', () => {
            if (date.value && date.validity.valid) sortRows(row);
        });
        row.querySelector('[data-import-delete]')?.addEventListener('click', () => {
            let marker = null;
            if (row.dataset.newRow !== '1') {
                marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = 'removed_rows[]';
                marker.value = row.dataset.rowKey;
                form.appendChild(marker);
                removedIds.add(Number(row.dataset.rowKey));
            }
            deleted.push({row, marker});
            row.remove();
            refreshRows();
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
    };
    rows().forEach(initRow);
    if (form && body && template) {
        form.querySelector('[data-import-add]')?.addEventListener('click', () => {
            const current = rows();
            const previousStaff = current[current.length - 1]?.querySelector('[data-import-technician]').value || '';
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-import-row]');
            const id = nextId++;
            row.dataset.rowKey = String(id);
            for (const input of row.querySelectorAll('[name]')) input.name = input.name.replaceAll('__ROW__', String(id));
            row.querySelector('[data-row-id]').value = String(id);
            row.querySelector('[data-import-technician]').value = previousStaff;
            body.appendChild(fragment);
            initRow(row);
            refreshRows();
            const target = row.querySelector(previousStaff ? '[data-import-date]' : '[data-import-technician]');
            target.focus();
            row.scrollIntoView({block: 'nearest'});
        });
        undo?.addEventListener('click', () => {
            const restored = deleted.pop();
            if (!restored) return;
            restored.marker?.remove();
            removedIds.delete(Number(restored.row.dataset.rowKey));
            body.appendChild(restored.row);
            sortRows(restored.row);
        });
        form.addEventListener('submit', event => {
            sortRows();
            if (confirm?.disabled) event.preventDefault();
        });
        sortRows();
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
