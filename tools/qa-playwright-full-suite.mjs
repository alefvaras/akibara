import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium, expect } from './akibara-e2e/node_modules/@playwright/test/index.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const seriesPath = '/serie/20th-century-boys/';

export async function navigate(page, url) {
	const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
	assert.equal(response?.status(), 200, `HTTP de ${url}`);
	const requested = new URL(url);
	const actual = new URL(page.url());
	assert.equal(actual.origin, requested.origin, 'Origen inesperado');
	assert.equal(actual.pathname, requested.pathname, 'Ruta final inesperada');
	await expect(page.locator('main').first()).toBeVisible();
}

export async function assertCanonical(page, url) {
	const canonical = page.locator('link[rel="canonical"]');
	await expect(canonical).toHaveCount(1);
	await expect(canonical).toHaveAttribute('href', url);
}

function schemaTypes(node) {
	const types = node?.['@type'];
	return Array.isArray(types) ? types : typeof types === 'string' ? [types] : [];
}

function schemaNodes(value, nodes = []) {
	if (Array.isArray(value)) {
		value.forEach(item => schemaNodes(item, nodes));
		return nodes;
	}
	assert.ok(value && typeof value === 'object', 'Grafo JSON-LD inválido');
	if (Object.hasOwn(value, '@graph')) {
		assert.ok(Array.isArray(value['@graph']), '@graph debe ser una lista');
		value['@graph'].forEach(item => schemaNodes(item, nodes));
		return nodes;
	}
	nodes.push(value);
	return nodes;
}

function assertAbsoluteInternalUrl(value, origin, label) {
	assert.equal(typeof value, 'string', `${label} requiere URL absoluta`);
	const parsed = new URL(value);
	assert.equal(parsed.origin, origin, `${label} debe usar el origen canónico`);
}

function assertListItems(node, origin, label) {
	const items = node?.itemListElement;
	assert.ok(Array.isArray(items) && items.length >= 2, `${label} requiere al menos dos elementos`);
	const breadcrumb = schemaTypes(node).includes('BreadcrumbList');
	items.forEach((entry, index) => {
		assert.ok(schemaTypes(entry).includes('ListItem'), `${label} ${index + 1} debe ser ListItem`);
		assert.equal(Number(entry.position), index + 1, `${label} debe tener posiciones contiguas`);
		const item = entry.item;
		const objectItem = item && typeof item === 'object' && !Array.isArray(item);
		const name = [entry.name, objectItem ? item.name : undefined].find(value => typeof value === 'string' && value.trim());
		assert.ok(name, `${label} ${index + 1} requiere nombre`);
		const hasItem = Object.hasOwn(entry, 'item');
		if (breadcrumb && !hasItem) {
			assert.equal(index, items.length - 1, `${label} requiere item en los elementos intermedios`);
			if (entry.url !== undefined) assertAbsoluteInternalUrl(entry.url, origin, `${label} ${index + 1}`);
			return;
		}
		if (hasItem) assert.ok(typeof item === 'string' || objectItem, `${label} ${index + 1} requiere un item válido`);
		const url = typeof item === 'string' ? item : objectItem ? item.url || item['@id'] : entry.url;
		assertAbsoluteInternalUrl(url, origin, `${label} ${index + 1}`);
	});
}

