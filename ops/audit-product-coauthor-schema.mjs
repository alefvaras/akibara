#!/usr/bin/env node

/**
 * Compare an authoritative, locally captured pa_autor manifest with the
 * Product/Book JSON-LD emitted by public product pages.
 *
 * The manifest is intentionally strict and minimal:
 * {
 *   "schemaVersion": 1,
 *   "source": "description of the local authoritative capture",
 *   "capturedAt": "2026-07-26T12:00:00Z",
 *   "taxonomy": "pa_autor",
 *   "products": [{
 *     "url": "https://akibara.cl/producto/",
 *     "authors": [{
 *       "name": "Author name",
 *       "url": "https://akibara.cl/autor/author-slug/"
 *     }]
 *   }]
 * }
 *
 * Usage:
 *   node ops/audit-product-coauthor-schema.mjs \
 *     --manifest /absolute/local/path/product-coauthors.json \
 *     --expected-count 53 \
 *     --concurrency 6
 */

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DEFAULT_EXPECTED_COUNT = 53;
const DEFAULT_CONCURRENCY = 6;
const MAX_CONCURRENCY = 16;
const DEFAULT_TIMEOUT_MS = 20000;
const MAX_TIMEOUT_MS = 60000;

const compareText = (left, right) => {
	if (left < right) {
		return -1;
	}
	if (left > right) {
		return 1;
	}
	return 0;
};

const exactKeys = (value, expected, label) => {
	const actual = Object.keys(value).sort(compareText);
	const wanted = [...expected].sort(compareText);
	if (
		actual.length !== wanted.length ||
		actual.some((key, index) => key !== wanted[index])
	) {
		throw new Error(
			`${label} must contain exactly: ${wanted.join(', ')}; got: ${actual.join(', ')}`
		);
	}
};

const positiveInteger = (value, label, maximum = Number.MAX_SAFE_INTEGER) => {
	if (!Number.isSafeInteger(value) || value < 1 || value > maximum) {
		throw new Error(`${label} must be an integer between 1 and ${maximum}`);
	}
	return value;
};

const integerArgument = (value, label, maximum = Number.MAX_SAFE_INTEGER) => {
	if (!/^[1-9]\d*$/.test(value)) {
		throw new Error(`${label} must be a positive base-10 integer`);
	}
	return positiveInteger(Number(value), label, maximum);
};

const normalizedHttpUrl = (value, label) => {
	if (typeof value !== 'string' || value === '') {
		throw new Error(`${label} must be a non-empty absolute HTTP(S) URL`);
	}
	if (value !== value.trim()) {
		throw new Error(`${label} must not contain leading or trailing whitespace`);
	}

	let url;
	try {
		url = new URL(value);
	} catch {
		throw new Error(`${label} must be a valid absolute URL`);
	}
	if (!['http:', 'https:'].includes(url.protocol)) {
		throw new Error(`${label} must use HTTP or HTTPS`);
	}
	if (url.username || url.password || url.search || url.hash) {
		throw new Error(`${label} must not contain credentials, a query, or a fragment`);
	}
	return url.href;
};

const normalizedName = (value, label) => {
	if (typeof value !== 'string' || value === '') {
		throw new Error(`${label} must be a non-empty string`);
	}
	if (value !== value.trim()) {
		throw new Error(`${label} must not contain leading or trailing whitespace`);
	}
	return value;
};

const authorKey = (author) => `${author.name}\u0000${author.url}`;

