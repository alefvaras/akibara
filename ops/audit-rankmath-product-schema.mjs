#!/usr/bin/env node

/**
 * Crawl every public product URL from the Rank Math sitemap and verify that
 * Akibara emits exactly one Product + Book node.
 *
 * Usage:
 *   node ops/audit-rankmath-product-schema.mjs \
 *     --base-url http://localhost:8080 \
 *     --expected-count 1503 \
 *     --concurrency 10
 */

const args = new Map();
for (let index = 2; index < process.argv.length; index += 2) {
	const key = process.argv[index];
	const value = process.argv[index + 1];
	if (!key?.startsWith('--') || value === undefined) {
		throw new Error(`Invalid argument near ${key ?? '<end>'}`);
	}
	args.set(key.slice(2), value);
}

const baseUrl = new URL(args.get('base-url') ?? 'http://localhost:8080');
const expectedCount = Number.parseInt(args.get('expected-count') ?? '1503', 10);
const concurrency = Number.parseInt(args.get('concurrency') ?? '10', 10);

if (!Number.isSafeInteger(expectedCount) || expectedCount < 1) {
	throw new Error('--expected-count must be a positive integer');
}
if (!Number.isSafeInteger(concurrency) || concurrency < 1 || concurrency > 32) {
	throw new Error('--concurrency must be an integer between 1 and 32');
}

const fetchText = async (url, timeoutMs = 20000) => {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		const response = await fetch(url, {
			headers: { 'User-Agent': 'Akibara-RankMath-Schema-Audit/1.0' },
			signal: controller.signal,
		});
		return {
			body: await response.text(),
			redirected: response.redirected,
			status: response.status,
			url: response.url,
		};
	} finally {
		clearTimeout(timer);
	}
};

const locValues = (xml) =>
	Array.from(xml.matchAll(/<loc>([\s\S]*?)<\/loc>/gi), (match) =>
		match[1]
			.replaceAll('&amp;', '&')
			.replaceAll('&lt;', '<')
			.replaceAll('&gt;', '>')
			.trim()
	);

const productLocValues = (xml) =>
	Array.from(xml.matchAll(/<url>([\s\S]*?)<\/url>/gi))
		.filter((match) => /<image:image\b/i.test(match[1]))
		.flatMap((match) => locValues(match[1]).slice(0, 1));

const localize = (url) => {
	const localized = new URL(url, baseUrl);
	localized.protocol = baseUrl.protocol;
	localized.host = baseUrl.host;
	return localized;
};

const normalizeText = (value) =>
	typeof value === 'string'
		? value
				.normalize('NFKC')
				.replace(/[\u200B-\u200D\uFEFF]/gu, '')
				.replace(/\u00A0/gu, ' ')
				.replace(/\s+/gu, ' ')
				.trim()
		: '';

const comparisonKey = (value) =>
	normalizeText(value).normalize('NFKC').toLocaleLowerCase('es-CL');

const asList = (value) => (Array.isArray(value) ? value : value ? [value] : []);

const auditDescription = (node) => {
	const rawDescription =
		typeof node?.description === 'string' ? node.description : '';
	const description = normalizeText(rawDescription);
	const name = normalizeText(node?.name);
	const authors = asList(node?.author)
		.map((author) => normalizeText(author?.name))
		.filter(Boolean);
	const errors = [];

	if (!description) {
		errors.push('missing');
	}
	if (description.length > 500) {
		errors.push('longer-than-500');
	}
	if (/<[^>]+>/u.test(rawDescription)) {
		errors.push('contains-html');
	}
	if (name && !description.startsWith(name)) {
		errors.push('does-not-start-with-name');
	}
	for (const author of authors) {
		if (!description.includes(author)) {
			errors.push(`missing-author:${author}`);
		}
	}
	if (
		/al mejor precio|env[ií]o a todo chile|preventas,\s*novedades\s*y\s*tomos originales/iu.test(
			description
		)
	) {
		errors.push('contains-global-promotion-template');
	}

	return { description, errors };
};

const auditBookFacts = (node) => {
	const errors = [];
	const description = normalizeText(node?.description);
	const authors = asList(node?.author).filter(
		(author) => normalizeText(author?.name) !== ''
	);
	const seriesTypes = asList(node?.isPartOf?.['@type']);
	const seriesName = normalizeText(node?.isPartOf?.name);
	const hasComicSeries =
		seriesTypes.includes('ComicSeries') &&
		seriesName !== '';
	const positionExists = Object.prototype.hasOwnProperty.call(
		node ?? {},
		'position'
	);
	const position = node?.position;
	const productMatchesSeries =
		comparisonKey(node?.name) === comparisonKey(seriesName);
	const hasValidPosition =
		(Number.isSafeInteger(position) && position > 0) ||
		(typeof position === 'string' &&
			/^[1-9]\d*$/u.test(position) &&
			Number.isSafeInteger(Number(position)));

	if (authors.length === 0) {
		errors.push('missing-author');
	}
	if (!hasComicSeries) {
		errors.push('missing-comic-series');
	}
	if (positionExists && !hasValidPosition) {
		errors.push('invalid-position');
	}
	if (hasComicSeries && hasValidPosition) {
		const expected = `Tomo ${Number(position)} de la serie ${seriesName}.`;
		if (!description.includes(expected)) {
			errors.push('missing-volume-series-description-clause');
		}
	}
	if (hasComicSeries && !positionExists && !productMatchesSeries) {
		const expected = `Serie: ${seriesName}.`;
		if (!description.includes(expected)) {
			errors.push('missing-series-description-clause');
		}
	}
	if (hasComicSeries && !positionExists && productMatchesSeries) {
		const tautology = `Serie: ${seriesName}.`;
		if (description.includes(tautology)) {
			errors.push('tautological-series-description-clause');
		}
	}

	return errors;
};