export async function assertSchemas(page, { pageType, canonical, product } = {}) {
	const raw = await page.locator('script[type="application/ld+json"]').allTextContents();
	assert.ok(raw.length > 0, 'Falta JSON-LD');
	const schemas = raw.map((text, index) => {
		try {
			return JSON.parse(text);
		} catch {
			throw new Error(`JSON-LD inválido en bloque ${index + 1}`);
		}
	});
	const graph = schemas.flatMap(schema => schemaNodes(schema));
	assert.ok(graph.length > 0, 'JSON-LD sin nodos');
	const origin = canonical ? new URL(canonical).origin : new URL(page.url()).origin;
	const breadcrumb = graph.find(node => schemaTypes(node).includes('BreadcrumbList'));
	assert.ok(breadcrumb, 'Falta BreadcrumbList');
	assertListItems(breadcrumb, origin, 'BreadcrumbList.itemListElement');

	if (pageType === 'directory' || pageType === 'series') {
		const pageNode = graph.find(node => schemaTypes(node).some(type => ['WebPage', 'CollectionPage'].includes(type)));
		assert.ok(pageNode, 'Falta WebPage o CollectionPage');
		assert.equal(pageNode.url, canonical, 'La página JSON-LD debe coincidir con su canonical');
		const entityId = pageNode.mainEntity?.['@id'];
		assert.ok(typeof entityId === 'string' && graph.some(node => node['@id'] === entityId), 'mainEntity debe apuntar a un nodo del grafo');
	}

	if (pageType === 'directory') {
		const list = graph.find(node => schemaTypes(node).includes('ItemList'));
		assert.ok(list, 'El directorio requiere ItemList');
		assertListItems(list, origin, 'ItemList.itemListElement');
	}

	if (pageType === 'product') {
		assert.ok(product && product.url === canonical, 'El producto esperado debe venir del snapshot WooCommerce');
		const productNode = graph.find(node => schemaTypes(node).includes('Product'));
		assert.ok(productNode, 'Falta Product');
		assert.ok(typeof productNode.name === 'string' && productNode.name.trim(), 'Product requiere nombre');
		const pageRef = productNode.mainEntityOfPage?.['@id'] || productNode.mainEntityOfPage;
		assertAbsoluteInternalUrl(pageRef, origin, 'Product.mainEntityOfPage');
		assert.equal(new URL(pageRef).pathname, new URL(canonical).pathname, 'Product debe pertenecer a la PDP canonical');
		const offer = Array.isArray(productNode.offers) ? productNode.offers[0] : productNode.offers;
		assert.ok(offer && schemaTypes(offer).includes('Offer'), 'Product requiere Offer');
		assert.equal(offer.url, canonical, 'Offer.url debe coincidir con la PDP canonical');
		assert.equal(offer.priceCurrency, product.currency, 'Offer.priceCurrency debe coincidir con WooCommerce');
		assert.ok((typeof offer.price === 'string' || typeof offer.price === 'number') && String(offer.price).trim() !== '' && Number.isFinite(Number(offer.price)) && Number(offer.price) >= 0, 'Offer.price debe ser un número no negativo');
		assert.equal(Number(offer.price), Number(product.price), 'Offer.price debe coincidir con el precio leído de WooCommerce');
	}

	assert.ok(['directory', 'series', 'product'].includes(pageType), `Contexto JSON-LD desconocido: ${pageType}`);
}

export async function assertLatestVolume(page, expected) {
	const latest = page.locator('.akb-series-latest');
	await expect(latest).toBeVisible();
	await expect(latest).toHaveText(new RegExp(`^Último tomo:\\s*${expected.volume}\\s*→$`));
	await expect(latest).toHaveAttribute('href', expected.url);
}

export async function assertTouchTarget(locator) {
	await expect(locator).toBeVisible();
	const box = await locator.boundingBox();
	assert.ok(box && box.width >= 44 && box.height >= 44, `Área táctil insuficiente ${box?.width}×${box?.height}`);
}

export async function assertDialogDismissTarget(dialog, close) {
	await expect(dialog).toBeVisible();
	await assertTouchTarget(close);
	const geometry = await close.evaluate(node => {
		const rect = node.getBoundingClientRect();
		const dialogRect = node.closest('dialog')?.getBoundingClientRect();
		const x = rect.x + rect.width / 2;
		const y = rect.y + rect.height / 2;
		return {
			hit: node.contains(document.elementFromPoint(x, y)),
			x: rect.x,
			y: rect.y,
			right: rect.right,
			bottom: rect.bottom,
			dialogLeft: dialogRect?.left,
			dialogRight: dialogRect?.right,
			width: document.documentElement.clientWidth,
			viewportHeight: document.documentElement.clientHeight,
		};
	});
	assert.ok(geometry.hit, 'El centro del cierre debe recibir el clic');
	assert.ok(geometry.x >= 0 && geometry.y >= 0 && geometry.right <= geometry.width && geometry.bottom <= geometry.viewportHeight, 'El cierre debe caber dentro del viewport');
	assert.ok(geometry.dialogLeft >= 0 && geometry.dialogRight <= geometry.width, 'El diálogo debe caber dentro del viewport');
}