export function validateManifest(
	manifest,
	{ expectedCount = DEFAULT_EXPECTED_COUNT } = {}
) {
	positiveInteger(expectedCount, 'expectedCount');
	if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
		throw new Error('Manifest root must be an object');
	}
	exactKeys(
		manifest,
		['schemaVersion', 'source', 'capturedAt', 'taxonomy', 'products'],
		'Manifest root'
	);
	if (manifest.schemaVersion !== 1) {
		throw new Error('Manifest schemaVersion must be exactly 1');
	}
	if (
		typeof manifest.source !== 'string' ||
		manifest.source === '' ||
		manifest.source !== manifest.source.trim()
	) {
		throw new Error('Manifest source must be a non-empty trimmed string');
	}
	if (
		typeof manifest.capturedAt !== 'string' ||
		!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(
			manifest.capturedAt
		) ||
		Number.isNaN(Date.parse(manifest.capturedAt))
	) {
		throw new Error('Manifest capturedAt must be an RFC 3339 date-time');
	}
	if (manifest.taxonomy !== 'pa_autor') {
		throw new Error('Manifest taxonomy must be exactly pa_autor');
	}
	if (!Array.isArray(manifest.products)) {
		throw new Error('Manifest products must be an array');
	}
	if (manifest.products.length !== expectedCount) {
		throw new Error(
			`Manifest must contain exactly ${expectedCount} products; got ${manifest.products.length}`
		);
	}

	const products = manifest.products.map((product, productIndex) => {
		const productLabel = `products[${productIndex}]`;
		if (!product || typeof product !== 'object' || Array.isArray(product)) {
			throw new Error(`${productLabel} must be an object`);
		}
		exactKeys(product, ['url', 'authors'], productLabel);
		const url = normalizedHttpUrl(product.url, `${productLabel}.url`);
		if (!Array.isArray(product.authors) || product.authors.length < 2) {
			throw new Error(`${productLabel}.authors must contain at least two coauthors`);
		}

		const authors = product.authors.map((author, authorIndex) => {
			const authorLabel = `${productLabel}.authors[${authorIndex}]`;
			if (!author || typeof author !== 'object' || Array.isArray(author)) {
				throw new Error(`${authorLabel} must be an object`);
			}
			exactKeys(author, ['name', 'url'], authorLabel);
			return {
				name: normalizedName(author.name, `${authorLabel}.name`),
				url: normalizedHttpUrl(author.url, `${authorLabel}.url`),
			};
		}).sort((left, right) => compareText(authorKey(left), authorKey(right)));

		const authorKeys = authors.map(authorKey);
		if (new Set(authorKeys).size !== authorKeys.length) {
			throw new Error(`${productLabel}.authors contains a duplicate name/URL pair`);
		}
		const authorUrls = authors.map((author) => author.url);
		if (new Set(authorUrls).size !== authorUrls.length) {
			throw new Error(`${productLabel}.authors contains a duplicate term URL`);
		}
		return { url, authors };
	}).sort((left, right) => compareText(left.url, right.url));

	const productUrls = products.map((product) => product.url);
	if (new Set(productUrls).size !== productUrls.length) {
		throw new Error('Manifest contains duplicate product URLs');
	}

	return {
		schemaVersion: 1,
		source: manifest.source,
		capturedAt: manifest.capturedAt,
		taxonomy: 'pa_autor',
		products,
	};
}

export function manifestSha256(
	manifest,
	{ expectedCount = DEFAULT_EXPECTED_COUNT } = {}
) {
	const canonical = validateManifest(manifest, { expectedCount });
	return crypto
		.createHash('sha256')
		.update(JSON.stringify(canonical))
		.digest('hex');
}

const scriptType = (attributes) => {
	const match = attributes.match(
		/\btype\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/i
	);
	return (match?.[1] ?? match?.[2] ?? match?.[3] ?? '')
		.split(';', 1)[0]
		.trim()
		.toLowerCase();
};

export function extractJsonLd(html) {
	if (typeof html !== 'string') {
		throw new Error('Public page body must be a string');
	}

	const scripts = [];
	for (const match of html.matchAll(
		/<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi
	)) {
		if (scriptType(match[1]) === 'application/ld+json') {
			scripts.push(match[2]);
		}
	}

	const nodes = [];
	const parseErrors = [];
	const collectTypedNodes = (value) => {
		if (Array.isArray(value)) {
			for (const item of value) {
				collectTypedNodes(item);
			}
			return;
		}
		if (!value || typeof value !== 'object') {
			return;
		}
		if (Object.hasOwn(value, '@type')) {
			nodes.push(value);
		}
		for (const child of Object.values(value)) {
			collectTypedNodes(child);
		}
	};

	scripts.forEach((script, index) => {
		try {
			collectTypedNodes(JSON.parse(script));
		} catch (error) {
			parseErrors.push({
				script: index + 1,
				error: error instanceof Error ? error.message : String(error),
			});
		}
	});

	return { scripts: scripts.length, nodes, parseErrors };
}

