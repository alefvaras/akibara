"""Executable negative controls for the inventory deployment gate (ADR-010)."""
import ast
import contextlib
import io
import json
from pathlib import Path
import runpy
import subprocess
import sys
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).with_name('guardrail-inventario-akibara.py')
PHP = next(ast.literal_eval(n.value) for n in ast.walk(ast.parse(SOURCE.read_text()))
           if isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'php_script' for t in n.targets))
PRELUDE = r'''
define('ARRAY_A', 'ARRAY_A');
$fixtures = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$wpdb = new class {
    public $posts = 'posts'; public $postmeta = 'postmeta'; public $last_error = '';
    public function get_results($sql, $format) { return $GLOBALS['fixtures']; }
};
function is_wp_error($value) { return false; }
function wp_get_object_terms($ids, $taxonomy, $args) {
    $terms = [];
    foreach ($GLOBALS['fixtures'] as $row) {
        if (!empty($row['oos'])) $terms[] = (object)['slug'=>'outofstock', 'object_id'=>$row['ID']];
    }
    return $terms;
}
'''


class InventoryGateTest(unittest.TestCase):
    def report(self, **changes):
        row = dict(ID=1, post_title='Tomo', stock_status='instock', manage_stock='yes',
                   stock_qty='3', backorders='no', regular_price='10000', sale_price='', price='10000')
        row.update(changes)
        result = subprocess.run(['php', '-r', PRELUDE + PHP.removeprefix('<?php')],
                                input=json.dumps([row]), text=True, capture_output=True, check=True)
        return json.loads(result.stdout)

    def approved(self, **changes):
        return not any(self.report(**changes)['anomalies'].values())

    def test_valid_instock_and_outofstock(self):
        self.assertTrue(self.approved())
        self.assertTrue(self.approved(stock_status='outofstock', oos=True, manage_stock='yes', stock_qty='0', sale_price='', price='10000'))
        self.assertTrue(self.approved(stock_status='outofstock', oos=True, manage_stock='no', stock_qty='', sale_price='', price='10000'))

    def test_onbackorder_is_allowed(self):
        for manage in ('no', 'yes'):
            with self.subTest(manage=manage):
                rep = self.report(stock_status='onbackorder', manage_stock=manage, stock_qty='0', backorders='yes')
                self.assertTrue(not any(rep['anomalies'].values()))
                self.assertEqual(1, rep['onbackorder_count'])

    def test_flexible_inventory_settings_allowed(self):
        for changes in ({'manage_stock':'no'}, {'stock_qty':'0'}, {'stock_qty':'-1'}, {'backorders':'yes'}, {'backorders':'notify'}):
            with self.subTest(changes=changes):
                self.assertTrue(self.approved(**changes))

    def test_physical_stock_can_have_sale_price(self):
        self.assertTrue(self.approved(stock_status='instock', manage_stock='yes', stock_qty='1', backorders='no', sale_price='9500', price='9500'))

    def test_outofstock_contract(self):
        self.assertTrue(self.approved(stock_status='outofstock', oos=True, stock_qty='0', backorders='no', sale_price='', price='10000'))
        self.assertTrue(self.approved(stock_status='outofstock', oos=True, stock_qty='0', backorders='no', sale_price='9500', price='9500'))
        self.assertFalse(self.approved(stock_status='outofstock', oos=False, stock_qty='0', backorders='no', sale_price='', price='10000'))

    def test_visibility_and_malformed_prices_rejected(self):
        self.assertFalse(self.approved(oos=True))
        self.assertFalse(self.approved(price='invalid'))

    def cli_exit(self, result):
        with patch.object(sys, 'argv', [str(SOURCE), '--target', 'local']), \
                patch('subprocess.run', return_value=result), contextlib.redirect_stdout(io.StringIO()), \
                contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as caught:
                runpy.run_path(str(SOURCE), run_name='__main__')
        return caught.exception.code

    def test_cli_never_approves_failed_empty_or_incomplete_reads(self):
        for status, payload in ((1, ''), (0, '{}'), (0, 'not json'), (0, '[]')):
            with self.subTest(payload=payload):
                self.assertEqual(2, self.cli_exit(subprocess.CompletedProcess([], status, payload, '')))

    def test_cli_verdict_matches_actual_anomalies(self):
        for changes, expected in (({}, 0), ({'price':'invalid'}, 1), ({'oos':True}, 1)):
            result = subprocess.CompletedProcess([], 0, json.dumps(self.report(**changes)), '')
            self.assertEqual(expected, self.cli_exit(result))


if __name__ == '__main__':
    unittest.main()