export async function assertGeometry(page) {
	const size = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
	assert.ok(size.scroll <= size.client, `Desbordamiento ${size.scroll} > ${size.client}`);
}

export async function assertDirectoryFilters(page) {
	const input = page.locator('#akb-series-directory-input');
	await expect(input).toHaveAttribute('placeholder', 'Escribe la serie, autor o edición');
	const cards = page.locator('li.akb-series-directory__item');
	const all = await cards.evaluateAll(items => items.map(item => ({ id: item.dataset.akbSerieId, brand: item.dataset.akbEditorial, search: item.dataset.akbSerie || '' })));
	assert.ok(all.length > 0, 'Directorio vacío');
	assert.ok(all.every(item => /^[1-9]\d*$/.test(item.id)), 'Identificador de serie inválido');
	const target = cards.filter({ hasText: '20th Century Boys' });
	const unrelatedId = all.find(item => !item.search.includes('urasawa'))?.id;
	assert.ok(unrelatedId, 'Falta un control negativo independiente del autor');
	const unrelated = page.locator(`[data-akb-serie-id="${unrelatedId}"]`);
	await expect(target).toBeVisible();
	await expect(unrelated).toBeVisible();
	const dataRequests = [];
	const listener = request => {
		if (/\/wp-json\/|\/admin-ajax\.php/.test(request.url())) dataRequests.push(request.url());
	};
	page.on('request', listener);
	try {
		await input.fill('Urasawa');
		await expect(target).toBeVisible();
		await expect(unrelated).toBeHidden();
		await input.fill('');
		await expect(page.locator('li.akb-series-directory__item:visible')).toHaveCount(all.length);
		const chip = page.locator('button[data-akb-filter-editorial]:not([data-akb-filter-editorial=""])').first();
		if (!(await chip.isVisible())) await page.locator('[data-akb-toggle-facets]').click();
		await expect(chip).toBeVisible();
		const brand = await chip.getAttribute('data-akb-filter-editorial');
		const expectedIds = all.filter(item => item.brand === brand).map(item => item.id).sort();
		assert.ok(expectedIds.length > 0 && expectedIds.length < all.length, 'La faceta carece de control positivo/negativo');
		await chip.click();
		await expect(chip).toHaveAttribute('aria-pressed', 'true');
		await expect.poll(() => page.locator('li.akb-series-directory__item:visible').evaluateAll(items => items.map(item => item.dataset.akbSerieId).sort())).toEqual(expectedIds);
		await page.locator('button[data-akb-filter-editorial=""]').click();
		await expect(page.locator('li.akb-series-directory__item:visible')).toHaveCount(all.length);
		assert.deepEqual(dataRequests, [], 'El filtro reactivo pidió datos adicionales');
	} finally {
		page.off('request', listener);
	}
}

export async function assertAutocomplete(page) {
	const input = page.locator('#akb-search-input, input[name="s"].akb-search__input').first();
	await expect(input).toBeVisible();
	await input.fill('Chainsaw');
	const panelId = await input.getAttribute('aria-controls');
	assert.ok(panelId, 'Falta relación combobox/listbox');
	const panel = page.locator(`[id="${panelId}"]`);
	await expect(panel).toHaveAttribute('role', 'listbox');
	await expect(panel.getByRole('option').first()).toBeVisible();
	await expect(panel).toContainText(/Chainsaw/i);
	await input.press('Escape');
	await expect(panel).toBeHidden();
}

export async function assertNoOwnershipControls(page) {
	const selectors = await page.locator('[data-akb-pdp-collection-product], .akb-card-shelf-cb, [data-akb-series-collection], .akb-pdp-shelve').count();
	assert.equal(selectors, 0, 'No debe haber controles de propiedad en superficies públicas');
	const checkboxes = await page.getByRole('checkbox', { name: /Tengo este tomo|Marcar en mi estantería/i }).count();
	assert.equal(checkboxes, 0, 'No debe haber casillas de propiedad en superficies públicas');
	assert.equal(await page.evaluate(() => Boolean(window.akbCollectionBadges)), false, 'Se cargó el hidratador global de propiedad');
}

