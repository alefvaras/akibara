'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function extension() {
    const filters = {};
    const context = { window: { wp: {
        hooks: { addFilter(name, namespace, callback) { filters[name] = callback; } },
        element: { createElement(tag, props, text) { return { tag, props, text }; } }
    } } };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/js/admin-customers.js'), 'utf8'), context);
    return filters;
}

test('only the Customers table gains points columns and existing data is preserved', () => {
    const render = extension().woocommerce_admin_report_table;
    const unrelated = { endpoint: 'orders' };
    assert.equal(render(unrelated), unrelated);
    const input = { endpoint: 'customers', headers: [{ key: 'name' }], rows: [[{ value: 'Customer' }]], items: { data: [{ id: 999, user_id: 197, akb_rewards: { has_account: true, balance: 500, manage_url: '/admin.php?page=akb-customer-points&user_id=197' } }] } };
    const output = render(input);
    assert.equal(input.headers.length, 1);
    assert.equal(input.rows[0].length, 1);
    assert.equal(output.rows[0][2].value, 500);
    assert.equal(output.rows[0][3].display.props.href, '/admin.php?page=akb-customer-points&user_id=197');
    assert.equal(output.rows[0][3].display.text, 'Gestionar puntos');
});

test('guest rows display no numeric balance and cannot expose an adjustment link', () => {
    const render = extension().woocommerce_admin_report_table;
    const table = render({ endpoint: 'customers', headers: [], rows: [[], []], items: { data: [{ akb_rewards: { has_account: false, balance: null, manage_url: '/should-not-appear' } }, { akb_rewards: { has_account: true, balance: 0, manage_url: '/account' } }] } });
    assert.equal(table.rows[0][0].display, 'Sin cuenta');
    assert.equal(table.rows[0][1].value, null);
    assert.equal(table.rows[0][2].display, '—');
    assert.equal(table.rows[1][0].display, 'Con cuenta');
    assert.equal(table.rows[1][1].value, 0);
});

test('native report filter definitions include account balance modes and numeric ranges', () => {
    const filters = extension();
    const input = [{ param: 'filter' }];
    const output = filters.woocommerce_admin_customers_report_filters(input);
    assert.equal(input.length, 1);
    assert.equal(output[1].param, 'akb_points');
    assert.deepEqual(Array.from(output[1].filters, item => item.value), ['all', 'positive', 'zero', 'guest']);
    const advanced = filters.woocommerce_admin_customers_report_advanced_filters({ title: 'Filters', filters: { email: {} } });
    assert.ok(advanced.filters.email);
    assert.equal(advanced.filters.akb_balance.input.component, 'Number');
    assert.deepEqual(Array.from(advanced.filters.akb_balance.rules, rule => rule.value), ['min', 'max', 'between']);
});
