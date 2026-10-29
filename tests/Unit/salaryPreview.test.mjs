import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const view = fs.readFileSync(new URL('../../resources/views/technicians/salaries.blade.php', import.meta.url), 'utf8');
const script = view.slice(view.indexOf('    let salaryPreviewRequest ='), view.indexOf('  </script>', view.indexOf('    let salaryPreviewRequest =')));

function preview({addition = '0', deductions = [], gross = 30000, baseAddition = 0, baseDeductions = 0, oldBalance = '0', oldRequested = '0'} = {}) {
    const nodes = new Map();
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, {textContent: '', disabled: false, classList: {toggle() {}}});
        return nodes.get(id);
    };
    const input = (value, max = undefined) => ({value, max, min:'0', validity: {rangeOverflow:false}, setCustomValidity() {}, setAttribute() {}, closest() {return null;}});
    const old = input(oldBalance), choice = input(oldRequested), additionInput = input(addition);
    const context = vm.createContext({
        document: {
            getElementById: get,
            querySelector: selector => selector.includes('old_balance_amount') ? old : selector.includes('old_balance_deduction') ? choice : additionInput,
            querySelectorAll: selector => selector.startsWith('input[name^="manual_deductions"]') ? deductions.map(value => ({value})) : [],
        },
    });
    vm.runInContext(script, context);
    context.saved = {gross_salary: gross, manual_additions: baseAddition, manual_deductions: baseDeductions, total_deductions: baseDeductions, net_payable: gross - baseDeductions, paid_amount: 0};
    vm.runInContext('salaryPreviewData = saved; updateSalaryDeductionPreview();', context);
    return get;
}

test('typing an addition updates gross and net while retaining manual deductions', () => {
    const get = preview({addition: '1500', deductions: ['200']});
    assert.equal(get('preview-gross').textContent, 'Rs. 31,500.00');
    assert.equal(get('preview-deductions').textContent, 'Rs. 200.00');
    assert.equal(get('preview-net').textContent, 'Rs. 31,300.00');
    assert.equal(get('save-draft-btn').disabled, false);
});

test('removing an addition already included in the last server preview deducts it once', () => {
    const get = preview({addition: '0', gross: 31500, baseAddition: 1500, baseDeductions: 200, deductions: ['200']});
    assert.equal(get('preview-gross').textContent, 'Rs. 30,000.00');
    assert.equal(get('preview-net').textContent, 'Rs. 29,800.00');
});

test('deductions above adjusted gross block draft saving', () => {
    const get = preview({addition: '100', deductions: ['30101']});
    assert.equal(get('save-draft-btn').disabled, true);
    assert.match(get('salary-save-alert').textContent, /deductions exceed/);
});


test('old debt beyond available salary carries forward while the paysheet remains saveable', () => {
    const get = preview({gross:63075, baseDeductions:61500, deductions:['61500'], oldBalance:'48950', oldRequested:'48950'});
    assert.equal(get('preview-net').textContent, 'Rs. 0.00');
    assert.equal(get('preview-deductions').textContent, 'Rs. 63,075.00');
    assert.equal(get('debt-remaining').textContent, 'Rs. 47,375.00');
    assert.match(get('debt-shortfall').textContent, /-47,375.00/);
    assert.equal(get('save-draft-btn').disabled, false);
});

test('a skipped old balance does not reduce the current salary', () => {
    const get = preview({gross:30000, oldBalance:'50000', oldRequested:'0'});
    assert.equal(get('preview-net').textContent, 'Rs. 30,000.00');
    assert.equal(get('debt-remaining').textContent, 'Rs. 50,000.00');
});