export function assertGuestCollectionData(data) {
	assert.ok(data && data.state && data.catalog && Array.isArray(data.catalog.series), 'Falta el estado público de invitado');
	assert.equal(data.state.user_id, 0, 'La colección pública no debe identificar a un usuario');
	for (const key of ['manual_ids', 'purchased_ids', 'historical_products', 'reviews']) {
		assert.deepEqual(data.state[key], [], `El invitado no debe recibir datos privados: ${key}`);
	}
	assert.equal(data.state.tier, null, 'El invitado no debe recibir el nivel de Rewards de una cuenta');
	assert.ok(data.catalog.series.length > 0, 'El invitado necesita el catálogo público');
	const editions = data.catalog.series.filter(item => Array.isArray(item.product_ids) && item.product_ids.length > 1);
	assert.ok(editions.length > 0, 'Falta una edición para probar el marcado invitado');
	const productById = new Map((data.catalog.products || []).map(item => [item.id, item]));
	return editions.find(edition => edition.product_ids.some(id => productById.get(id)?.stock_status === 'outofstock')) || editions[0];
}

export async function assertGuestCollection(page, url) {
	const requests = [];
	const listener = request => { if (['fetch', 'xhr'].includes(request.resourceType())) requests.push(request.url()); };
	page.on('request', listener);
	try {
		await navigate(page, url);
		const app = page.locator('[data-akb-collection-app]');
		await expect(app).toBeVisible();
		const data = await page.evaluate(() => window.akbCollection);
		const edition = assertGuestCollectionData(data);
		const robots = page.locator('meta[name="robots"]');
		await expect(robots).toHaveCount(1);
		assert.ok((await robots.getAttribute('content')).split(',').map(value => value.trim()).includes('noindex'), 'La colección personal debe permanecer noindex');
		await app.locator('#akb-collection-search').fill(edition.name);
		await app.locator(`[data-action="open"][data-id="${edition.id}"]`).click();
		const toggle = app.locator('[data-action="toggle"]').first();
		await expect(toggle).toBeVisible();
		const id = await toggle.getAttribute('data-id');
		await toggle.click();
		await expect(app.locator(`[data-action="toggle"][data-id="${id}"]`)).toContainText('Quitar');
		await app.locator(`[data-action="toggle"][data-id="${id}"]`).click();
		await expect(app.locator(`[data-action="toggle"][data-id="${id}"]`)).toContainText('Lo tengo');
		const notice = app.locator('.akb-notify-link').first();
		if (data.bis_enabled) {
			await expect(notice).toBeVisible();
			await notice.click();
			const dialog = page.locator('#akb-bis-dialog');
			await expect(dialog).toBeVisible();
			const close = dialog.getByRole('button', {name: 'Cerrar', exact: true});
			await assertDialogDismissTarget(dialog, close);
			await close.click();
			await expect(dialog).toBeHidden();
			await expect(notice).toBeFocused();
			await notice.click();
			await page.keyboard.press('Escape');
			await expect(dialog).toBeHidden();
			await expect(notice).toBeFocused();
		}
		assert.deepEqual(requests, [], 'La navegación y el marcado invitado solicitaron datos adicionales');
		await assertGeometry(page);
	} finally { page.off('request', listener); }
}