const nodeTypes = (node) => {
	const types = Array.isArray(node?.['@type'])
		? node['@type']
		: [node?.['@type']];
	return types.filter((type) => typeof type === 'string');
};

const hasType = (node, type) => nodeTypes(node).includes(type);

const arrayEqual = (left, right) => (
	left.length === right.length &&
	left.every((value, index) => value === right[index])
);

const assertion = (name, expected, actual, pass) => ({
	name,
	pass,
	expected,
	actual,
});

const observedAuthors = (productBookNode) => {
	if (!productBookNode) {
		return {
			rawCount: 0,
			authors: [],
			schemaErrors: ['Cannot inspect author without exactly one Product/Book node'],
		};
	}
	const raw = Array.isArray(productBookNode.author)
		? productBookNode.author
		: productBookNode.author === undefined
			? []
			: [productBookNode.author];
	const authors = [];
	const schemaErrors = [];

	raw.forEach((author, index) => {
		const label = `author[${index}]`;
		if (!author || typeof author !== 'object' || Array.isArray(author)) {
			schemaErrors.push(`${label} must be an object`);
			return;
		}
		if (!hasType(author, 'Person')) {
			schemaErrors.push(`${label}.@type must include Person`);
		}

		let name;
		let url;
		try {
			name = normalizedName(author.name, `${label}.name`);
		} catch (error) {
			schemaErrors.push(error.message);
		}
		try {
			url = normalizedHttpUrl(author.url, `${label}.url`);
		} catch (error) {
			schemaErrors.push(error.message);
		}
		if (name !== undefined && url !== undefined) {
			authors.push({ name, url });
		}
	});

	authors.sort((left, right) => compareText(authorKey(left), authorKey(right)));
	return { rawCount: raw.length, authors, schemaErrors };
};

export function auditProductHtml({
	product,
	html,
	status = 200,
	requestUrl = product.url,
	finalUrl = requestUrl,
}) {
	const jsonLd = extractJsonLd(html);
	const productNodes = jsonLd.nodes.filter((node) => hasType(node, 'Product'));
	const productBookNodes = productNodes.filter(
		(node) => hasType(node, 'Book')
	);
	const authorResult = observedAuthors(
		productBookNodes.length === 1 ? productBookNodes[0] : null
	);

	const expectedNames = product.authors
		.map((author) => author.name)
		.sort(compareText);
	const expectedUrls = product.authors
		.map((author) => author.url)
		.sort(compareText);
	const expectedPairs = product.authors.map(authorKey).sort(compareText);
	const actualNames = authorResult.authors
		.map((author) => author.name)
		.sort(compareText);
	const actualUrls = authorResult.authors
		.map((author) => author.url)
		.sort(compareText);
	const actualPairs = authorResult.authors.map(authorKey).sort(compareText);

	const assertions = [
		assertion('http_status', 200, status, status === 200),
		assertion(
			'final_url',
			requestUrl,
			finalUrl,
			finalUrl === requestUrl
		),
		assertion(
			'json_ld_script_count',
			'at least 1',
			jsonLd.scripts,
			jsonLd.scripts >= 1
		),
		assertion(
			'json_ld_parse_errors',
			0,
			jsonLd.parseErrors.length,
			jsonLd.parseErrors.length === 0
		),
		assertion(
			'product_node_count',
			1,
			productNodes.length,
			productNodes.length === 1
		),
		assertion(
			'product_book_node_count',
			1,
			productBookNodes.length,
			productBookNodes.length === 1
		),
		assertion(
			'author_schema_errors',
			[],
			authorResult.schemaErrors,
			authorResult.schemaErrors.length === 0
		),
		assertion(
			'author_cardinality',
			product.authors.length,
			authorResult.rawCount,
			product.authors.length === authorResult.rawCount
		),
		assertion(
			'author_names',
			expectedNames,
			actualNames,
			arrayEqual(expectedNames, actualNames)
		),
		assertion(
			'author_urls',
			expectedUrls,
			actualUrls,
			arrayEqual(expectedUrls, actualUrls)
		),
		assertion(
			'author_name_url_pairs',
			expectedPairs,
			actualPairs,
			arrayEqual(expectedPairs, actualPairs)
		),
	];

	return {
		url: product.url,
		requestUrl,
		finalUrl,
		pass: assertions.every((item) => item.pass),
		assertions,
		expectedAuthors: product.authors,
		observedAuthors: authorResult.authors,
		jsonLdParseErrors: jsonLd.parseErrors,
	};
}

