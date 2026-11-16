import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { chromium } from './akibara-e2e/node_modules/@playwright/test/index.mjs';
import {
	navigate, assertCanonical, assertSchemas, assertLatestVolume, assertTouchTarget,
	assertDialogDismissTarget, assertGeometry, assertDirectoryFilters, assertAutocomplete,
	assertGuestCollectionData, assertNoOwnershipControls,
} from './qa-playwright-full-suite.mjs';

let browser;
before(async () => {
	browser = await chromium.launch({ executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
	});
after(async () => browser?.close());

async function pageFixture(html, run, viewport = { width: 375, height: 812 }) {
	const page = await browser.newPage({ viewport });
	try {
		await page.setContent(html);
		await run(page);
	} finally {
		await page.close();
	}
}

const canonical = 'https://fixture.invalid/serie/ejemplo/';
const breadcrumb = {
	'@type': 'BreadcrumbList',
	itemListElement: [
		{ '@type': 'ListItem', position: 1, item: { '@id': 'https://fixture.invalid/', name: 'Inicio' } },
		{ '@type': 'ListItem', position: 2, item: { '@id': canonical, name: 'Ejemplo' } },
	],
};
const seriesGraph = [
	breadcrumb,
	{ '@type': ['WebPage', 'CollectionPage'], '@id': canonical + '#webpage', url: canonical, mainEntity: { '@id': canonical + '#series' } },
	{ '@type': 'ComicSeries', '@id': canonical + '#series', name: 'Ejemplo' },
];

function schemaHtml(nodes) {
	return `<script type="application/ld+json">${JSON.stringify({ '@graph': nodes })}</script>`;
}

function productNodes(product) {
	return [
		breadcrumb,
		{ '@type': 'WebPage', '@id': product.url + '#webpage', url: product.url },
		{
			'@type': ['Product', 'Book'],
			'@id': product.url + '#product',
			name: 'Tomo de prueba',
			mainEntityOfPage: { '@id': product.url + '#webpage' },
			offers: { '@type': 'Offer', url: product.url, priceCurrency: product.currency, price: product.price },
		},
	];
}

function schemaTypesForTest(node) {
	const types = node?.['@type'];
	return Array.isArray(types) ? types : [types];
}

test('HTTP 404 with a plausible main cannot pass navigation', () => pageFixture('', async page => {
	await page.route('https://fixture.invalid/**', route => route.fulfill({ status: 404, contentType: 'text/html', body: '<main>Serie y recomendaciones</main>' }));
	await assert.rejects(navigate(page, 'https://fixture.invalid/serie/ejemplo/'), /HTTP/);
}));

test('navigation rejects cross-origin redirects and path substitutions', async () => {
	await pageFixture('<main>Serie</main>', async page => {
		page.goto = async () => ({ status: () => 200 });
		page.url = () => 'https://other.invalid/serie/ejemplo/';
		await assert.rejects(navigate(page, 'https://fixture.invalid/serie/ejemplo/'), /Origen inesperado/);
	});
	await pageFixture('<main>Serie</main>', async page => {
		page.goto = async () => ({ status: () => 200 });
		page.url = () => 'https://fixture.invalid/serie/otra/';
		await assert.rejects(navigate(page, 'https://fixture.invalid/serie/ejemplo/'), /Ruta final inesperada/);
	});
});

test('canonical accepts the expected URL and rejects a different URL', () => pageFixture(`<link rel="canonical" href="${canonical}">`, async page => {
	await assertCanonical(page, canonical);
	await assert.rejects(assertCanonical(page, 'https://fixture.invalid/serie/otra/'));
}));

test('schema graph requires valid breadcrumb and page/collection structure', () => pageFixture(schemaHtml(seriesGraph), async page => {
	await assertSchemas(page, { pageType: 'series', canonical });
	const malformed = [...seriesGraph];
	malformed[0] = { ...breadcrumb, itemListElement: [{ '@type': 'Thing', position: 1, item: { name: 'Inicio', url: 'https://fixture.invalid/' } }] };
	await page.locator('script[type="application/ld+json"]').evaluate((node, data) => { node.textContent = JSON.stringify({ '@graph': data }); }, malformed);
	await assert.rejects(assertSchemas(page, { pageType: 'series', canonical }), /al menos dos elementos/);
}));

test('Breadcrumb contract accepts item URL strings with the name on ListItem', () => pageFixture('', async page => {
	const nodes = structuredClone(seriesGraph);
	nodes[0].itemListElement = [
		{ '@type': 'ListItem', position: 1, name: 'Inicio', item: 'https://fixture.invalid/' },
		{ '@type': 'ListItem', position: 2, name: 'Ejemplo', item: canonical },
	];
	await page.setContent(schemaHtml(nodes));
	await assertSchemas(page, { pageType: 'series', canonical });
}));

test('Breadcrumb contract accepts an absent item on its final named ListItem', () => pageFixture('', async page => {
	const nodes = structuredClone(seriesGraph);
	nodes[0].itemListElement[1] = { '@type': 'ListItem', position: 2, name: 'Ejemplo' };
	await page.setContent(schemaHtml(nodes));
	await assertSchemas(page, { pageType: 'series', canonical });
}));

test('Breadcrumb contract rejects intermediate omissions, missing names, wrong types and explicit null items', () => pageFixture('', async page => {
	for (const mutate of [
		nodes => { nodes[0].itemListElement[0] = { '@type': 'ListItem', position: 1, name: 'Inicio' }; },
		nodes => { nodes[0].itemListElement[0].item = { '@id': 'https://fixture.invalid/' }; },
		nodes => { nodes[0].itemListElement[1] = { '@type': 'ListItem', position: 2 }; },
		nodes => { nodes[0].itemListElement[0]['@type'] = 'Thing'; },
		nodes => { nodes[0].itemListElement[1] = { '@type': 'ListItem', position: 2, name: 'Ejemplo', item: null }; },
	]) {
		const nodes = structuredClone(seriesGraph);
		mutate(nodes);
		await page.setContent(schemaHtml(nodes));
		await assert.rejects(assertSchemas(page, { pageType: 'series', canonical }), /BreadcrumbList/);
	}
}));

test('ItemList contract requires a URL even on its final named ListItem', () => pageFixture('', async page => {
	const directoryUrl = 'https://fixture.invalid/series/';
	const nodes = [
		breadcrumb,
		{ '@type': 'CollectionPage', url: directoryUrl, mainEntity: { '@id': directoryUrl + '#list' } },
		{ '@type': 'ItemList', '@id': directoryUrl + '#list', itemListElement: [
			{ '@type': 'ListItem', position: 1, item: { name: 'Serie A', url: 'https://fixture.invalid/serie/a/' } },
			{ '@type': 'ListItem', position: 2, item: { name: 'Serie B' } },
		] },
	];
	await page.setContent(schemaHtml(nodes));
	await assert.rejects(assertSchemas(page, { pageType: 'directory', canonical: directoryUrl }), /URL absoluta/);
}));

test('directory requires a valid ItemList and contextual page entity', () => pageFixture('', async page => {
	const directoryUrl = 'https://fixture.invalid/series/';
	const directory = [
		{ ...breadcrumb, itemListElement: [
			{ '@type': 'ListItem', position: 1, item: { name: 'Inicio', url: 'https://fixture.invalid/' } },
			{ '@type': 'ListItem', position: 2, item: { name: 'Series', url: directoryUrl } },
		] },
		{ '@type': 'CollectionPage', '@id': directoryUrl + '#page', url: directoryUrl, mainEntity: { '@id': directoryUrl + '#list' } },
		{ '@type': 'ItemList', '@id': directoryUrl + '#list', itemListElement: [
			{ '@type': 'ListItem', position: 1, item: { name: 'Serie A', url: 'https://fixture.invalid/serie/a/' } },
			{ '@type': 'ListItem', position: 2, item: { name: 'Serie B', url: 'https://fixture.invalid/serie/b/' } },
		] },
	];
	await page.setContent(schemaHtml(directory));
	await assertSchemas(page, { pageType: 'directory', canonical: directoryUrl });
	directory[2].itemListElement[1].position = 4;
	await page.locator('script[type="application/ld+json"]').evaluate((node, data) => { node.textContent = JSON.stringify({ '@graph': data }); }, directory);
	await assert.rejects(assertSchemas(page, { pageType: 'directory', canonical: directoryUrl }), /posiciones contiguas/);
}));

test('collection page mainEntity must resolve to an actual graph node', () => pageFixture('', async page => {
	const directoryUrl = 'https://fixture.invalid/series/';
	const nodes = [
		breadcrumb,
		{ '@type': 'CollectionPage', url: directoryUrl, mainEntity: { '@id': directoryUrl + '#missing' } },
		{ '@type': 'ItemList', '@id': directoryUrl + '#list', itemListElement: [
			{ '@type': 'ListItem', position: 1, item: { name: 'Serie A', url: 'https://fixture.invalid/serie/a/' } },
			{ '@type': 'ListItem', position: 2, item: { name: 'Serie B', url: 'https://fixture.invalid/serie/b/' } },
		] },
	];
	await page.setContent(schemaHtml(nodes));
	await assert.rejects(assertSchemas(page, { pageType: 'directory', canonical: directoryUrl }), /mainEntity debe apuntar/);
}));

test('series schemas do not require Product nodes, unknown page contexts fail', () => pageFixture(schemaHtml(seriesGraph), async page => {
	await assertSchemas(page, { pageType: 'series', canonical });
	await assert.rejects(assertSchemas(page, { pageType: 'unknown', canonical }), /Contexto JSON-LD desconocido/);
}));

test('PDP Product Offer must match Woo fixture URL, currency and exact price', () => pageFixture('', async page => {
	const product = { url: 'https://fixture.invalid/tomo-1/', currency: 'CLP', price: '29500' };
	await page.setContent(schemaHtml(productNodes(product)));
	await assertSchemas(page, { pageType: 'product', canonical: product.url, product });
	for (const mutate of [
		nodes => { nodes[2].offers.url = 'https://fixture.invalid/tomo-2/'; },
		nodes => { nodes[2].offers.priceCurrency = 'USD'; },
		nodes => { nodes[2].offers.price = '29501'; },
		nodes => { nodes[2].offers.price = true; },
	]) {
		const nodes = productNodes(product);
		mutate(nodes);
		await page.locator('script[type="application/ld+json"]').evaluate((node, data) => { node.textContent = JSON.stringify({ '@graph': data }); }, nodes);
		await assert.rejects(assertSchemas(page, { pageType: 'product', canonical: product.url, product }));
	}
	await assert.rejects(assertSchemas(page, { pageType: 'product', canonical: product.url }), /producto esperado/);
}));

test('PDP schema rejects a missing Product or Offer', () => pageFixture('', async page => {
	const product = { url: 'https://fixture.invalid/tomo-1/', currency: 'CLP', price: '29500' };
	await page.setContent(schemaHtml(productNodes(product).filter(node => !schemaTypesForTest(node).includes('Product'))));
	await assert.rejects(assertSchemas(page, { pageType: 'product', canonical: product.url, product }), /Falta Product/);
	await page.setContent(schemaHtml(productNodes(product).map(node => schemaTypesForTest(node).includes('Product') ? { ...node, offers: undefined } : node)));
	await assert.rejects(assertSchemas(page, { pageType: 'product', canonical: product.url, product }), /Product requiere Offer/);
}));

test('missing JSON-LD, malformed JSON-LD and missing BreadcrumbList fail', async () => {
	await pageFixture('<main>Serie</main>', page => assert.rejects(assertSchemas(page, { pageType: 'series', canonical }), /Falta JSON-LD/));
	await pageFixture('<script type="application/ld+json">{"@type":"BreadcrumbList"}</script><script type="application/ld+json">{broken</script>', page => assert.rejects(assertSchemas(page, { pageType: 'series', canonical }), /inválido en bloque 2/));
	await pageFixture('<script type="application/ld+json">{"@type":"WebPage"}</script>', page => assert.rejects(assertSchemas(page, { pageType: 'series', canonical }), /Falta BreadcrumbList/));
	});

test('numeric latest volume accepts 110 and rejects lexicographic 99', () => pageFixture('<a class="akb-series-latest" href="https://fixture.invalid/one-piece-110/">Último tomo: 110 →</a>', async page => {
	const expected = { volume: 110, url: 'https://fixture.invalid/one-piece-110/' };
	await assertLatestVolume(page, expected);
	await page.locator('a').evaluate(element => { element.textContent = 'Último tomo: 99 →'; element.href = 'https://fixture.invalid/one-piece-99/'; });
	await assert.rejects(assertLatestVolume(page, expected));
}));

test('touch checks enforce both dimensions, visibility, center hit and viewport bounds', async () => {
	await pageFixture('<button style="box-sizing:border-box;width:44px;height:44px;padding:0">+</button>', page => assertTouchTarget(page.locator('button')));
	for (const size of ['width:43px;height:44px', 'width:44px;height:40px']) {
		await pageFixture(`<button style="box-sizing:border-box;${size};padding:0">+</button>`, page => assert.rejects(assertTouchTarget(page.locator('button')), /Área táctil insuficiente/));
	}
	await pageFixture('<button hidden>+</button>', page => assert.rejects(assertTouchTarget(page.locator('button'))));
	const base = '<dialog open style="position:fixed;inset:0;margin:0;padding:0;border:0;width:100vw;height:100vh;box-sizing:border-box"><button id="close" style="position:absolute;top:10px;right:10px;width:44px;height:44px;padding:0">Cerrar</button></dialog>';
	await pageFixture(base, page => assertDialogDismissTarget(page.locator('dialog'), page.locator('#close')));
	await pageFixture(base.replace('width:44px;height:44px', 'width:1px;height:1px'), page => assert.rejects(assertDialogDismissTarget(page.locator('dialog'), page.locator('#close')), /Área táctil insuficiente/));
	await pageFixture(base.replace('right:10px;width:44px;height:44px', 'right:-2px;width:44px;height:44px'), page => assert.rejects(assertDialogDismissTarget(page.locator('dialog'), page.locator('#close')), /viewport/));
	await pageFixture(base.replace('</dialog>', '<div style="position:absolute;top:10px;right:10px;width:44px;height:44px;background:white"></div></dialog>'), page => assert.rejects(assertDialogDismissTarget(page.locator('dialog'), page.locator('#close')), /centro del cierre/));
	});

test('guest collection payload allows empty ownership and rejects private state', () => {
	const data = {
		state: { user_id: 0, manual_ids: [], purchased_ids: [], historical_products: [], reviews: [], tier: null },
		catalog: { series: [{ id: 3, name: 'Serie', product_ids: [21, 22] }], products: [{ id: 21, stock_status: 'instock' }, { id: 22, stock_status: 'outofstock' }] },
	};
	assert.equal(assertGuestCollectionData(data).id, 3);
	for (const alter of [
		copy => { copy.state.user_id = 23; },
		copy => { copy.state.user_id = '0'; },
		copy => { copy.state.manual_ids = [21]; },
		copy => { copy.state.purchased_ids = [21]; },
		copy => { copy.state.historical_products = [{ id: 21 }]; },
		copy => { copy.state.reviews = [{ id: 2 }]; },
		copy => { copy.state.tier = 'oro'; },
	]) {
		const copy = structuredClone(data);
		alter(copy);
		assert.throws(() => assertGuestCollectionData(copy));
	}
	});

test('public pages have no ownership controls or global ownership hydrator', () => pageFixture('<main><article>Serie pública</article></main>', async page => {
	await assertNoOwnershipControls(page);
	await page.setContent('<main><input type="checkbox" aria-label="Tengo este tomo"></main>');
	await assert.rejects(assertNoOwnershipControls(page), /casillas de propiedad/);
	await page.setContent('<main><button class="akb-pdp-shelve">Marcar</button></main>');
	await assert.rejects(assertNoOwnershipControls(page), /controles de propiedad/);
	await page.setContent('<main></main><script>window.akbCollectionBadges = {}</script>');
	await assert.rejects(assertNoOwnershipControls(page), /hidratador global/);
}));

test('a single overflowing pixel fails mobile geometry', () => pageFixture('<style>body{margin:0}div{width:376px}</style><div>Serie</div>', page => assert.rejects(assertGeometry(page), /Desbordamiento/)));

test('editorial filter and autocomplete reject missing real controls or results', async () => {
	await pageFixture(`
		<input id="akb-series-directory-input" placeholder="Escribe la serie, autor o edición">
		<ul><li class="akb-series-directory__item" data-akb-serie-id="1" data-akb-editorial="a">20th Century Boys</li><li class="akb-series-directory__item" data-akb-serie-id="2" data-akb-editorial="b">Akira</li></ul>
		<script>document.querySelector('input').addEventListener('input', event => {document.querySelectorAll('li')[1].hidden=Boolean(event.target.value)});</script>
	`, page => assert.rejects(assertDirectoryFilters(page)));
	await pageFixture('<input id="akb-search-input" aria-controls="suggestions"><div id="suggestions" role="listbox"></div>', page => assert.rejects(assertAutocomplete(page)));
});
