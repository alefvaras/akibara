/* WooCommerce Customers extension. Uses report hooks, never DOM patching. */
(function (hooks, element) {
    'use strict';
    if (!hooks || !element) return;
    var namespace = 'akibara-rewards/customers';

    hooks.addFilter('woocommerce_admin_customers_report_filters', namespace, function (filters) {
        return filters.concat([{
            label: 'Puntos Akibara', param: 'akb_points',
            staticParams: ['paged', 'per_page', 'filter'], showFilters: function () { return true; },
            filters: [
                { label: 'Todos los saldos', value: 'all' },
                { label: 'Con saldo', value: 'positive' },
                { label: 'Saldo cero (con cuenta)', value: 'zero' },
                { label: 'Sin cuenta', value: 'guest' }
            ]
        }]);
    });

    hooks.addFilter('woocommerce_admin_customers_report_advanced_filters', namespace, function (settings) {
        return Object.assign({}, settings, { filters: Object.assign({}, settings.filters, {
            akb_balance: {
                labels: { add: 'Saldo de puntos', remove: 'Quitar filtro de puntos', rule: 'Saldo de puntos <rule/> <filter/>' },
                rules: [
                    { value: 'min', label: 'Más de' },
                    { value: 'max', label: 'Menos de' },
                    { value: 'between', label: 'Entre (incluidos)' }
                ],
                input: { component: 'Number' }
            }
        }) });
    });

    hooks.addFilter('woocommerce_admin_report_table', namespace, function (table) {
        if (table.endpoint !== 'customers') return table;
        var customers = (table.items && table.items.data) || [];
        var headers = table.headers.concat([
            { label: 'Cuenta', key: 'akb_account' },
            { label: 'Puntos Akibara', key: 'akb_points', isNumeric: true },
            { label: 'Puntos', key: 'akb_manage' }
        ]);
        var rows = table.rows.map(function (row, index) {
            var rewards = (customers[index] && customers[index].akb_rewards) || {};
            var hasAccount = rewards.has_account === true;
            var balance = hasAccount ? Number(rewards.balance) : null;
            var action = hasAccount && rewards.manage_url ? element.createElement('a', { href: rewards.manage_url }, 'Gestionar puntos') : '—';
            return row.concat([
                { display: hasAccount ? 'Con cuenta' : 'Sin cuenta', value: hasAccount ? 'Con cuenta' : 'Sin cuenta' },
                { display: hasAccount ? balance.toLocaleString('es-CL') : '—', value: balance },
                { display: action, value: '' }
            ]);
        });
        return Object.assign({}, table, { headers: headers, rows: rows });
    });
})(window.wp && window.wp.hooks, window.wp && window.wp.element);
