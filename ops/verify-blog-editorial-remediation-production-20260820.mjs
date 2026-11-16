#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ACTIVE_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27528,
]);
const REQUIRED_FIELDS = Object.freeze([
	'post_title', 'post_content', 'post_excerpt', 'rank_math_focus_keyword',
	'rank_math_title', 'rank_math_description',
]);

function fail(message) {
	throw new Error(`Production blog remediation verification failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative);
}

function localFile(value, label, { output = false } = {}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const allowed = [path.join(ROOT, '.local-reports'), path.join(ROOT, '.local-checkpoints')];
	if (!allowed.some((directory) => isInside(file, directory))) {
		fail(`${label} must remain under a local evidence directory`);
	}
	if (output) {
		if (fs.existsSync(file)) fail(`${label} already exists`);
	} else if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
		fail(`${label} must be a regular file`);
	}
	return file;
}

function readJson(file, label) {
	try {
		return { bytes: fs.readFileSync(file), value: JSON.parse(fs.readFileSync(file, 'utf8')) };
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function normalize(value) {
	return String(value ?? '').replace(/\r\n?/gu, '\n');
}

function stable(value) {
	if (Array.isArray(value)) return value.map((item) => stable(item));
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.keys(value).sort().map((key) => [key, stable(value[key])]));
	}
	return value;
}

function equal(left, right) {
	return JSON.stringify(stable(left)) === JSON.stringify(stable(right));
}

function textOnly(value) {
	return String(value ?? '')
		.replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
		.replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replace(/&(?:nbsp|amp|quot|apos|#39);/giu, ' ')
		.replace(/\s+/gu, ' ').trim();
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8', flag: 'wx', mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function curlPage(url, { follow = false } = {}) {
	const args = [
		'--silent', '--show-error', '--compressed', '--max-time', '45',
		'--connect-timeout', '10', '--dump-header', '-',
		'--user-agent', 'AkibaraProductionEditorialQA/20260820',
		'--header', 'Cache-Control: no-cache', '--header', 'Pragma: no-cache',
	];
	if (follow) args.push('--location', '--max-redirs', '5');
	args.push(url);
	const result = spawnSync('curl', args, {
		encoding: 'utf8', timeout: 55_000, maxBuffer: 24 * 1024 * 1024,
	});
	if (result.error || result.status !== 0) {
		fail(`curl failed for ${url}: ${result.stderr || result.error?.message}`);
	}
	const parts = result.stdout.split(/\r?\n\r?\n/u);
	let status = 0;
	let headers = {};
	let bodyStart = 0;
	for (let index = 0; index < parts.length; index += 1) {
		const lines = parts[index].split(/\r?\n/u);
		const match = lines[0]?.match(/^HTTP\/\S+\s+(\d{3})/u);
		if (!match) continue;
		status = Number(match[1]);
		headers = Object.fromEntries(lines.slice(1).flatMap((line) => {
			const separator = line.indexOf(':');
			return separator > 0
				? [[line.slice(0, separator).trim().toLocaleLowerCase('en'), line.slice(separator + 1).trim()]]
				: [];
		}));
		bodyStart = index + 1;
	}
	return { url, status, headers, body: parts.slice(bodyStart).join('\n\n') };
}

function inspectPublishedPage(post) {
	const url = `https://akibara.cl/${post.content_key}/`;
	const response = curlPage(url);
	const h1 = [...response.body.matchAll(/<h1\b[^>]*>([\s\S]*?)<\/h1>/giu)]
		.map((match) => textOnly(match[1]));
	const canonicals = [...response.body.matchAll(/<link\b[^>]*rel=["']canonical["'][^>]*href=["']([^"']+)["']/giu)]
		.map((match) => match[1]);
	const robots = [...response.body.matchAll(/<meta\b[^>]*name=["']robots["'][^>]*content=["']([^"']+)["']/giu)]
		.map((match) => match[1].toLocaleLowerCase('en'));
	const descriptions = [...response.body.matchAll(/<meta\b[^>]*name=["']description["'][^>]*content=["']([^"']*)["']/giu)]
		.map((match) => match[1]);
	const hasArticleSchema = /"@type"\s*:\s*"(?:BlogPosting|Article)"/u.test(response.body);
	if (
		response.status !== 200
		|| h1.length !== 1
		|| h1[0] !== post.fields.post_title
		|| canonicals.length !== 1
		|| canonicals[0] !== url
		|| robots.some((value) => value.includes('noindex'))
		|| descriptions.length !== 1
		|| descriptions[0] !== post.fields.rank_math_description
		|| !hasArticleSchema
	) {
		fail(`public SEO contract failed for ${post.source_post_id} (${post.content_key})`);
	}
	return {
		post_id: post.source_post_id,
		url,
		status: response.status,
		h1: h1[0],
		canonical: canonicals[0],
		meta_description: descriptions[0],
		indexable: true,
		article_schema: true,
		body_sha256: sha256(response.body),
	};
}

function collectPostSitemap(origin) {
	const index = curlPage(`${origin}/sitemap_index.xml`);
	const pages = [...index.body.matchAll(/<loc>([^<]+)<\/loc>/gu)]
		.map((match) => match[1])
		.filter((url) => /^https?:\/\/[^/]+\/post-sitemap(?:\d+)?\.xml$/u.test(url));
	if (index.status !== 200 || pages.length < 1) {
		fail('production sitemap index has no post sitemap pages');
	}
	const urls = [];
	for (const page of pages) {
		const response = curlPage(page);
		if (response.status !== 200) fail(`production post sitemap failed: ${page}`);
		urls.push(...[...response.body.matchAll(/<loc>([^<]+)<\/loc>/gu)]
			.map((match) => match[1]));
	}
	if (new Set(urls).size !== urls.length) fail('production post sitemaps contain duplicate URLs');
	return { pages, urls };
}

function main() {
	const desiredPath = localFile(option('--desired'), '--desired');
	const beforeSnapshotPath = localFile(option('--before-snapshot'), '--before-snapshot');
	const beforeStructurePath = localFile(option('--before-structure'), '--before-structure');
	const afterSnapshotPath = localFile(option('--after-snapshot'), '--after-snapshot');
	const afterStructurePath = localFile(option('--after-structure'), '--after-structure');
	const output = localFile(option('--output'), '--output', { output: true });
	const desired = readJson(desiredPath, '--desired');
	const beforeSnapshot = readJson(beforeSnapshotPath, '--before-snapshot');
	const beforeStructure = readJson(beforeStructurePath, '--before-structure');
	const afterSnapshot = readJson(afterSnapshotPath, '--after-snapshot');
	const afterStructure = readJson(afterStructurePath, '--after-structure');
	if (
		desired.value?.inventory_post_count !== 35
		|| !Array.isArray(desired.value.posts)
		|| desired.value.posts.length !== 35
		|| beforeSnapshot.value?.expected_phase !== 'before_remediation'
		|| afterSnapshot.value?.expected_phase !== 'after_remediation'
		|| beforeStructure.value?.expected_phase !== 'before_remediation'
		|| afterStructure.value?.expected_phase !== 'after_remediation'
		|| beforeSnapshot.value?.environment !== 'production'
		|| afterSnapshot.value?.environment !== 'production'
	) {
		fail('evidence phase or inventory contract is invalid');
	}
	const desiredById = new Map(desired.value.posts.map((post) => [post.source_post_id, post]));
	const beforeById = new Map(beforeSnapshot.value.posts.map((post) => [post.post_id, post]));
	const afterById = new Map(afterSnapshot.value.posts.map((post) => [post.post_id, post]));
	const beforeStructureById = new Map(beforeStructure.value.posts.map((post) => [post.post_id, post]));
	const afterStructureById = new Map(afterStructure.value.posts.map((post) => [post.post_id, post]));
	if (
		!equal([...desiredById.keys()].sort((a, b) => a - b), [...ACTIVE_IDS])
		|| afterSnapshot.value?.counts?.by_status?.publish !== 32
		|| afterSnapshot.value?.counts?.by_status?.draft !== 3
		|| afterSnapshot.value?.counts?.by_status?.trash !== 1
	) {
		fail('desired IDs or post-remediation status counts drifted');
	}
	const preservationRows = [];
	for (const postId of ACTIVE_IDS) {
		const wanted = desiredById.get(postId);
		const before = beforeById.get(postId);
		const after = afterById.get(postId);
		const beforeShape = beforeStructureById.get(postId);
		const afterShape = afterStructureById.get(postId);
		const expectedStatus = [24974, 25039, 24977].includes(postId) ? 'draft' : 'publish';
		const actualFields = {
			post_title: normalize(after?.post_title),
			post_content: normalize(after?.post_content),
			post_excerpt: normalize(after?.post_excerpt),
			rank_math_focus_keyword: normalize(after?.rank_math?.focus_keyword),
			rank_math_title: normalize(after?.rank_math?.title),
			rank_math_description: normalize(after?.rank_math?.description),
		};
		const expectedFields = Object.fromEntries(REQUIRED_FIELDS.map((field) => [
			field, normalize(wanted?.fields?.[field]),
		]));
		const structurePreservedKeys = [
			'post_date_gmt', 'post_author_id', 'post_parent', 'menu_order',
			'comment_status', 'ping_status', 'taxonomies',
		];
		const beforePreserved = Object.fromEntries(structurePreservedKeys.map((key) => [key, beforeShape?.[key]]));
		const afterPreserved = Object.fromEntries(structurePreservedKeys.map((key) => [key, afterShape?.[key]]));
		if (
			!wanted || !before || !after || !beforeShape || !afterShape
			|| before.post_slug !== wanted.content_key
			|| after.post_slug !== wanted.content_key
			|| after.post_status !== expectedStatus
			|| afterShape.post_status !== expectedStatus
			|| !equal(actualFields, expectedFields)
			|| !equal(after.featured_image, before.featured_image)
			|| after.rank_math.robots !== before.rank_math.robots
			|| after.rank_math.canonical_url !== before.rank_math.canonical_url
			|| !equal(beforePreserved, afterPreserved)
		) {
			fail(`persisted or preserved production mismatch for ${postId}`);
		}
		preservationRows.push({
			post_id: postId,
			status: expectedStatus,
			fields_sha256: sha256(JSON.stringify(actualFields)),
			structure_sha256: sha256(JSON.stringify(stable(afterPreserved))),
			featured_image_sha256: sha256(JSON.stringify(stable(after.featured_image))),
		});
	}
	const excludedBefore = beforeById.get(27460);
	const excludedAfter = afterById.get(27460);
	const excludedShapeBefore = beforeStructureById.get(27460);
	const excludedShapeAfter = afterStructureById.get(27460);
	if (
		!excludedBefore || !excludedAfter
		|| excludedAfter.post_status !== 'trash'
		|| excludedAfter.post_slug !== excludedBefore.post_slug
		|| !equal(excludedAfter, excludedBefore)
		|| !equal(excludedShapeAfter, excludedShapeBefore)
	) {
		fail('excluded post 27460 changed');
	}
	const redirect = afterSnapshot.value.rank_math_redirect_588;
	if (
		String(redirect?.id) !== '588' || String(redirect?.header_code) !== '301'
		|| redirect?.status !== 'active'
		|| redirect?.url_to !== 'https://akibara.cl/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/'
	) {
		fail('Rank Math redirect 588 drifted');
	}
	const published = desired.value.posts.filter((post) => ![24974, 25039, 24977].includes(post.source_post_id));
	const frontend = published.map((post) => inspectPublishedPage(post));
	const archive = curlPage('https://akibara.cl/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/');
	if (
		archive.status !== 301
		|| !String(archive.headers.location ?? '').includes('/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/')
	) {
		fail('public 24977 redirect is not the reviewed 301');
	}
	for (const slug of [
		'jujutsu-kaisen-en-que-orden-leer-el-manga-guia-definitiva-2026',
		'guia-de-dandadan-desde-que-tomo-leer-el-manga-despues-del-anime',
		'mangas-mas-buscados-en-chile-2026',
	]) {
		const response = curlPage(`https://akibara.cl/${slug}/`);
		if (response.status === 200) fail(`draft/excluded post is public: ${slug}`);
	}
	const sitemap = collectPostSitemap('https://akibara.cl');
	const sitemapUrls = sitemap.urls;
	const sitemapArticleUrls = sitemapUrls.filter((url) => url !== 'https://akibara.cl/blog/');
	if (
		!sitemapUrls.includes('https://akibara.cl/blog/')
		|| sitemapArticleUrls.length !== published.length
		|| published.some((post) => !sitemapArticleUrls.includes(`https://akibara.cl/${post.content_key}/`))
		|| sitemapArticleUrls.some((url) => [24974, 24977, 25039].some((postId) => (
			url.includes(`/${desiredById.get(postId).content_key}/`)
		)))
		|| sitemapArticleUrls.some((url) => url.includes('/mangas-mas-buscados-en-chile-2026/'))
	) {
		fail(`production sitemap contract failed with ${sitemapArticleUrls.length} article URLs`);
	}
	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_production_verification_20260820',
		environment: 'production',
		target_origin: 'https://akibara.cl',
		verified_at_gmt: new Date().toISOString(),
		result: 'PASS',
		sources: {
			desired_file_sha256: sha256(desired.bytes),
			before_snapshot_sha256: sha256(beforeSnapshot.bytes),
			before_structure_sha256: sha256(beforeStructure.bytes),
			after_snapshot_sha256: sha256(afterSnapshot.bytes),
			after_structure_sha256: sha256(afterStructure.bytes),
		},
		posts_verified: ACTIVE_IDS.length,
		published_frontend_verified: frontend.length,
		drafts_verified: 3,
		excluded_27460_preserved_trash: true,
		redirect_24977_to_23334: true,
		post_sitemap_urls: sitemapArticleUrls.length,
		post_sitemap_total_urls: sitemapUrls.length,
		post_sitemap_pages: sitemap.pages.length,
		managed_fields_exact: true,
		preservation_exact: true,
		preservation_rows: preservationRows,
		frontend,
	};
	writeAtomic0600(output, report);
	process.stdout.write(`${JSON.stringify({
		result: report.result,
		posts_verified: report.posts_verified,
		published_frontend_verified: report.published_frontend_verified,
		post_sitemap_urls: report.post_sitemap_urls,
		redirect_24977_to_23334: true,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