function catalogSnapshot(baseUrl) {
	const local = new URL(baseUrl).hostname === 'localhost' || new URL(baseUrl).hostname === '127.0.0.1';
	assert.ok(local || baseUrl === 'https://akibara.cl', 'Destino de QA no reconocido');
	const php = `<?php
$result = ['home' => untrailingslashit(home_url('/')), 'shop' => wc_get_page_permalink('shop')];
foreach (['one-piece', '20th-century-boys'] as $slug) {
    $term = get_term_by('slug', $slug, 'pa_serie');
    if (!$term) { WP_CLI::error('Falta serie requerida ' . $slug); }
    $ids = get_posts(['post_type'=>'product', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids', 'tax_query'=>[['taxonomy'=>'pa_serie','field'=>'term_id','terms'=>$term->term_id]]]);
    $rows = [];
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product || !$product->is_visible() || get_post_field('post_password', $id) !== '' || !preg_match('/\\s(\\d+)(?:\\s*\\([^)]*\\))?$/u', $product->get_name(), $match)) { continue; }
        $stored_price = $product->get_price();
        $display_price = wc_get_price_to_display($product);
        if ($product->is_type('simple') && $stored_price !== '' && is_numeric($stored_price) && is_numeric($display_price)) {
            $rows[] = ['volume'=>(int)$match[1], 'url'=>get_permalink($id), 'stock'=>$product->get_stock_status(), 'type'=>$product->get_type(), 'price'=>(string)$display_price, 'currency'=>get_woocommerce_currency()];
        }
    }
    usort($rows, static fn($a,$b) => $a['volume'] <=> $b['volume']);
    $result[$slug] = $rows;
}
echo 'AKB_QA_FIXTURE=' . wp_json_encode($result) . PHP_EOL;
`;
	const executable = local ? 'docker' : 'ssh';
	const args = local
		? ['exec', '-i', 'akibara-cron-1', 'wp', 'eval-file', '-']
		: ['-o', 'BatchMode=yes', process.env.AKB_SSH_HOST || 'akibara', 'cd ~/domains/akibara.cl/public_html && wp eval-file -'];
	const result = spawnSync(executable, args, { input: php, cwd: root, encoding: 'utf8', timeout: 60000 });
	assert.equal(result.status, 0, `No se pudo leer el catálogo para QA: ${result.stderr}`);
	const line = result.stdout.split('\n').find(value => value.startsWith('AKB_QA_FIXTURE='));
	assert.ok(line, 'Falta snapshot del catálogo');
	const snapshot = JSON.parse(line.slice('AKB_QA_FIXTURE='.length));
	assert.equal(snapshot.home, baseUrl, 'Snapshot de otro entorno');
	assert.ok(snapshot['one-piece'].length > 0, 'One Piece no ofrece un control de último tomo');
	assert.ok(snapshot['20th-century-boys'].length > 0, 'Serie de prueba vacía');
	return snapshot;
}

