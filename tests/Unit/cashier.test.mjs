import test from 'node:test';
import assert from 'node:assert/strict';
import { cashierClosing } from '../../resources/js/cashier.js';

test('owner withdrawal updates parts live while preserving a manual variance', () => {
    const form = cashierClosing({ main: 1000, washing: 100, spare_parts: 500 });
    form.updateWithdrawal('100.10');
    assert.equal(form.balances.spare_parts, '399.90');
    assert.equal(form.expectedCashTotal, 1499.90);
    assert.equal(form.countedCashTotal, 1499.90);
    assert.equal(form.hasDifference, false);
    form.balances.spare_parts = '390.00';
    form.updateWithdrawal('110.10');
    assert.equal(form.balances.spare_parts, '380.00');
    assert.equal(form.hasDifference, true);
    assert.equal(form.balances.main, '1000');
    form.updateWithdrawal('501');
    assert.equal(form.withdrawalTooHigh, true);
});

test('refreshed balances retain the pending withdrawal without retaining stale balances', () => {
    const form = cashierClosing({ main: 1100, washing: 0, spare_parts: 2000 }, {}, 300);
    assert.equal(form.balances.spare_parts, '1700');
    assert.equal(form.expectedCashTotal, 2800);
    assert.equal(form.countedCashTotal, 2800);
    assert.equal(form.hasDifference, false);
});
