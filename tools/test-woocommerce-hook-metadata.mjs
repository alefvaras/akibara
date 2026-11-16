import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync, existsSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const configFile = path.join(root, 'psalm.xml');
const metadataFile = path.join(root, 'tools/psalm/woocommerce/hooks.json');
const originalConfig = readFileSync(configFile, 'utf8');
const originalMetadata = readFileSync(metadataFile, 'utf8');
const wc = 'public_html/wp-content/plugins/woocommerce/includes/';
const productStore = wc + 'data-stores/class-wc-product-data-store-cpt.php';
const wct = 'public_html/wp-content/plugins/woocommerce/templates/';
const contracts = [
	['woocommerce_get_query_vars', wc + 'class-wc-query.php', 'filter', ['array']],
	['woocommerce_account_menu_items', wc + 'wc-account-functions.php', 'filter', ['array', 'array']],
	['woocommerce_account_{$key}_endpoint', wc + 'wc-template-functions.php', 'action', ['mixed']],
	['woocommerce_endpoint_{$endpoint}_title', wc + 'class-wc-query.php', 'filter', ['string', 'string', 'string']],
	['woocommerce_new_product', productStore, 'action', ['int', 'WC_Product']],
	['woocommerce_update_product', productStore, 'action', ['int', 'WC_Product']],
	['woocommerce_product_set_stock', wc + 'wc-stock-functions.php', 'action', ['WC_Product']],
	['woocommerce_variation_set_stock', wc + 'wc-stock-functions.php', 'action', ['WC_Product']],
	['woocommerce_product_set_stock_status', productStore, 'action', ['int', 'string', 'WC_Product']],
	['woocommerce_variation_set_stock_status', productStore, 'action', ['int', 'string', 'WC_Product']],
	['woocommerce_order_status_changed', wc + 'class-wc-order.php', 'action', ['int', 'string', 'string', 'WC_Order']],
	['woocommerce_thankyou', wct + 'checkout/thankyou.php', 'action', ['int']],
	['woocommerce_view_order', wct + 'myaccount/view-order.php', 'action', ['int']],
	['woocommerce_email_before_order_table', wct + 'emails/email-order-details.php', 'action', ['WC_Order', 'bool', 'bool', 'WC_Email']],
	['woocommerce_email_after_order_table', wct + 'emails/email-order-details.php', 'action', ['WC_Order', 'bool', 'bool', 'WC_Email']],
	['woocommerce_order_details_after_order_table', wct + 'order/order-details.php', 'action', ['WC_Order']],
	['woocommerce_package_rates', wc + 'class-wc-shipping.php', 'filter', ['array', 'array']],
	['woocommerce_order_item_get_formatted_meta_data', wc + 'class-wc-order-item.php', 'filter', ['array', 'WC_Order_Item']],
	['woocommerce_cart_shipping_method_full_label', wc + 'wc-cart-functions.php', 'filter', ['string', 'WC_Shipping_Rate']],
	['woocommerce_order_status_{$status_transition[\'to\']}', wc + 'class-wc-order.php', 'action', ['int', 'WC_Order', 'array']],
	['woocommerce_address_providers', 'public_html/wp-content/plugins/woocommerce/src/Internal/AddressProvider/AddressProviderController.php', 'filter', ['array']],
	['woocommerce_blocks_enqueue_cart_block_scripts_after', 'public_html/wp-content/plugins/woocommerce/src/Blocks/BlockTypes/Cart.php', 'action', []],
	['woocommerce_blocks_enqueue_checkout_block_scripts_after', 'public_html/wp-content/plugins/woocommerce/src/Blocks/BlockTypes/Checkout.php', 'action', []],
	['woocommerce_get_country_locale', wc + 'class-wc-countries.php', 'filter', ['array']],
	['woocommerce_get_default_value_for_{$key}', 'public_html/wp-content/plugins/woocommerce/src/Blocks/Domain/Services/CheckoutFieldsStorage.php', 'filter', ['mixed', 'string', 'WC_Data']],
	['woocommerce_checkout_validate_order_before_payment', 'public_html/wp-content/plugins/woocommerce/src/StoreApi/Utilities/OrderController.php', 'action', ['WC_Order', 'WP_Error']],
	['woocommerce_set_additional_field_value', 'public_html/wp-content/plugins/woocommerce/src/Blocks/Domain/Services/CheckoutFieldsStorage.php', 'action', ['string', 'mixed', 'string', 'WC_Customer|WC_Order']],
	['woocommerce_cart_loaded_from_session', wc + 'class-wc-cart-session.php', 'action', ['WC_Cart']],
	['woocommerce_before_{$this->object_type}_object_save', wc + 'abstracts/abstract-wc-data.php', 'action', ['WC_Data', 'WC_Data_Store_WP']],
	['woocommerce_store_api_cart_update_customer_from_request', 'public_html/wp-content/plugins/woocommerce/src/StoreApi/Routes/V1/CartUpdateCustomer.php', 'action', ['WC_Customer', 'WP_REST_Request']],
	['woocommerce_store_api_checkout_update_order_from_request', 'public_html/wp-content/plugins/woocommerce/src/StoreApi/Utilities/CheckoutTrait.php', 'action', ['WC_Order', 'WP_REST_Request']],
	['woocommerce_init', wc + 'class-woocommerce.php', 'action', []],
	['woocommerce_store_api_cart_select_shipping_rate', 'public_html/wp-content/plugins/woocommerce/src/StoreApi/Routes/V1/CartSelectShippingRate.php', 'action', ['string|null', 'string', 'WP_REST_Request']],
	['woocommerce_cart_shipping_packages', wc + 'class-wc-cart.php', 'filter', ['array']],
	['woocommerce_blocks_loaded', 'public_html/wp-content/plugins/woocommerce/src/Blocks/Domain/Bootstrap.php', 'action', []],
	['woocommerce_privacy_export_order_personal_data_meta', wc + 'class-wc-privacy-exporters.php', 'filter', ['array']],
	['woocommerce_privacy_remove_order_personal_data_meta', wc + 'class-wc-privacy-erasers.php', 'filter', ['array']],
	['woocommerce_privacy_remove_order_personal_data_meta_value', wc + 'class-wc-privacy-erasers.php', 'filter', ['string', 'string', 'string', 'string', 'WC_Order']],
	['woocommerce_privacy_export_customer_personal_data', wc + 'class-wc-privacy-exporters.php', 'filter', ['array', 'WC_Customer']],
	['woocommerce_privacy_erase_personal_data_customer', wc + 'class-wc-privacy-erasers.php', 'filter', ['array', 'WC_Customer']],
	['woocommerce_get_item_data', 'public_html/wp-content/plugins/woocommerce/src/StoreApi/Schemas/V1/CartItemSchema.php', 'filter', ['array', 'array']]
];