export async function run({ baseUrl = process.env.AKB_BASE_URL || 'https://akibara.cl' } = {}) {
	baseUrl = new URL(baseUrl).origin;
	const catalog = catalogSnapshot(baseUrl);
	const volumes = catalog['20th-century-boys'];
	const available = volumes.find(item => item.stock === 'instock' && item.type === 'simple' && item.price !== '');
	const soldOut = volumes.find(item => item.stock === 'outofstock' && item.type === 'simple' && item.price !== '');
	assert.ok(available && soldOut, 'Se requieren productos publicados disponibles y agotados para la certificación');
	const browser = await chromium.launch({ executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
	const failures = [];
	let passed = 0;
	async function check(name, action) {
		try {
			await action();
			passed++;
			console.log(`[PASS] ${name}`);
		} catch (error) {
			failures.push(name);
			console.error(`[FAIL] ${name}: ${error.message}`);
		}
	}
	console.log(`QA adversarial en ${baseUrl}`);
	try {
		const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'AkibaraQA/2.0' });
		const page = await context.newPage();
		const pageErrors = [];
		page.on('pageerror', error => pageErrors.push(error.message));
		await check('Directorio, autor y faceta reversible sin nuevas peticiones', async () => {
			await navigate(page, baseUrl + '/series/');
			await assertCanonical(page, baseUrl + '/series/');
			await assertSchemas(page, { pageType: 'directory', canonical: baseUrl + '/series/' });
			await assertDirectoryFilters(page);
		});
		await check('Serie, ancla, agotados y enlaces internos', async () => {
			await navigate(page, baseUrl + seriesPath);
			const link = page.locator('a.akb-series-summary__more');
			await expect(link).toHaveAttribute('href', '#ficha-de-la-serie');
			await link.click();
			const facts = page.locator('section#ficha-de-la-serie');
			await expect(facts).toBeInViewport();
			const shown = await facts.locator('.akb-series-facts__vols').innerText();
			assert.deepEqual((shown.match(/\d+/g) || []).map(Number), volumes.filter(item => item.stock === 'outofstock').map(item => item.volume));
			await expect(facts.locator('a[href*="/marca/"]').first()).toBeVisible();
			await expect(facts.locator('a[href*="/autor/"]').first()).toBeVisible();
			const links = await facts.locator('a').evaluateAll(items => items.map(item => item.href));
			assert.ok(links.every(url => new URL(url).origin === baseUrl), 'Enlace editorial externo');
		});
		await check('Último tomo cotejado con catálogo publicado', async () => {
			await navigate(page, baseUrl + '/serie/one-piece/');
			await assertCanonical(page, baseUrl + '/serie/one-piece/');
			await assertSchemas(page, { pageType: 'series', canonical: baseUrl + '/serie/one-piece/' });
			await assertLatestVolume(page, catalog['one-piece'].at(-1));
		});
		await check('Producto disponible, compra activa y especificaciones', async () => {
			await navigate(page, available.url);
			await assertCanonical(page, available.url);
			await assertSchemas(page, { pageType: 'product', canonical: available.url, product: available });
			const button = page.locator('.summary .single_add_to_cart_button');
			await expect(button).toBeVisible();
			await expect(button).toBeEnabled();
			await expect(page.locator('.akb-pdp-specs-grid').first()).toBeVisible();
			assert.ok(!/preventa|a pedido|onbackorder/i.test(await page.locator('main').innerText()), 'Vocabulario de disponibilidad ajeno al producto disponible');
		});
		await check('Producto agotado, aviso visible y compra ausente', async () => {
			await navigate(page, soldOut.url);
			await assertCanonical(page, soldOut.url);
			await assertSchemas(page, { pageType: 'product', canonical: soldOut.url, product: soldOut });
			await expect(page.locator('#akb-bis-form, .akb-bis-form, [data-akb-bis-container]').first()).toBeVisible();
			await expect(page.locator('.summary .single_add_to_cart_button:visible')).toHaveCount(0);
		});
		await check('Autocompletado visible y cierre con Escape', () => assertAutocomplete(page));
		await check('Canonical, indexación y todos los bloques JSON-LD válidos', async () => {
			await navigate(page, baseUrl + seriesPath);
			await assertCanonical(page, baseUrl + seriesPath);
			const robots = page.locator('meta[name="robots"]');
			await expect(robots).toHaveCount(1);
			const content = (await robots.getAttribute('content')).split(',').map(value => value.trim());
			assert.ok(content.includes('index') && !content.includes('noindex'), 'Robots no permite indexación');
			await assertSchemas(page, { pageType: 'series', canonical: baseUrl + seriesPath });
		});
		const mobile = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
		const mobilePage = await mobile.newPage();
		mobilePage.on('pageerror', error => pageErrors.push(error.message));
		for (const url of [baseUrl + '/series/', baseUrl + seriesPath, available.url, soldOut.url]) {
			await check(`Geometría móvil ${new URL(url).pathname}`, async () => {
				await navigate(mobilePage, url);
				await assertGeometry(mobilePage);
			});
		}
		await check('Área táctil de compra ≥44×44', async () => {
			await navigate(mobilePage, available.url);
			await assertTouchTarget(mobilePage.locator('.summary .single_add_to_cart_button'));
		});
		for (const url of [catalog.shop, baseUrl + seriesPath, available.url, soldOut.url]) {
			await check(`Propiedad concentrada en Mi colección, ${new URL(url).pathname}`, async () => {
				await navigate(page, url);
				await expect(page.locator('li.product, .single-product .summary').first()).toBeVisible();
				await assertNoOwnershipControls(page);
			});
		}
		await check('Ausencia de errores JavaScript', async () => assert.deepEqual(pageErrors, []));
	} finally {
		await browser.close();
	}
	console.log(`RESULTADO ${passed} aprobados, ${failures.length} fallos`);
	assert.equal(failures.length, 0, `QA rechazada: ${failures.join(', ')}`);
	console.log('CERTIFICACIÓN QA ADVERSARIAL APROBADA');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	run().catch(error => {
		console.error(error.message);
		process.exitCode = 1;
	});
}
