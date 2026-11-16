#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	auditManifest,
	auditProductHtml,
	extractJsonLd,
	manifestSha256,
	parseCliArguments,
	validateManifest,
} from './audit-product-coauthor-schema.mjs';

const fixtureDir = path.join(
	path.dirname(fileURLToPath(import.meta.url)),
	'fixtures',
	'product-coauthor-schema'
);

const readFixture = (name) => fs.readFile(path.join(fixtureDir, name), 'utf8');
const manifest = JSON.parse(await readFixture('manifest-valid.json'));
const expectedAuthors = validateManifest(manifest, { expectedCount: 2 })
	.products[0].authors;

const assertionByName = (audit, name) => (
	audit.assertions.find((item) => item.name === name)
);

test('manifest validation is strict and canonical hash ignores input ordering', () => {
	const canonical = validateManifest(manifest, { expectedCount: 2 });
	assert.deepEqual(
		canonical.products.map((product) => product.url),
		[
			'https://akibara.cl/fixture-coauthor-a/',
			'https://akibara.cl/fixture-coauthor-b/',
		]
	);
	assert.deepEqual(
		canonical.products[1].authors.map((author) => author.name),
		['Alice Mori', 'Bob Kato']
	);

	const reordered = structuredClone(manifest);
	reordered.products.reverse();
	reordered.products.forEach((product) => product.authors.reverse());
	assert.equal(
		manifestSha256(reordered, { expectedCount: 2 }),
		manifestSha256(manifest, { expectedCount: 2 })
	);
});

test('manifest fails closed on count, taxonomy, unknown fields, and duplicates', () => {
	assert.throws(
		() => validateManifest(manifest, { expectedCount: 53 }),
		/exactly 53 products/
	);

	const wrongTaxonomy = structuredClone(manifest);
	wrongTaxonomy.taxonomy = 'product_author';
	assert.throws(
		() => validateManifest(wrongTaxonomy, { expectedCount: 2 }),
		/taxonomy must be exactly pa_autor/
	);

	const unknownField = structuredClone(manifest);
	unknownField.products[0].authors[0].slug = 'alice-mori';
	assert.throws(
		() => validateManifest(unknownField, { expectedCount: 2 }),
		/must contain exactly: name, url/
	);

	const duplicateProduct = structuredClone(manifest);
	duplicateProduct.products[1].url = duplicateProduct.products[0].url;
	assert.throws(
		() => validateManifest(duplicateProduct, { expectedCount: 2 }),
		/duplicate product URLs/
	);

	const duplicateAuthor = structuredClone(manifest);
	duplicateAuthor.products[0].authors[1] = structuredClone(
		duplicateAuthor.products[0].authors[0]
	);
	assert.throws(
		() => validateManifest(duplicateAuthor, { expectedCount: 2 }),
		/duplicate name\/URL pair/
	);

	const singleAuthor = structuredClone(manifest);
	singleAuthor.products[0].authors.pop();
	assert.throws(
		() => validateManifest(singleAuthor, { expectedCount: 2 }),
		/at least two coauthors/
	);
});

test('valid JSON-LD passes exact Product/Book and coauthor assertions', async () => {
	const html = await readFixture('multi-author.html');
	const extracted = extractJsonLd(html);
	assert.equal(extracted.scripts, 1);
	assert.equal(extracted.parseErrors.length, 0);

	const audit = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html,
	});
	assert.equal(audit.pass, true);
	assert.equal(assertionByName(audit, 'product_node_count').pass, true);
	assert.equal(assertionByName(audit, 'product_book_node_count').pass, true);
	assert.equal(assertionByName(audit, 'author_cardinality').actual, 2);
	assert.deepEqual(audit.observedAuthors, expectedAuthors);
});

test('missing coauthor fails cardinality, names, URLs, and name/URL pairs', async () => {
	const audit = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: await readFixture('single-author.html'),
	});
	assert.equal(audit.pass, false);
	for (const name of [
		'author_cardinality',
		'author_names',
		'author_urls',
		'author_name_url_pairs',
	]) {
		assert.equal(assertionByName(audit, name).pass, false, name);
	}
});

test('a changed name or term URL fails exact author comparison', async () => {
	const html = await readFixture('multi-author.html');
	const changedName = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: html.replace('Alice Mori', 'Alice Móri'),
	});
	assert.equal(assertionByName(changedName, 'author_names').pass, false);
	assert.equal(assertionByName(changedName, 'author_urls').pass, true);
	assert.equal(assertionByName(changedName, 'author_name_url_pairs').pass, false);

	const changedUrl = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: html.replace('/autor/alice-mori/', '/autor/alice-mori-legacy/'),
	});
	assert.equal(assertionByName(changedUrl, 'author_names').pass, true);
	assert.equal(assertionByName(changedUrl, 'author_urls').pass, false);
	assert.equal(assertionByName(changedUrl, 'author_name_url_pairs').pass, false);
});

