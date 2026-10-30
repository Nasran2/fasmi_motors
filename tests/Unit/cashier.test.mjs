import test from 'node:test';
import assert from 'node:assert/strict';
import { cashierClosing, openingBalancesState } from '../../resources/js/cashier.js';

test('closing requires a physical count and withdrawal changes expected cash only', () => {
    const form = cashierClosing({main: 1000, washing: 100, spare_parts: 500});
    assert.equal(form.hasCount, false);
    assert.equal(form.hasDifference, false);
    form.updateWithdrawal('100.10');
    assert.equal(form.expectedCashTotal, 1499.90);
    form.closingBalance = '1490.00';
    assert.equal(form.cashDifference, -9.90);
    form.updateWithdrawal('110.00');
    assert.equal(form.closingBalance, '1490.00');
    assert.equal(form.hasDifference, false);
    form.updateWithdrawal('501');
    assert.equal(form.withdrawalTooHigh, true);
});

test('a single count reconciles very different account allocations by their total', () => {
    const form = cashierClosing({main: -1257853.43, washing: 500, spare_parts: 1276918.43}, '19565');
    assert.equal(form.expectedCashTotal, 19565);
    assert.equal(form.hasDifference, false);
    form.closingBalance = '19560';
    assert.equal(form.cashDifference, -5);
});

test('a refreshed stale form retains the withdrawal but asks for a fresh count', () => {
    const form = cashierClosing({main: 1100, washing: 0, spare_parts: 2000}, '', 300);
    assert.equal(form.expectedCashTotal, 2800);
    assert.equal(form.hasCount, false);
});

test('account amounts include washing in main and owner withdrawal in spare parts', () => {
    const form = cashierClosing({main: 1000, washing: 200, spare_parts: 500}, '', 100);
    assert.equal(form.balances.main, '1200');
    assert.equal(form.balances.spare_parts, '400');
    form.balances.spare_parts = '390';
    form.updateWithdrawal(120);
    assert.equal(form.balances.spare_parts, '370.00');
    assert.equal(form.expectedBalance('spare_parts'), 380);
    assert.equal(form.hasDifference, true);
    form.closingBalance = '1570';
    assert.equal(form.cashMatchesAccounts, true);
});

test('offsetting account corrections need a reason even when total cash matches', () => {
    const form = cashierClosing({main: 1000, washing: 200, spare_parts: 500}, '1700', 0, {main: 1250, spare_parts: 450});
    assert.equal(form.cashMatchesAccounts, true);
    assert.equal(form.cashDifference, 0);
    assert.equal(form.hasDifference, true);
    form.closingBalance = '1600';
    assert.equal(form.cashMatchesAccounts, false);
});

test('bank auto-fills and changes independently of cash, withdrawal and matching totals', () => {
    const form = cashierClosing({main: 1000, washing: 200, spare_parts: 500, bank: 3000}, '1600', 100);
    assert.equal(form.balances.bank, '3000');
    assert.equal(form.expectedBalance('bank'), 3000);
    assert.equal(form.expectedCashTotal, 1600);
    assert.equal(form.allocatedCashTotal, 1600);
    assert.equal(form.hasDifference, false);
    form.balances.bank = '2950';
    assert.equal(form.hasDifference, true);
    assert.equal(form.cashMatchesAccounts, true);
    assert.equal(form.cashDifference, 0);
    form.updateWithdrawal(120);
    assert.equal(form.balances.bank, '2950');
    assert.equal(form.expectedCashTotal, 1580);
});


test('drawer cash is independent of main and spare parts opening balances', () => {
    const form = openingBalancesState('5000', ['74838', '12000', '0'], '5000', ['74838', '12000', '0']);
    assert.equal(form.valid, true);
    assert.equal(form.changed, false);
    assert.equal(openingBalancesState('4000', ['74838', '12000', '0'], '5000', ['74838', '12000', '0']).changed, true);
    assert.equal(openingBalancesState('5000', ['80000', '12000', '0'], '5000', ['74838', '12000', '0']).changed, true);
});

test('drawer cash and separate account balances must each be valid amounts', () => {
    assert.equal(openingBalancesState('0', ['0', '0', '0'], '0', ['0', '0', '0']).valid, true);
    assert.equal(openingBalancesState('5000', ['-100', '0', '0'], '0', ['0', '0', '0']).valid, true);
    for (const value of ['', '-1', 'not money', Infinity]) {
        assert.equal(openingBalancesState(value, ['1000', '0', '0'], '0', ['0', '0', '0']).valid, false);
        if (value !== '-1') assert.equal(openingBalancesState('100', [value, '0', '0'], '0', ['0', '0', '0']).valid, false);
    }
});

test('closing drawer cash does not need to match account balances', () => {
    const form = cashierClosing({main: 74838, spare_parts: 12000, washing: 200, bank: 300}, '5000', 0, {}, 5000);
    assert.equal(form.expectedCashTotal, 5000);
    assert.equal(form.balances.main, '75038');
    assert.equal(form.cashMatchesAccounts, true);
    assert.equal(form.hasDifference, false);
    form.balances.main = '76000';
    assert.equal(form.expectedCashTotal, 5000);
    assert.equal(form.hasDifference, true);
    form.updateWithdrawal(100);
    assert.equal(form.expectedCashTotal, 4900);
    assert.equal(form.balances.spare_parts, '11900.00');
    form.closingBalance = '4900';
    assert.equal(form.cashDifference, 0);
});

test('cashier closing count fills once from Main plus Spare Parts, with washing folded into Main and Bank excluded', () => {
    const form = cashierClosing({main:1000, washing:200, spare_parts:500, bank:90000}, '', 100, {}, 500, true);
    assert.equal(form.closingBalance, '1600.00');
    form.balances.main = '800';
    form.updateWithdrawal(120);
    assert.equal(form.closingBalance, '1600.00');
    form.closingBalance = '350.25';
    form.balances.spare_parts = '100';
    form.updateWithdrawal(150);
    assert.equal(form.closingBalance, '350.25');
});

test('an explicitly entered cashier count including zero wins over automatic fill', () => {
    for (const count of ['0', '75.50']) {
        const form = cashierClosing({main:1000, spare_parts:500, bank:9000}, count, 0, {}, 500, true);
        assert.equal(form.closingBalance, count);
    }
    const form = cashierClosing({main:1000, spare_parts:500}, '', 0, {main:'0.10', spare_parts:'0.20'}, 500, true);
    assert.equal(form.closingBalance, '0.30');
});
