'use strict';
const assert = require('node:assert/strict');
const {test} = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../assets/js/account-rewards.js'), 'utf8');

function mount(overrides = {}) {
    const listeners = {};
    const preview = {textContent: ''};
    const input = {
        value: '100', min: '100', max: '2480', focused: false,
        get validity() { const n = Number(this.value); return {valid: Number.isInteger(n) && n >= Number(this.min) && n <= Number(this.max)}; },
        addEventListener(event, fn) { listeners[event] = fn; },
        focus() { this.focused = true; }
    };
    const maximum = {hidden: true, addEventListener(event, fn) { listeners['max:' + event] = fn; }};
    const form = {
        dataset: {akbRedeem: JSON.stringify({value: 1, eligible: true, decimals: 0, decimal: ',', thousand: '.', format: '%1$s%2$s', symbol: '$', ...overrides})},
        querySelector(s) { return {'[name="akb_rw_points"]': input, '#akb-rw-preview': preview, '.akb-rw-max': maximum}[s]; },
        submit() { throw new Error('preview must not submit'); },
        requestSubmit() { throw new Error('preview must not submit'); }
    };
    const sandbox = {Intl, document: {readyState: 'complete', documentElement: {lang: 'es-CL'}, querySelectorAll: () => [form]}, fetch() {throw new Error('preview must not fetch');}};
    vm.runInNewContext(source, sandbox);
    return {input, preview, maximum, listeners};
}

test('uses configured point value and WooCommerce currency format', () => {
    const {input, preview, listeners} = mount({value: 2});
    assert.equal(preview.textContent, '100 puntos: $200 de descuento aplicable en tu carrito actual.');
    input.value = '1234'; listeners.input();
    assert.equal(preview.textContent, '1.234 puntos: $2.468 de descuento aplicable en tu carrito actual.');
    const alt = mount({decimals: 2, decimal: '.', thousand: ',', format: '%2$s %1$s', symbol: 'USD'});
    alt.input.value = '1234'; alt.listeners.input();
    assert.equal(alt.preview.textContent, '1.234 puntos: 1,234.00 USD de descuento aplicable en tu carrito actual.');
});

test('maximum only fills server max, announces preview, focuses and remains editable', () => {
    const {input, preview, maximum, listeners} = mount();
    assert.equal(maximum.hidden, false);
    listeners['max:click']();
    assert.equal(input.value, '2480');
    assert.equal(input.focused, true);
    assert.equal(preview.textContent, '2.480 puntos: $2.480 de descuento aplicable en tu carrito actual.');
    input.value = '200'; listeners.input();
    assert.equal(preview.textContent, '200 puntos: $200 de descuento aplicable en tu carrito actual.');
    assert.deepEqual(Object.keys(listeners).sort(), ['change', 'input', 'max:click']);
});

test('invalid and empty values never invent money or clamp silently', () => {
    const {input, preview, listeners} = mount();
    for (const value of ['', '-1', '0', '99', '100.5', '2481', 'abc', '1e100']) {
        input.value = value; listeners.input();
        assert.equal(input.value, value);
        assert.doesNotMatch(preview.textContent, /NaN|Infinity|equivalen a|\$/);
    }
});


test('unavailable server state leaves preview intact and cannot fill or submit', () => {
    const {preview, maximum, listeners, input} = mount({eligible: false});
    assert.equal(maximum.hidden, false);
    assert.deepEqual(Object.keys(listeners), []);
    assert.equal(preview.textContent, '');
    assert.equal(input.value, '100');
});