test('duplicate Product nodes are never deduplicated or accepted', async () => {
	const audit = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: await readFixture('duplicate-product.html'),
	});
	assert.equal(audit.pass, false);
	assert.deepEqual(assertionByName(audit, 'product_node_count'), {
		name: 'product_node_count',
		pass: false,
		expected: 1,
		actual: 2,
	});
	assert.equal(
		assertionByName(audit, 'product_book_node_count').actual,
		2
	);
});

test('one malformed JSON-LD script fails even when another script is valid', async () => {
	const audit = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: await readFixture('malformed-jsonld.html'),
	});
	assert.equal(audit.pass, false);
	assert.equal(assertionByName(audit, 'json_ld_parse_errors').actual, 1);
	assert.equal(assertionByName(audit, 'product_node_count').actual, 1);
	assert.equal(audit.jsonLdParseErrors.length, 1);
});

test('a redirect cannot validate schema from a different public URL', async () => {
	const audit = auditProductHtml({
		product: {
			url: manifest.products[0].url,
			authors: expectedAuthors,
		},
		html: await readFixture('multi-author.html'),
		requestUrl: manifest.products[0].url,
		finalUrl: manifest.products[1].url,
	});
	assert.equal(audit.pass, false);
	assert.deepEqual(assertionByName(audit, 'final_url'), {
		name: 'final_url',
		pass: false,
		expected: manifest.products[0].url,
		actual: manifest.products[1].url,
	});
});

test('manifest audit is bounded and deterministic despite completion order', async () => {
	const products = Array.from({ length: 7 }, (_, index) => ({
		url: `https://akibara.cl/fixture-${String(index).padStart(2, '0')}/`,
		authors: structuredClone(manifest.products[0].authors).reverse(),
	})).reverse();
	const concurrentManifest = {
		...structuredClone(manifest),
		products,
	};
	const html = await readFixture('multi-author.html');
	let active = 0;
	let maximumActive = 0;
	const requested = [];

	const result = await auditManifest({
		manifest: concurrentManifest,
		expectedCount: 7,
		concurrency: 3,
		baseUrl: 'http://localhost:8080',
		fetchPage: async (url) => {
			requested.push(url);
			active += 1;
			maximumActive = Math.max(maximumActive, active);
			await new Promise((resolve) => setImmediate(resolve));
			active -= 1;
			return { status: 200, body: html, url };
		},
	});

	assert.equal(result.pass, true);
	assert.equal(result.failureCount, 0);
	assert.ok(maximumActive > 1);
	assert.ok(maximumActive <= 3);
	assert.deepEqual(
		result.audits.map((audit) => audit.url),
		[...result.audits.map((audit) => audit.url)].sort()
	);
	assert.ok(requested.every((url) => url.startsWith('http://localhost:8080/')));
	assert.ok(result.audits.every((audit) => (
		audit.observedAuthors.every((author) => author.url.startsWith('https://akibara.cl/'))
	)));
});

test('fetch failures are recorded without changing deterministic audit order', async () => {
	const result = await auditManifest({
		manifest,
		expectedCount: 2,
		concurrency: 2,
		fetchPage: async (url) => {
			if (url.endsWith('/fixture-coauthor-a/')) {
				throw new Error('fixture read failed');
			}
			return {
				status: 200,
				body: await readFixture('multi-author.html'),
				url,
			};
		},
	});
	assert.equal(result.pass, false);
	assert.equal(result.failureCount, 1);
	assert.equal(result.failures[0].url, manifest.products[0].url);
	assert.equal(result.failures[0].assertions[0].name, 'fetch');
	assert.match(result.failures[0].assertions[0].actual, /fixture read failed/);
});

test('CLI arguments default to the 53-product gate and reject ambiguity', () => {
	const parsed = parseCliArguments(['--manifest', './manifest.json']);
	assert.equal(parsed.expectedCount, 53);
	assert.equal(parsed.concurrency, 6);
	assert.equal(parsed.timeoutMs, 20000);
	assert.equal(path.isAbsolute(parsed.manifestPath), true);

	assert.throws(
		() => parseCliArguments([
			'--manifest',
			'a.json',
			'--manifest',
			'b.json',
		]),
		/Duplicate argument/
	);
	assert.throws(
		() => parseCliArguments(['--manifest', 'a.json', '--concurrency', '17']),
		/between 1 and 16/
	);
	assert.throws(
		() => parseCliArguments(['--manifest', 'a.json', '--unknown', 'value']),
		/Unsupported argument/
	);
	assert.throws(
		() => parseCliArguments(['--manifest', 'a.json', '--expected-count', '53x']),
		/positive base-10 integer/
	);
});