const requestUrlFor = (productUrl, baseUrl) => {
	if (!baseUrl) {
		return productUrl;
	}
	const requestUrl = new URL(productUrl);
	requestUrl.protocol = baseUrl.protocol;
	requestUrl.host = baseUrl.host;
	return requestUrl.href;
};

const fetchFailureAudit = (product, requestUrl, error) => {
	const message = error instanceof Error ? error.message : String(error);
	return {
		url: product.url,
		requestUrl,
		finalUrl: null,
		pass: false,
		assertions: [
			assertion('fetch', 'successful public page read', message, false),
		],
		expectedAuthors: product.authors,
		observedAuthors: [],
		jsonLdParseErrors: [],
	};
};

export async function auditManifest({
	manifest,
	expectedCount = DEFAULT_EXPECTED_COUNT,
	concurrency = DEFAULT_CONCURRENCY,
	baseUrl,
	fetchPage,
	onProgress,
}) {
	positiveInteger(concurrency, 'concurrency', MAX_CONCURRENCY);
	if (typeof fetchPage !== 'function') {
		throw new Error('fetchPage must be a function');
	}
	const canonical = validateManifest(manifest, { expectedCount });
	const canonicalHash = crypto
		.createHash('sha256')
		.update(JSON.stringify(canonical))
		.digest('hex');

	let normalizedBaseUrl;
	if (baseUrl !== undefined) {
		const baseHref = normalizedHttpUrl(baseUrl, 'baseUrl');
		normalizedBaseUrl = new URL(baseHref);
		if (
			normalizedBaseUrl.pathname !== '/' ||
			normalizedBaseUrl.search ||
			normalizedBaseUrl.hash
		) {
			throw new Error('baseUrl must be an origin without a path, query, or fragment');
		}
	}

	const audits = new Array(canonical.products.length);
	let cursor = 0;
	let completed = 0;
	const worker = async () => {
		for (;;) {
			const index = cursor;
			cursor += 1;
			if (index >= canonical.products.length) {
				return;
			}
			const product = canonical.products[index];
			const requestUrl = requestUrlFor(product.url, normalizedBaseUrl);
			try {
				const page = await fetchPage(requestUrl, product);
				if (
					!page ||
					typeof page !== 'object' ||
					typeof page.body !== 'string' ||
					!Number.isInteger(page.status)
				) {
					throw new Error('fetchPage must return { body, status, url? }');
				}
				audits[index] = auditProductHtml({
					product,
					html: page.body,
					status: page.status,
					requestUrl,
					finalUrl: typeof page.url === 'string' ? page.url : requestUrl,
				});
			} catch (error) {
				audits[index] = fetchFailureAudit(product, requestUrl, error);
			}
			completed += 1;
			onProgress?.({
				completed,
				total: canonical.products.length,
				failures: audits.filter((audit) => audit && !audit.pass).length,
			});
		}
	};

	const workerCount = Math.min(concurrency, canonical.products.length);
	await Promise.all(Array.from({ length: workerCount }, worker));
	const failures = audits.filter((audit) => !audit.pass);

	return {
		pass: failures.length === 0 && audits.length === expectedCount,
		schemaVersion: 1,
		manifest: {
			source: canonical.source,
			capturedAt: canonical.capturedAt,
			taxonomy: canonical.taxonomy,
			sha256: canonicalHash,
		},
		baseUrl: normalizedBaseUrl?.origin ?? null,
		expectedProducts: expectedCount,
		auditedProducts: audits.length,
		concurrency,
		failureCount: failures.length,
		failures,
		audits,
	};
}

