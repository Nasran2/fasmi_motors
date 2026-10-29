import test from 'node:test';
import assert from 'node:assert/strict';
import { attendanceImportState } from '../../resources/js/attendanceImport.js';

const calendar = {weekly_closed: ['Friday'], today: '2026-10-29', special_closed: {'2026-10-14': {name: 'Shop maintenance', type: 'Company'}}};

test('missing attendance is checked only on working days', () => {
    const working = attendanceImportState('2026-10-13', '', '', calendar);
    assert.equal(working.checked, true);
    assert.equal(working.disabled, false);
    const weekly = attendanceImportState('2026-10-16', '', '', calendar);
    assert.equal(weekly.kind, 'weekly');
    assert.equal(weekly.checked, false);
    assert.equal(weekly.disabled, true);
    const special = attendanceImportState('2026-10-14', '', '', calendar);
    assert.equal(special.kind, 'special');
    assert.equal(special.label, 'Shop maintenance');
    assert.equal(special.checked, false);
});

test('manual leave choices are respected while actual punches and closures stay protected', () => {
    assert.equal(attendanceImportState('2026-10-13', '', '', calendar, false).checked, false);
    assert.equal(attendanceImportState('2026-10-13', '08:00', '', calendar, true).checked, false);
    assert.equal(attendanceImportState('2026-10-13', '', '17:00', calendar).checked, false);
    assert.equal(attendanceImportState('2026-10-14', '', '', calendar, true).checked, false);
});

test('future days are not automatically marked but planned leave can be explicitly checked', () => {
    assert.equal(attendanceImportState('2026-10-31', '', '', calendar).checked, false);
    assert.equal(attendanceImportState('2026-10-31', '', '', calendar, true).checked, true);
});

test('date edits select the current calendar rule and special closures take priority', () => {
    const modified = {...calendar, special_closed: {'2026-10-16': {name: 'Special closure'}}};
    assert.equal(attendanceImportState('2026-10-16', '', '', modified).kind, 'special');
    assert.equal(attendanceImportState('', '', '', calendar).checked, false);
    assert.equal(attendanceImportState('2026-02-31', '', '', calendar).disabled, true);
});


test('unchecking missing working-day attendance shows the unpaid note and red row', () => {
    const paid = attendanceImportState('2026-10-13', '', '', calendar);
    assert.equal(paid.colour, 'bg-amber-50');
    assert.equal(paid.note, 'Paid leave · no absence deduction');
    const unpaid = attendanceImportState('2026-10-13', '', '', calendar, false);
    assert.equal(unpaid.colour, 'bg-rose-50');
    assert.equal(unpaid.note, 'Absent · normal deduction');
    const closed = attendanceImportState('2026-10-16', '', '', calendar);
    assert.equal(closed.colour, 'bg-sky-50');
    assert.equal(closed.note, 'Shop closed · not personal leave');
    const present = attendanceImportState('2026-10-13', '08:00', '17:00', calendar);
    assert.equal(present.colour, 'bg-white');
    assert.equal(present.note, 'Attendance from punches');
});