test('WooCommerce hook metadata matches the ten independently reviewed producer contracts and real AST calls', () => {
	const metadata = JSON.parse(originalMetadata).hooks;
	assert.equal(metadata.length, contracts.length);
	const pinned = JSON.parse(readFileSync(path.join(root, 'tools/ci-dependencies/dependencies.lock.json'), 'utf8')).dependencies.find(dependency => dependency.name === 'woocommerce');
	assert.ok(pinned, 'WooCommerce must come from the reviewed provider lock');
	for (const file of new Set(contracts.map(item => item[1]))) {
		const relative = path.posix.relative(pinned.destination, file);
		assert.ok(pinned.files[relative], 'Producer missing from provider lock: ' + file);
		assert.equal(createHash('sha256').update(readFileSync(path.join(root, file))).digest('hex'), pinned.files[relative], 'WooCommerce producer differs from the reviewed provider: ' + file);
	}
	const extract = String.raw`<?php
require $argv[1] . '/vendor/autoload.php';
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
function hook_name(PhpParser\Node $node): ?string {
    if ($node instanceof PhpParser\Node\Scalar\String_ || $node instanceof PhpParser\Node\InterpolatedStringPart || $node instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
        return $node->value;
    }
    if ($node instanceof PhpParser\Node\Expr\Variable && is_string($node->name)) {
        return '{$' . $node->name . '}';
    }
    if ($node instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
        $a = hook_name($node->left); $b = hook_name($node->right);
        return null !== $a && null !== $b ? $a . $b : null;
    }
    if ($node instanceof PhpParser\Node\Scalar\InterpolatedString || $node instanceof PhpParser\Node\Scalar\Encapsed) {
        $out = '';
        foreach ($node->parts as $part) {
            $piece = hook_name($part);
            if (null === $piece) { return null; }
            $out .= $piece;
        }
        return $out;
    }
    if ($node instanceof PhpParser\Node\Expr\PropertyFetch && $node->name instanceof PhpParser\Node\Identifier) {
        $base = hook_name($node->var);
        return null !== $base && str_starts_with($base, '{$') && str_ends_with($base, '}')
            ? substr($base, 0, -1) . '->' . $node->name->toString() . '}'
            : null;
    }
    if ($node instanceof PhpParser\Node\Expr\ArrayDimFetch && null !== $node->dim) {
        $base = hook_name($node->var);
        $dim  = hook_name($node->dim);
        if (null !== $base && null !== $dim && str_starts_with($base, '{$') && str_ends_with($base, '}')) {
            $key = ($node->dim instanceof PhpParser\Node\Scalar\String_) ? "'" . $dim . "'" : $dim;
            return substr($base, 0, -1) . '[' . $key . ']}';
        }
    }
    return null;
}
$result = [];
foreach (json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR) as $file) {
    $nodes = $parser->parse(file_get_contents($argv[1] . '/' . $file));
    $calls = (new PhpParser\NodeFinder())->findInstanceOf($nodes, PhpParser\Node\Expr\FuncCall::class);
    foreach ($calls as $call) {
        if (!$call->name instanceof PhpParser\Node\Name || !in_array($call->name->toString(), ['do_action', 'apply_filters'], true) || !$call->args) { continue; }
        $name = hook_name($call->args[0]->value);
        if (null !== $name) { $result[] = ['file'=>$file, 'name'=>$name, 'type'=>'do_action' === $call->name->toString() ? 'action' : 'filter', 'args'=>count($call->args)-1, 'line'=>$call->getStartLine()]; }
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
`;
	const parsed = spawnSync('php', ['-r', extract.replace(/^<\?php\s*/, ''), root, JSON.stringify([...new Set(contracts.map(item => item[1]))])], {
		cwd: root, encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024
	});
	assert.equal(parsed.status, 0, parsed.stdout + parsed.stderr);
	const producers = JSON.parse(parsed.stdout);
	for (const [name, file, type, types] of contracts) {
		const matches = metadata.filter(hook => hook.name === name);
		assert.equal(matches.length, 1, name);
		const hook = matches[0];
		assert.equal(hook.file, file, name);
		assert.equal(hook.type, type, name);
		assert.equal(hook.args, types.length, name);
		assert.deepEqual(hook.doc.tags.filter(tag => tag.name === 'param').map(tag => tag.types), types.map(value => value.split('|')), name);
		assert.ok(producers.some(producer => producer.file === file && producer.name === name && producer.type === type && producer.args === types.length), 'Producer AST mismatch: ' + name);
	}
});