const usage = `Usage:
  node ops/audit-product-coauthor-schema.mjs \\
    --manifest /absolute/local/path/product-coauthors.json \\
    [--expected-count 53] [--concurrency 6] [--timeout-ms 20000] \\
    [--base-url http://localhost:8080]

The manifest and every audit result are validated deterministically. The
optional base URL changes only the read target; expected author URLs continue
to come from the authoritative manifest.`;

export function parseCliArguments(argv) {
	const values = new Map();
	for (let index = 0; index < argv.length; index += 1) {
		const key = argv[index];
		if (key === '--help') {
			if (argv.length !== 1) {
				throw new Error('--help cannot be combined with other arguments');
			}
			return { help: true };
		}
		const value = argv[index + 1];
		if (!key?.startsWith('--') || value === undefined || value.startsWith('--')) {
			throw new Error(`Invalid argument near ${key ?? '<end>'}`);
		}
		const name = key.slice(2);
		if (![
			'manifest',
			'expected-count',
			'concurrency',
			'timeout-ms',
			'base-url',
		].includes(name)) {
			throw new Error(`Unsupported argument: --${name}`);
		}
		if (values.has(name)) {
			throw new Error(`Duplicate argument: --${name}`);
		}
		values.set(name, value);
		index += 1;
	}

	const manifestPath = values.get('manifest');
	if (!manifestPath) {
		throw new Error('--manifest is required');
	}
	const expectedCount = integerArgument(
		values.get('expected-count') ?? String(DEFAULT_EXPECTED_COUNT),
		'--expected-count'
	);
	const concurrency = integerArgument(
		values.get('concurrency') ?? String(DEFAULT_CONCURRENCY),
		'--concurrency',
		MAX_CONCURRENCY
	);
	const timeoutMs = integerArgument(
		values.get('timeout-ms') ?? String(DEFAULT_TIMEOUT_MS),
		'--timeout-ms',
		MAX_TIMEOUT_MS
	);

	return {
		help: false,
		manifestPath: path.resolve(manifestPath),
		expectedCount,
		concurrency,
		timeoutMs,
		baseUrl: values.get('base-url'),
	};
}

const fetchPublicPage = async (url, timeoutMs) => {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		const response = await fetch(url, {
			cache: 'no-store',
			headers: {
				'User-Agent': 'Akibara-Product-Coauthor-Schema-Audit/1.0',
			},
			redirect: 'follow',
			signal: controller.signal,
		});
		return {
			body: await response.text(),
			status: response.status,
			url: response.url,
		};
	} finally {
		clearTimeout(timer);
	}
};

export async function runCli(argv = process.argv.slice(2)) {
	const options = parseCliArguments(argv);
	if (options.help) {
		console.log(usage);
		return 0;
	}

	const rawManifest = await fs.readFile(options.manifestPath, 'utf8');
	let manifest;
	try {
		manifest = JSON.parse(rawManifest);
	} catch (error) {
		throw new Error(
			`Manifest is not valid JSON: ${
				error instanceof Error ? error.message : String(error)
			}`
		);
	}

	const result = await auditManifest({
		manifest,
		expectedCount: options.expectedCount,
		concurrency: options.concurrency,
		baseUrl: options.baseUrl,
		fetchPage: (url) => fetchPublicPage(url, options.timeoutMs),
		onProgress: ({ completed, total, failures }) => {
			if (completed % 10 === 0 || completed === total) {
				process.stderr.write(
					`Audited ${completed}/${total}; failures=${failures}\n`
				);
			}
		},
	});
	console.log(JSON.stringify(result, null, 2));
	return result.pass ? 0 : 1;
}

const isMain = process.argv[1] && (
	fileURLToPath(import.meta.url) === path.resolve(process.argv[1])
);
if (isMain) {
	try {
		process.exitCode = await runCli();
	} catch (error) {
		console.error(JSON.stringify({
			pass: false,
			fatalError: error instanceof Error ? error.message : String(error),
		}, null, 2));
		process.exitCode = 1;
	}
}
