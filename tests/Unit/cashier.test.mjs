import test from 'node:test';
import assert from 'node:assert/strict';
import { cashierClosing, openingAllocation, openingCashTotal } from '../../resources/js/cashier.js';

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


test('opening allocation uses cents and requires the confirmed split to match', () => {
    assert.equal(openingAllocation('0.30', ['0.10', '0.20', '0'], '0.30', ['0.10', '0.20', '0']).matches, true);
    assert.equal(openingAllocation('4665', ['3000', '1665', '0'], '4665', ['3000', '1665', '0']).changed, false);
    assert.equal(openingAllocation('4665', ['3100', '1565', '0'], '4665', ['3000', '1665', '0']).changed, true);
    assert.equal(openingAllocation('4665', ['3000', '1660', '0'], '4665', ['3000', '1665', '0']).matches, false);
});

test('opening rejects negative, blank or invalid physical cash even if the total matches', () => {
    assert.equal(openingAllocation('4665', ['-1261353.43', '1266018.43', '0'], '4665', ['-1261353.43', '1266018.43', '0']).matches, false);
    assert.equal(openingAllocation('0', ['', '0', '0'], '0', ['0', '0', '0']).matches, false);
    assert.equal(openingAllocation('0', ['bad', '0', '0'], '0', ['0', '0', '0']).matches, false);
});


test('opening total follows edited account amounts instead of the previous opening total', () => {
    assert.equal(openingCashTotal(['74838', '0', '0']), '74838.00');
    assert.equal(openingCashTotal(['74838', '250', '60']), '75148.00');
    assert.equal(openingCashTotal(['0.1', '0.2', '0']), '0.30');
    assert.equal(openingCashTotal(['0', '0', '0']), '0.00');
});

test('opening total stays incomplete while an account is invalid', () => {
    for (const value of ['', '-1', 'not money', Infinity]) {
        assert.equal(openingCashTotal([value, '0', '0']), '');
    }
});
