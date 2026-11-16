#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REQUIRED_FIELDS = Object.freeze([
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
]);

function fail(message) {
	throw new Error(`Docker blog remediation verification failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localFile(value, label, { output = false } = {}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const allowed = [path.join(ROOT, '.local-reports'), path.join(ROOT, '.local-checkpoints')];
	if (!allowed.some((directory) => isInside(file, directory))) {
		fail(`${label} must remain under a local report directory`);
	}
	if (output) {
		if (fs.existsSync(file)) fail(`${label} already exists`);
	} else if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
		fail(`${label} must be a regular file`);
	}
	return file;
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function normalize(value) {
	return String(value ?? '').replace(/\r\n?/gu, '\n');
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

function runDockerWpPhp(targets) {
	const encodedTargets = Buffer.from(JSON.stringify(targets), 'utf8').toString('base64');
	const php = String.raw`$targets = json_decode(base64_decode('${encodedTargets}'), true, 512, JSON_THROW_ON_ERROR);
function akb_v_normalize($value) { return str_replace(["\r\n", "\r"], "\n", (string) $value); }
function akb_v_fields($post_id) {
	$post = get_post($post_id);
	return [
		'post_title' => akb_v_normalize($post->post_title),
		'post_content' => akb_v_normalize($post->post_content),
		'post_excerpt' => akb_v_normalize($post->post_excerpt),
		'rank_math_focus_keyword' => akb_v_normalize(get_post_meta($post_id, 'rank_math_focus_keyword', true)),
		'rank_math_title' => akb_v_normalize(get_post_meta($post_id, 'rank_math_title', true)),
		'rank_math_description' => akb_v_normalize(get_post_meta($post_id, 'rank_math_description', true)),
	];
}
function akb_v_preserved($post_id) {
	$post = get_post($post_id);
	$taxonomies = [];
	foreach (['category', 'post_tag'] as $taxonomy) {
		$ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
		if (is_wp_error($ids)) throw new RuntimeException($ids->get_error_message());
		$ids = array_map('intval', $ids);
		sort($ids, SORT_NUMERIC);
		$taxonomies[$taxonomy] = $ids;
	}
	return [
		'post_name' => $post->post_name,
		'post_date' => $post->post_date,
		'post_date_gmt' => $post->post_date_gmt,
		'post_author' => (int) $post->post_author,
		'post_parent' => (int) $post->post_parent,
		'menu_order' => (int) $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status' => $post->ping_status,
		'thumbnail_id' => (string) get_post_meta($post_id, '_thumbnail_id', true),
		'taxonomies' => $taxonomies,
	];
}
$rows = [];
foreach ($targets as $target) {
	$post = get_post((int) $target['local_post_id']);
	if (!$post instanceof WP_Post || $post->post_type !== 'post') {
		throw new RuntimeException('Missing local post ' . $target['local_post_id']);
	}
	$fields = akb_v_fields((int) $post->ID);
	$preserved = akb_v_preserved((int) $post->ID);
	$blocks = parse_blocks($post->post_content);
	$rendered = do_blocks($post->post_content);
	$rows[] = [
		'source_post_id' => (int) $target['source_post_id'],
		'local_post_id' => (int) $post->ID,
		'content_key' => $post->post_name,
		'post_status' => $post->post_status,
		'fields' => $fields,
		'fields_sha256' => hash('sha256', wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		'preserved_sha256' => hash('sha256', wp_json_encode($preserved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		'parsed_top_level_blocks' => count($blocks),
		'rendered_bytes' => strlen($rendered),
		'rendered_sha256' => hash('sha256', $rendered),
	];
}
$excluded = get_post(27460);
echo wp_json_encode([
	'environment_type' => wp_get_environment_type(),
	'home_url' => untrailingslashit(home_url('/')),
	'rows' => $rows,
	'excluded_27460' => $excluded instanceof WP_Post ? [
		'post_id' => (int) $excluded->ID,
		'post_status' => $excluded->post_status,
		'post_name' => $excluded->post_name,
	] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);`;
	const result = spawnSync('docker', [
		'compose', 'exec', '-T', 'cron', 'wp', '--allow-root', '--path=/var/www/html',
		'eval', php,
	], { cwd: ROOT, encoding: 'utf8', timeout: 120_000, maxBuffer: 32 * 1024 * 1024 });
	if (result.error || result.status !== 0) {
		fail(`Docker WP-CLI capture failed: ${result.stderr || result.error?.message}`);
	}
	try {
		return JSON.parse(result.stdout);
	} catch {
		fail('Docker WP-CLI capture returned invalid JSON');
	}
}

function curlPage(url, { follow = false } = {}) {
	const args = [
		'--silent', '--show-error', '--max-time', '30', '--connect-timeout', '5',
		'--dump-header', '-', '--user-agent', 'AkibaraDockerEditorialQA/20260820',
		'--header', 'Cache-Control: no-cache', '--header', 'Pragma: no-cache',
	];
	if (follow) args.push('--location', '--max-redirs', '5');
	args.push(url);
	const result = spawnSync('curl', args, {
		encoding: 'utf8', timeout: 40_000, maxBuffer: 16 * 1024 * 1024,
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
	const body = parts.slice(bodyStart).join('\n\n');
	return { url, status, headers, body };
}

function inspectPublishedPage(slug, title) {
	const url = `http://localhost:8080/${slug}/`;
	const response = curlPage(url);
	const h1 = [...response.body.matchAll(/<h1\b[^>]*>([\s\S]*?)<\/h1>/giu)]
		.map((match) => match[1].replace(/<[^>]+>/gu, '').replace(/\s+/gu, ' ').trim());
	const canonical = [...response.body.matchAll(/<link\b[^>]*rel=["']canonical["'][^>]*href=["']([^"']+)["']/giu)]
		.map((match) => match[1]);
	const robots = [...response.body.matchAll(/<meta\b[^>]*name=["']robots["'][^>]*content=["']([^"']+)["']/giu)]
		.map((match) => match[1].toLocaleLowerCase('en'));
	const hasArticleSchema = /"@type"\s*:\s*"(?:BlogPosting|Article)"/u.test(response.body);
	if (
		response.status !== 200
		|| h1.length !== 1
		|| h1[0] !== title
		|| canonical.length !== 1
		|| canonical[0] !== url
		|| robots.some((value) => value.includes('noindex'))
		|| !hasArticleSchema
	) {
		fail(`frontend contract failed for ${slug}`);
	}
	return {
		url,
		status: response.status,
		h1: h1[0],
		canonical: canonical[0],
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
		fail('Docker sitemap index has no post sitemap pages');
	}
	const urls = [];
	for (const page of pages) {
		const response = curlPage(page);
		if (response.status !== 200) fail(`Docker post sitemap failed: ${page}`);
		urls.push(...[...response.body.matchAll(/<loc>([^<]+)<\/loc>/gu)]
			.map((match) => match[1]));
	}
	if (new Set(urls).size !== urls.length) fail('Docker post sitemaps contain duplicate URLs');
	return { pages, urls };
}

function main() {
	const desiredPath = localFile(option('--desired'), '--desired');
	const receiptPath = localFile(option('--receipt'), '--receipt');
	const output = localFile(option('--output'), '--output', { output: true });
	const desiredBytes = fs.readFileSync(desiredPath);
	const receiptBytes = fs.readFileSync(receiptPath);
	const desired = JSON.parse(desiredBytes.toString('utf8'));
	const receipt = JSON.parse(receiptBytes.toString('utf8'));
	if (
		desired?.inventory_post_count !== 35
		|| !Array.isArray(desired.posts)
		|| receipt?.receipt_type !== 'akibara_blog_editorial_local_apply_20260820'
		|| receipt?.desired_file_sha256 !== sha256(desiredBytes)
		|| !Array.isArray(receipt.records)
		|| receipt.records.length !== 35
	) {
		fail('desired or receipt contract is invalid');
	}
	const desiredById = new Map(desired.posts.map((post) => [post.source_post_id, post]));
	const receiptById = new Map(receipt.records.map((row) => [row.source_post_id, row]));
	const targets = receipt.records.map((row) => ({
		source_post_id: row.source_post_id,
		local_post_id: row.local_post_id,
	}));
	const captured = runDockerWpPhp(targets);
	if (
		captured?.environment_type !== 'local'
		|| captured?.home_url !== 'http://localhost:8080'
		|| !Array.isArray(captured.rows)
		|| captured.rows.length !== 35
		|| captured?.excluded_27460?.post_status !== 'draft'
		|| captured?.excluded_27460?.post_name !== 'mangas-mas-buscados-en-chile-2026'
	) {
		fail('Docker environment or excluded-post contract drifted');
	}
	for (const row of captured.rows) {
		const wanted = desiredById.get(row.source_post_id);
		const receiptRow = receiptById.get(row.source_post_id);
		const expectedStatus = [24974, 25039, 24977].includes(row.source_post_id)
			? 'draft'
			: 'publish';
		const expectedFields = Object.fromEntries(REQUIRED_FIELDS.map((field) => [
			field, normalize(wanted?.fields?.[field]),
		]));
		if (
			!wanted
			|| !receiptRow
			|| row.local_post_id !== receiptRow.local_post_id
			|| row.content_key !== wanted.content_key
			|| row.post_status !== expectedStatus
			|| JSON.stringify(row.fields) !== JSON.stringify(expectedFields)
			|| row.fields_sha256 !== receiptRow.after_sha256
			|| row.preserved_sha256 !== receiptRow.preserved_sha256
			|| row.rendered_bytes < 1
		) {
			fail(`persisted Docker mismatch for source post ${row.source_post_id}`);
		}
	}

	const publicRows = captured.rows.filter((row) => row.post_status === 'publish');
	const frontend = publicRows.map((row) => inspectPublishedPage(
		row.content_key,
		row.fields.post_title,
	));
	const archive = curlPage('http://localhost:8080/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/');
	if (
		archive.status !== 301
		|| !String(archive.headers.location ?? '').includes('/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/')
	) {
		fail('local 24977 redirect is not the reviewed 301');
	}
	for (const slug of [
		'jujutsu-kaisen-en-que-orden-leer-el-manga-guia-definitiva-2026',
		'guia-de-dandadan-desde-que-tomo-leer-el-manga-despues-del-anime',
		'mangas-mas-buscados-en-chile-2026',
	]) {
		const response = curlPage(`http://localhost:8080/${slug}/`);
		if (response.status === 200) fail(`draft/excluded post is public in Docker: ${slug}`);
	}
	const sitemap = collectPostSitemap('http://localhost:8080');
	const sitemapUrls = sitemap.urls;
	const sitemapArticleUrls = sitemapUrls.filter((url) => url !== 'http://localhost:8080/blog/');
	if (
		!sitemapUrls.includes('http://localhost:8080/blog/')
		|| sitemapArticleUrls.length !== publicRows.length
		|| publicRows.some((row) => !sitemapArticleUrls.includes(`http://localhost:8080/${row.content_key}/`))
		|| sitemapArticleUrls.some((url) => url.includes('/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/'))
		|| sitemapArticleUrls.some((url) => url.includes('/mangas-mas-buscados-en-chile-2026/'))
	) {
		fail(`Docker sitemap contract failed with ${sitemapArticleUrls.length} article URLs`);
	}
	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_docker_verification_20260820',
		environment: 'local',
		target_origin: 'http://localhost:8080',
		verified_at_gmt: new Date().toISOString(),
		sources: {
			desired_file_sha256: sha256(desiredBytes),
			receipt_file_sha256: sha256(receiptBytes),
		},
		result: 'PASS',
		posts_verified: captured.rows.length,
		published_frontend_verified: frontend.length,
		drafts_verified: 3,
		excluded_27460_preserved_draft: true,
		redirect_24977_to_23334: true,
		post_sitemap_urls: sitemapArticleUrls.length,
		post_sitemap_total_urls: sitemapUrls.length,
		post_sitemap_pages: sitemap.pages.length,
		managed_fields_exact: true,
		preservation_hashes_exact: true,
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