const sitemapIndex = await fetchText(new URL('/sitemap_index.xml', baseUrl));
if (sitemapIndex.status !== 200) {
	throw new Error(`Sitemap index returned HTTP ${sitemapIndex.status}`);
}

const productSitemaps = locValues(sitemapIndex.body).filter((url) =>
	/product-sitemap\d*\.xml(?:$|\?)/i.test(new URL(url).pathname)
);
if (productSitemaps.length === 0) {
	throw new Error('No product sitemap was found in sitemap_index.xml');
}

const productUrls = [];
for (const sitemapUrl of productSitemaps) {
	const sitemap = await fetchText(localize(sitemapUrl));
	if (sitemap.status !== 200) {
		throw new Error(`${sitemapUrl} returned HTTP ${sitemap.status}`);
	}
	// Rank Math also includes virtual WooCommerce archive URLs such as
	// /tienda/ and /preventas/ in the product sitemap. Actual product entries
	// carry an image block; archives do not and must not be counted as product
	// detail pages in this schema audit.
	productUrls.push(...productLocValues(sitemap.body).map(localize));
}

const uniqueProducts = Array.from(
	new Map(productUrls.map((url) => [url.pathname, url])).values()
).sort((left, right) => left.pathname.localeCompare(right.pathname));

const failures = [];
let cursor = 0;
let audited = 0;

const auditProduct = async (url) => {
	const auditedUrl = new URL(url);
	auditedUrl.searchParams.set('akb_rankmath_schema_audit', '1');

	const page = await fetchText(auditedUrl);
	const requestedUrl = auditedUrl.href;
	const finalUrl = new URL(page.url).href;
	const unexpectedRedirect = page.redirected || finalUrl !== requestedUrl;
	const scripts = Array.from(
		page.body.matchAll(
			/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi
		),
		(match) => match[1]
	);

	const nodes = [];
	let parseErrors = 0;
	for (const script of scripts) {
		try {
			const json = JSON.parse(script);
			nodes.push(
				...(Array.isArray(json?.['@graph']) ? json['@graph'] : [json])
			);
		} catch {
			parseErrors += 1;
		}
	}

	const productNodes = nodes.filter((node) => {
		const types = Array.isArray(node?.['@type'])
			? node['@type']
			: [node?.['@type']];
		return types.includes('Product');
	});
	const types = productNodes.map((node) => node['@type']);
	const hasOneProductBook =
		productNodes.length === 1 &&
		Array.isArray(productNodes[0]['@type']) &&
		productNodes[0]['@type'].includes('Book');
	const descriptionAudit =
		productNodes.length === 1
			? auditDescription(productNodes[0])
			: { description: '', errors: ['product-node-count'] };
	const bookFactErrors =
		productNodes.length === 1
			? auditBookFacts(productNodes[0])
			: ['product-node-count'];

	if (
		page.status !== 200 ||
		unexpectedRedirect ||
		parseErrors !== 0 ||
		!hasOneProductBook ||
		descriptionAudit.errors.length !== 0 ||
		bookFactErrors.length !== 0
	) {
		failures.push({
			url: requestedUrl,
			finalUrl,
			redirected: page.redirected,
			status: page.status,
			jsonLdParseErrors: parseErrors,
			productNodes: productNodes.length,
			types,
			description: descriptionAudit.description,
			descriptionErrors: descriptionAudit.errors,
			bookFactErrors,
		});
	}
};

const worker = async () => {
	for (;;) {
		const index = cursor;
		cursor += 1;
		if (index >= uniqueProducts.length) {
			return;
		}

		const url = uniqueProducts[index];
		try {
			await auditProduct(url);
		} catch (error) {
			failures.push({ url: url.href, error: String(error) });
		}
		audited += 1;

		if (audited % 100 === 0 || audited === uniqueProducts.length) {
			process.stderr.write(
				`Audited ${audited}/${uniqueProducts.length}; failures=${failures.length}\n`
			);
		}
	}
};

await Promise.all(Array.from({ length: concurrency }, worker));

const result = {
	baseUrl: baseUrl.href,
	productSitemaps: productSitemaps.length,
	discoveredProducts: uniqueProducts.length,
	expectedProducts: expectedCount,
	auditedProducts: audited,
	failures,
};

console.log(JSON.stringify(result, null, 2));

if (
	uniqueProducts.length !== expectedCount ||
	audited !== expectedCount ||
	failures.length !== 0
) {
	process.exitCode = 1;
}
