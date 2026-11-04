#!/usr/bin/env node

/**
 * Validador exhaustivo de Schemas JSON-LD (Rich Results hygiene)
 * Audita nodos Product, Book, ComicSeries, BreadcrumbList y MerchantReturnPolicy.
 */

const URLS = [
	'https://akibara.cl/serie/los-diarios-de-la-boticaria/',
	'https://akibara.cl/los-diarios-de-la-boticaria-1-devir/',
	'https://akibara.cl/los-diarios-de-la-boticaria-la-novela-original-1-2-pack-de-lanzamiento/',
	'https://akibara.cl/serie/dorohedoro/',
	'https://akibara.cl/dorohedoro-1-ivrea-argentina/',
	'https://akibara.cl/dorohedoro-11-ivrea-argentina/',
	'https://akibara.cl/serie/attack-on-titan-edicion-deluxe/',
	'https://akibara.cl/attack-on-titan-edicion-deluxe-1-ovni-press/',
	'https://akibara.cl/serie/fumando-juntos-detras-del-super/',
	'https://akibara.cl/fumando-juntos-detras-del-super-01-panini-espana/',
	'https://akibara.cl/fumando-juntos-detras-del-super-08-panini-espana/',
	'https://akibara.cl/el-arte-de-atelier-of-witch-hat-milky-way/',
	'https://akibara.cl/el-arte-de-berserk-panini-espana/'
];

async function fetchHtml(url) {
	const res = await fetch(url, {
		headers: {
			'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
			'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
		}
	});
	if (!res.ok) {
		throw new Error(`HTTP ${res.status} ${res.statusText}`);
	}
	return await res.text();
}

function extractJsonLd(html) {
	const regex = /<script\s+[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
	const schemas = [];
	let match;
	while ((match = regex.exec(html)) !== null) {
		try {
			const parsed = JSON.parse(match[1]);
			schemas.push(parsed);
		} catch (e) {
			schemas.push({ error: 'JSON_PARSE_ERROR', raw: match[1] });
		}
	}
	return schemas;
}

function validateSchema(schema, url) {
	const errors = [];
	const warnings = [];
	const graph = schema['@graph'] || [schema];

	let hasProduct = false;
	let hasBook = false;
	let hasSeries = false;
	let hasBreadcrumb = false;

	for (const node of graph) {
		const types = Array.isArray(node['@type']) ? node['@type'] : [node['@type']];

		if (types.includes('BreadcrumbList')) {
			hasBreadcrumb = true;
			if (!Array.isArray(node.itemListElement) || node.itemListElement.length === 0) {
				errors.push('BreadcrumbList sin itemListElement válido');
			}
		}

		if (types.includes('ComicSeries')) {
			hasSeries = true;
			if (!node.name) errors.push('ComicSeries sin name');
			if (!node.url) errors.push('ComicSeries sin url');
			if (!node.publisher) warnings.push('ComicSeries sin publisher');
			if (!node.author) warnings.push('ComicSeries sin author');
		}

		if (types.includes('Product')) {
			hasProduct = true;
			if (!node.name) errors.push('Product sin name');
			if (!node.sku && !node.gtin) warnings.push('Product sin sku ni gtin');
			if (!node.offers) {
				errors.push('Product sin offers');
			} else {
				const offer = node.offers;
				if (!offer.price) errors.push('Product offer sin price');
				if (!offer.priceCurrency) errors.push('Product offer sin priceCurrency');
				if (!offer.availability) errors.push('Product offer sin availability');
				if (!offer.hasMerchantReturnPolicy) warnings.push('Product offer sin hasMerchantReturnPolicy');
			}
		}

		if (types.includes('Book')) {
			hasBook = true;
			if (!node.name) errors.push('Book sin name');
			if (!node.author) warnings.push('Book sin author');
		}
	}

	const isPdp = url.includes('-ivrea-') || url.includes('-devir') || url.includes('-panini-') || url.includes('-ovni-') || url.includes('-milky-way') || url.includes('pack-de-lanzamiento');
	const isSeries = url.includes('/serie/');

	if (isPdp && !hasProduct) errors.push('PDP no contiene nodo Product');
	if (isPdp && !hasBook) warnings.push('PDP de manga no contiene nodo Book');
	if (isSeries && !hasSeries) errors.push('Landing de serie no contiene nodo ComicSeries');
	if (!hasBreadcrumb) warnings.push('Página no contiene nodo BreadcrumbList');

	return { errors, warnings, nodeCount: graph.length };
}

async function run() {
	console.log(`Auditoría de Schemas JSON-LD en ${URLS.length} URLs de producción...\n`);
	let allPassed = true;

	for (const url of URLS) {
		await new Promise(r => setTimeout(r, 250));
		try {
			const html = await fetchHtml(url);
			const schemas = extractJsonLd(html);

			if (schemas.length === 0) {
				console.error(`❌ [FAIL] ${url}: Cero etiquetas JSON-LD encontradas.`);
				allPassed = false;
				continue;
			}

			let totalErrors = [];
			let totalWarnings = [];
			let totalNodes = 0;

			for (const s of schemas) {
				if (s.error) {
					totalErrors.push(`Error de parsing JSON: ${s.error}`);
					continue;
				}
				const v = validateSchema(s, url);
				totalErrors.push(...v.errors);
				totalWarnings.push(...v.warnings);
				totalNodes += v.nodeCount;
			}

			if (totalErrors.length === 0) {
				console.log(`✅ [PASS] ${url}`);
				console.log(`   Nodos: ${totalNodes} | Advertencias: ${totalWarnings.length ? totalWarnings.join(', ') : '0'}`);
			} else {
				console.error(`❌ [FAIL] ${url}`);
				console.error(`   Errores: ${totalErrors.join('; ')}`);
				allPassed = false;
			}
		} catch (err) {
			console.error(`❌ [ERROR] ${url}: ${err.message}`);
			allPassed = false;
		}
	}

	console.log('\n=======================================');
	if (allPassed) {
		console.log('🎉 TODOS LOS SCHEMAS JSON-LD SON 100% VÁLIDOS.');
	} else {
		console.log('⚠️ SE ENCONTRARON FALLAS EN AL MENOS UN SCHEMA.');
		process.exit(1);
	}
}

run();
