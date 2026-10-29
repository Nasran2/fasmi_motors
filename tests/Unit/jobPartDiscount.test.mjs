import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const view = fs.readFileSync(new URL('../../resources/views/jobs/show.blade.php', import.meta.url), 'utf8');
const script = view.slice(view.indexOf('function openPartModal(details)'), view.indexOf("let currentEditingHiddenInputId = ''"));
function setup() {
    const nodes = new Map();
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, {value: '', textContent: '', addEventListener() {}, showModal() {}, setCustomValidity(message) { this.error = message; }});
        return nodes.get(id);
    };
    const ctx = vm.createContext({document: {getElementById: get}});
    vm.runInContext(script, ctx);
    return {ctx, get, open: data => {ctx.details = data; vm.runInContext('openPartModal(details)', ctx);}};
}
const part = {id: 1, name: 'Brake Pad', quantity: 2, price: 100, cost: 40, discount: 25, external: false};

test('editing loads an existing discount and shows the discounted line total', () => {
    const ui = setup();
    ui.open(part);
    assert.equal(ui.get('pe-discount').value, 25);
    assert.equal(ui.get('pe-total').textContent, 'Rs. 175.00');
    ui.get('pe-discount').value = '50';
    vm.runInContext('updatePartEditTotal()', ui.ctx);
    assert.equal(ui.get('pe-total').textContent, 'Rs. 150.00');
});

test('opening another part resets discount rather than carrying over the previous product value', () => {
    const ui = setup();
    ui.open(part);
    ui.open({...part, id: 2, discount: undefined});
    assert.equal(ui.get('pe-discount').value, 0);
    assert.equal(ui.get('pe-total').textContent, 'Rs. 200.00');
});

test('lower quantity invalidates an excessive discount, including a fractional-cent negative tie', () => {
    const ui = setup();
    ui.open({...part, quantity: 1, price: 10, discount: 11});
    assert.match(ui.get('pe-discount').error, /cannot exceed/);
    ui.open({...part, quantity: 1.5, price: 0.67, discount: 1.01});
    assert.match(ui.get('pe-discount').error, /cannot exceed/);
    ui.get('pe-discount').value = 1;
    vm.runInContext('updatePartEditTotal()', ui.ctx);
    assert.equal(ui.get('pe-discount').error, '');
    assert.equal(ui.get('pe-total').textContent, 'Rs. 0.01');
});