test('metadata preserves valid callbacks, rejects wrong types and hook typos with the real Psalm configuration', () => {
	const directory = mkdtempSync(path.join(tmpdir(), 'akb-woocommerce-hook-probes-'));
	try {
		const fixtures = {
			positive: `<?php
declare(strict_types=1);
add_action('init', static function (): void { echo 'core hook'; }, 10, 0);
add_filter('woocommerce_get_query_vars', static fn(array $vars): array => $vars);
add_filter('woocommerce_account_menu_items', static fn(array $items, array $endpoints): array => array_merge($items, $endpoints), 10, 2);
add_action('woocommerce_account_collection_endpoint', static function (mixed $value): void { echo get_debug_type($value); });
add_filter('woocommerce_endpoint_collection_title', static fn(string $title, string $endpoint, string $action): string => $title . $endpoint . $action, 10, 3);
add_action('woocommerce_new_product', static function (int $id, WC_Product $product): void { echo $id . $product->get_id(); }, 10, 2);
add_action('woocommerce_update_product', static function (int $id, WC_Product $product): void { echo $id . $product->get_id(); }, 10, 2);
add_action('woocommerce_product_set_stock', static function (WC_Product $product): void { echo $product->get_id(); });
add_action('woocommerce_variation_set_stock', static function (WC_Product $product): void { echo $product->get_id(); });
add_action('woocommerce_product_set_stock_status', static function (int $id, string $status, WC_Product $product): void { echo $id . $status . $product->get_id(); }, 10, 3);
add_action('woocommerce_variation_set_stock_status', static function (int $id, string $status, WC_Product $product): void { echo $id . $status . $product->get_id(); }, 10, 3);
`,
			wrong_type: `<?php
declare(strict_types=1);
add_action('woocommerce_product_set_stock', static function (int $product): void { echo $product; });
`,
			hook_typo: `<?php
declare(strict_types=1);
add_action('woocommerce_product_set_stok', static function (WC_Product $product): void { echo $product->get_id(); });
`
		};
		for (const [name, source] of Object.entries(fixtures)) {
			const filename = path.join(directory, name + '.php');
			const config = path.join(directory, name + '.xml');
			writeFileSync(filename, source);
			const ignored = originalConfig.match(/<ignoreFiles>[\s\S]*?<\/ignoreFiles>/)?.[0];
			assert.ok(ignored);
			// Preserve every analyzer setting; only scope and path resolution change.
			const probeConfig = originalConfig
				.replace(/<projectFiles>[\s\S]*?<\/projectFiles>/, `<projectFiles><file name="${filename}" />${ignored}</projectFiles>`)
				.replace(/((?:name|autoloader)=")([^"]+)(")/g, (all, before, value, after) => before + (path.isAbsolute(value) ? value : path.join(root, value)) + after)
				.replace('vendor/vimeo/psalm/config.xsd', path.join(root, 'vendor/vimeo/psalm/config.xsd'));
			writeFileSync(config, probeConfig);
			const result = spawnSync(path.join(root, 'vendor/bin/psalm'), ['--config=' + config, '-m', '--threads=1', '--no-progress', '--taint-analysis'], {
				cwd: root, encoding: 'utf8', timeout: 120000, maxBuffer: 8 * 1024 * 1024, env: { ...process.env, NO_COLOR: '1' }
			});
			assert.equal(result.error, undefined, result.error?.message);
			const output = result.stdout + result.stderr;
			if (name === 'positive') {
				assert.equal(result.status, 0, output);
				assert.ok(!output.includes('ERROR:'), output);
			} else {
				assert.equal(result.status, 2, output);
				const expected = name === 'wrong_type' ? 'InvalidArgument' : 'HookNotFound';
				assert.deepEqual([...output.matchAll(/^ERROR: (\w+) -/gm)].map(match => match[1]), [expected], output);
				assert.ok(output.includes(path.basename(filename) + ':'), output);
			}
		}
	} finally {
		rmSync(directory, { recursive: true, force: true });
		assert.equal(readFileSync(configFile, 'utf8'), originalConfig, 'root Psalm configuration changed during probe');
		assert.equal(readFileSync(metadataFile, 'utf8'), originalMetadata, 'metadata changed during probe');
	}
});

test('real metadata seal rejects exact tampering causes without hiding other pending gates', () => {
	const verifier = path.join(root, 'tools/verify-devsecops-seal.py');
	const originalVerifier = readFileSync(verifier);
	const parsed = spawnSync('python3', ['-c',
		'import ast,json,sys; tree=ast.parse(open(sys.argv[1]).read()); values=[ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=="SEALED_HASHES" for t in n.targets)]; assert len(values)==1; print(json.dumps(values[0]))',
		verifier], { cwd: root, encoding: 'utf8', timeout: 30000 });
	assert.equal(parsed.status, 0, parsed.stdout + parsed.stderr);
	const hashes = JSON.parse(parsed.stdout);
	for (const filename of ['psalm.xml', 'tools/psalm/woocommerce/hooks.json']) {
		assert.match(hashes[filename], /^[a-f0-9]{64}$/);
		assert.equal(createHash('sha256').update(readFileSync(path.join(root, filename))).digest('hex'), hashes[filename], 'Authorized metadata must match its sealed hash: ' + filename);
	}
	if (process.platform === 'darwin') {
		const protectedFiles = spawnSync('python3', ['-c',
			'import json,os,stat,sys; print(json.dumps([{"file":p,"mode":stat.S_IMODE(os.stat(p).st_mode),"immutable":bool(os.stat(p).st_flags & stat.UF_IMMUTABLE)} for p in sys.argv[1:]]))',
			verifier, configFile, metadataFile], { cwd: root, encoding: 'utf8', timeout: 30000 });
		assert.equal(protectedFiles.status, 0, protectedFiles.stdout + protectedFiles.stderr);
		for (const file of JSON.parse(protectedFiles.stdout)) {
			assert.equal(file.mode, 0o444, 'Reviewed metadata protection mode: ' + file.file);
			assert.equal(file.immutable, true, 'Reviewed metadata kernel protection: ' + file.file);
		}
	}
	const directory = mkdtempSync(path.join(tmpdir(), 'akb-woocommerce-metadata-seal-'));
	try {
		const verify = target => {
			const result = spawnSync('python3', [verifier, '--root', target], { cwd: root, encoding: 'utf8', timeout: 30000 });
			assert.equal(result.error, undefined, result.error?.message);
			assert.ok([0, 1].includes(result.status), result.stdout + result.stderr);
			return { ...result, issues: result.stderr.split('\n').filter(Boolean).sort() };
		};
		for (const relative of Object.keys(hashes)) {
			const filename = path.resolve(directory, relative);
			assert.ok(filename.startsWith(directory + path.sep), 'Sealed path must remain inside the temporary tree');
			mkdirSync(path.dirname(filename), { recursive: true });
			// Create fresh writable bytes; do not copy kernel flags or change a real file.
			writeFileSync(filename, readFileSync(path.join(root, relative)));
		}
		// This optional real input participates in the verifier's unchanged semantic checks.
		if (existsSync(path.join(root, 'phpstan-stubs.php'))) writeFileSync(path.join(directory, 'phpstan-stubs.php'), readFileSync(path.join(root, 'phpstan-stubs.php')));
		const real = verify(root);
		const before = verify(directory);
		assert.equal(before.status, real.status, 'Temporary tree must preserve every existing gate failure');
		assert.deepEqual(before.issues, real.issues);
		const rejectOnly = (relative, absent = false) => {
			const filename = path.join(directory, relative);
			const original = readFileSync(filename);
			let expected;
			if (absent) {
				unlinkSync(filename);
				expected = 'VIOLACIÓN DE CANDADO: falta archivo crítico sellado: ' + relative;
			} else {
				writeFileSync(filename, Buffer.concat([original, Buffer.from('\n')]));
				const actual = createHash('sha256').update(readFileSync(filename)).digest('hex');
				expected = `VIOLACIÓN DE CANDADO: ${relative} fue modificado sin autorización (SHA-256 actual ${actual.slice(0, 12)}… != sellado ${hashes[relative].slice(0, 12)}…).`;
			}
			const after = verify(directory);
			assert.equal(after.status, 1, after.stdout + after.stderr);
			assert.deepEqual(after.issues, [...before.issues, expected].sort(), 'Only the exact metadata violation must be added');
			writeFileSync(filename, original);
			assert.deepEqual(verify(directory).issues, before.issues, 'Restoring temporary bytes must return to the original diagnostics');
		};
		rejectOnly('psalm.xml');
		rejectOnly('tools/psalm/woocommerce/hooks.json');
		rejectOnly('tools/psalm/woocommerce/hooks.json', true);
		assert.deepEqual(verify(root).issues, real.issues, 'Real gates must remain unchanged');
	} finally {
		rmSync(directory, { recursive: true, force: true });
		assert.deepEqual(readFileSync(verifier), originalVerifier, 'Verifier must remain unchanged');
		assert.equal(readFileSync(configFile, 'utf8'), originalConfig, 'Root Psalm configuration must remain unchanged');
		assert.equal(readFileSync(metadataFile, 'utf8'), originalMetadata, 'Real metadata must remain unchanged');
	}
});
