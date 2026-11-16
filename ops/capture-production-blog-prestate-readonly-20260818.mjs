#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const OUTPUT = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/production-prestate-final.json'
);
const SHA_OUTPUT = `${OUTPUT}.sha256`;
const EXPECTED_POST_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
]);

function fail(message) {
	throw new Error(`Production prestate read-only capture failed: ${message}`);
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function canonicalJson(value) {
	if (Array.isArray(value)) {
		return `[${value.map((item) => canonicalJson(item)).join(',')}]`;
	}
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => (
			`${JSON.stringify(key)}:${canonicalJson(value[key])}`
		)).join(',')}}`;
	}
	return JSON.stringify(value);
}

function writeAtomic(file, content, mode = 0o600) {
	if (fs.existsSync(file)) fail(`refusing to overwrite ${file}`);
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, content, { encoding: 'utf8', flag: 'wx', mode });
	fs.renameSync(temporary, file);
	fs.chmodSync(file, mode);
}

function parseHeaders(raw) {
	const blocks = raw.trim().split(/\r?\n\r?\n/).filter(Boolean);
	const finalBlock = blocks.at(-1) || '';
	const lines = finalBlock.split(/\r?\n/);
	const statusLine = lines.shift() || '';
	const statusMatch = statusLine.match(/^HTTP\/\S+\s+(\d{3})/);
	const headers = {};
	for (const line of lines) {
		const separator = line.indexOf(':');
		if (separator < 1) continue;
		const key = line.slice(0, separator).trim().toLowerCase();
		const value = line.slice(separator + 1).trim();
		if (!Object.hasOwn(headers, key)) headers[key] = value;
	}
	return { status: statusMatch ? Number(statusMatch[1]) : null, headers };
}

function publicProbe(url) {
	const headerFile = path.join('/tmp', `akb-prestate-headers-${process.pid}-${crypto.randomUUID()}`);
	const bodyFile = path.join('/tmp', `akb-prestate-body-${process.pid}-${crypto.randomUUID()}`);
	try {
		const result = spawnSync('curl', [
			'--silent', '--show-error', '--max-time', '30', '--connect-timeout', '10',
			'--request', 'GET', '--dump-header', headerFile, '--output', bodyFile,
			'--user-agent', 'AkibaraReadOnlyPrestate/1.0',
			'--header', 'Cache-Control: no-cache', '--header', 'Pragma: no-cache', url,
		], { encoding: 'utf8', timeout: 40_000, maxBuffer: 1024 * 1024 });
		if (result.error) fail(`curl ${url}: ${result.error.message}`);
		if (result.status !== 0) fail(`curl ${url} exited ${result.status}: ${result.stderr.trim()}`);
		const rawHeaders = fs.readFileSync(headerFile, 'utf8');
		const body = fs.readFileSync(bodyFile);
		const parsed = parseHeaders(rawHeaders);
		const text = body.toString('utf8');
		const canonical = [...text.matchAll(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)["']/gi)]
			.map((match) => match[1]);
		return {
			url,
			method: 'GET',
			redirect_followed: false,
			status: parsed.status,
			location: parsed.headers.location || '',
			content_type: parsed.headers['content-type'] || '',
			content_length_bytes: body.length,
			body_sha256: sha256(body),
			canonical_urls: canonical,
			robots_meta: [...text.matchAll(/<meta[^>]+name=["']robots["'][^>]+content=["']([^"']+)["']/gi)]
				.map((match) => match[1]),
			cache_headers: {
				cf_cache_status: parsed.headers['cf-cache-status'] || '',
				x_litespeed_cache: parsed.headers['x-litespeed-cache'] || '',
				cache_control: parsed.headers['cache-control'] || '',
				age: parsed.headers.age || '',
			},
		};
	} finally {
		for (const file of [headerFile, bodyFile]) {
			try { fs.unlinkSync(file); } catch (error) {
				if (error?.code !== 'ENOENT') throw error;
			}
		}
	}
}

const php = String.raw`<?php
function akb_rel_path(string $absolute): string {
	$root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	if (strpos($absolute, $root) === 0) {
		return substr($absolute, strlen($root));
	}
	return basename($absolute);
}

function akb_file_fingerprint(string $absolute): array {
	$exists = is_file($absolute);
	return [
		'relative_path' => akb_rel_path($absolute),
		'exists' => $exists,
		'readable' => $exists && is_readable($absolute),
		'filesize_bytes' => $exists ? (int) filesize($absolute) : null,
		'filemtime_gmt' => $exists ? gmdate('c', (int) filemtime($absolute)) : null,
		'sha256' => $exists && is_readable($absolute) ? hash_file('sha256', $absolute) : null,
	];
}

function akb_residue_match(string $name): bool {
	return (bool) preg_match(
		'/^(?:\.htaccess\..+|\..*(?:prev|staging|backup|bak|tmp).*)$|(?:\.bak|\.backup|\.old|\.orig|\.tmp|\.temp|\.sql(?:\.gz)?|\.tar(?:\.gz)?|\.tgz|\.zip)$/i',
		$name
	);
}

function akb_scan_directory(string $absolute, string $relative, string $scope): array {
	$rows = [];
	if (!is_dir($absolute) || !is_readable($absolute)) return $rows;
	foreach (scandir($absolute) ?: [] as $name) {
		if ($name === '.' || $name === '..' || !akb_residue_match($name)) continue;
		$item = $absolute . DIRECTORY_SEPARATOR . $name;
		$is_file = is_file($item);
		$rows[] = [
			'scope' => $scope,
			'relative_path' => trim($relative . '/' . $name, '/'),
			'type' => $is_file ? 'file' : (is_dir($item) ? 'directory' : 'other'),
			'filesize_bytes' => $is_file ? (int) filesize($item) : null,
			'filemtime_gmt' => file_exists($item) ? gmdate('c', (int) filemtime($item)) : null,
			'sha256' => $is_file && is_readable($item) ? hash_file('sha256', $item) : null,
		];
	}
	return $rows;
}

$expected_ids = [
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
];
$posts = [];
$featured_ids = [];
$taxonomy_names = get_object_taxonomies('post', 'names');
sort($taxonomy_names, SORT_STRING);

foreach ($expected_ids as $post_id) {
	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'post') {
		throw new RuntimeException('Missing expected blog post ' . $post_id);
	}
	$taxonomies = [];
	foreach ($taxonomy_names as $taxonomy) {
		$terms = wp_get_object_terms($post_id, $taxonomy, ['orderby' => 'term_id', 'order' => 'ASC']);
		if (is_wp_error($terms)) throw new RuntimeException($terms->get_error_message());
		$taxonomies[$taxonomy] = array_map(static fn($term) => [
			'term_id' => (int) $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			'taxonomy' => $term->taxonomy,
			'name' => $term->name,
			'slug' => $term->slug,
			'parent' => (int) $term->parent,
		], $terms);
	}
	$featured_media = (int) get_post_thumbnail_id($post_id);
	if ($featured_media > 0) $featured_ids[$featured_media] = true;
	$posts[] = [
		'post_id' => (int) $post->ID,
		'post_slug' => $post->post_name,
		'post_status' => $post->post_status,
		'post_title' => $post->post_title,
		'post_type' => $post->post_type,
		'post_modified_gmt' => $post->post_modified_gmt,
		'permalink' => get_permalink($post),
		'featured_media' => $featured_media,
		'taxonomies' => $taxonomies,
	];
}

$attachments = [];
$attachment_ids = array_map('intval', array_keys($featured_ids));
sort($attachment_ids, SORT_NUMERIC);
foreach ($attachment_ids as $attachment_id) {
	$attachment = get_post($attachment_id);
	if (!$attachment || $attachment->post_type !== 'attachment') {
		throw new RuntimeException('Missing featured attachment ' . $attachment_id);
	}
	$file = get_attached_file($attachment_id, true);
	$metadata = wp_get_attachment_metadata($attachment_id, true);
	$referenced_by = [];
	foreach ($posts as $post) {
		if ($post['featured_media'] === $attachment_id) $referenced_by[] = $post['post_id'];
	}
	$attachments[] = [
		'attachment_id' => (int) $attachment->ID,
		'referenced_by_post_ids' => $referenced_by,
		'post_status' => $attachment->post_status,
		'post_parent' => (int) $attachment->post_parent,
		'post_author_id' => (int) $attachment->post_author,
		'post_slug' => $attachment->post_name,
		'post_title' => $attachment->post_title,
		'post_excerpt_caption' => $attachment->post_excerpt,
		'post_content_description' => $attachment->post_content,
		'post_mime_type' => $attachment->post_mime_type,
		'post_date_gmt' => $attachment->post_date_gmt,
		'post_modified_gmt' => $attachment->post_modified_gmt,
		'guid' => $attachment->guid,
		'attachment_url' => wp_get_attachment_url($attachment_id),
		'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
		'attached_file_meta' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
		'attachment_metadata' => is_array($metadata) ? $metadata : [],
		'original_file' => $file ? akb_file_fingerprint($file) : null,
	];
}

global $wpdb;
$redirect_table = $wpdb->prefix . 'rank_math_redirections';
$redirect_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $redirect_table)) === $redirect_table;
$redirect_rows = [];
$redirect_columns = [];
if ($redirect_table_exists) {
	$redirect_columns = array_values(array_map('strval', $wpdb->get_col("DESCRIBE {$redirect_table}", 0)));
	$like_source = '%que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile%';
	$like_target = '%que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber%';
	$redirect_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$redirect_table} WHERE id = %d OR sources LIKE %s OR url_to LIKE %s ORDER BY id ASC",
			588,
			$like_source,
			$like_target
		),
		ARRAY_A
	);
	if (!is_array($redirect_rows)) $redirect_rows = [];
}

$residue_rows = array_merge(
	akb_scan_directory(ABSPATH, '', 'webroot_depth_1'),
	akb_scan_directory(WP_CONTENT_DIR, 'wp-content', 'wp_content_depth_1'),
	akb_scan_directory(WP_PLUGIN_DIR, 'wp-content/plugins', 'plugins_depth_1'),
	akb_scan_directory(get_theme_root(), 'wp-content/themes', 'themes_depth_1')
);
usort($residue_rows, static fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));

echo wp_json_encode([
	'schema_version' => 1,
	'capture_type' => 'akibara_production_blog_prestate_read_only',
	'environment' => 'production',
	'direct_writes' => 0,
	'contains_credentials' => false,
	'images_or_media_mutated' => 0,
	'captured_at_gmt' => gmdate('c'),
	'wordpress' => [
		'home_url' => home_url('/'),
		'site_url' => site_url('/'),
		'core_version' => get_bloginfo('version'),
	],
	'posts' => $posts,
	'featured_attachments' => $attachments,
	'rank_math_redirects' => [
		'table_exists' => $redirect_table_exists,
		'columns' => $redirect_columns,
		'relevant_rows' => $redirect_rows,
	],
	'bounded_remote_residue_scan' => [
		'scopes' => [
			'webroot_depth_1',
			'wp_content_depth_1',
			'plugins_depth_1',
			'themes_depth_1',
		],
		'matching_items' => $residue_rows,
	],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
`;

function main() {
	const encoded = Buffer.from(php, 'utf8').toString('base64');
	const remoteCommand = [
		'cd domains/akibara.cl/public_html',
		`printf '%s' '${encoded}' | base64 -d | wp eval-file -`,
	].join(' && ');
	const result = spawnSync('ssh', ['-o', 'BatchMode=yes', 'akibara', remoteCommand], {
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
		timeout: 120_000,
	});
	if (result.error) fail(result.error.message);
	if (result.status !== 0) {
		fail(`SSH/WP-CLI exited ${result.status}: ${result.stderr.trim()}`);
	}
	let payload;
	try {
		payload = JSON.parse(result.stdout);
	} catch {
		fail('remote output is not JSON');
	}

	const urls = {
		post_22353: 'https://akibara.cl/atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/',
		post_23334: 'https://akibara.cl/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/',
		post_24977: 'https://akibara.cl/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/',
		post_27460: 'https://akibara.cl/mangas-mas-buscados-en-chile-2026/',
		post_sitemap: 'https://akibara.cl/post-sitemap.xml',
		sitemap_index: 'https://akibara.cl/sitemap_index.xml',
	};
	const publicProbes = Object.fromEntries(
		Object.entries(urls).map(([key, url]) => [key, publicProbe(url)])
	);

	const sitemapBody = spawnSync('curl', [
		'--silent', '--show-error', '--max-time', '30', '--connect-timeout', '10',
		'--user-agent', 'AkibaraReadOnlyPrestate/1.0',
		'--header', 'Cache-Control: no-cache', '--header', 'Pragma: no-cache',
		urls.post_sitemap,
	], { encoding: 'utf8', timeout: 40_000, maxBuffer: 16 * 1024 * 1024 });
	if (sitemapBody.error) fail(`post sitemap: ${sitemapBody.error.message}`);
	if (sitemapBody.status !== 0) fail(`post sitemap curl exited ${sitemapBody.status}`);
	const locs = [...sitemapBody.stdout.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
	payload.public_read_only_probes = publicProbes;
	payload.sitemap_evidence = {
		post_sitemap_url: urls.post_sitemap,
		loc_count: locs.length,
		body_sha256: sha256(sitemapBody.stdout),
		contains_post_ids_by_slug: {
			22353: locs.some((url) => url.includes('/atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/')),
			23334: locs.some((url) => url.includes('/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/')),
			24977: locs.some((url) => url.includes('/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/')),
			27460: locs.some((url) => url.includes('/mangas-mas-buscados-en-chile-2026/')),
		},
		matching_locs: locs.filter((url) => (
			url.includes('/atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/')
			|| url.includes('/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/')
			|| url.includes('/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/')
			|| url.includes('/mangas-mas-buscados-en-chile-2026/')
		)),
	};

	if (
		payload?.schema_version !== 1
		|| payload?.capture_type !== 'akibara_production_blog_prestate_read_only'
		|| payload?.environment !== 'production'
		|| payload?.direct_writes !== 0
		|| payload?.images_or_media_mutated !== 0
		|| payload?.contains_credentials !== false
		|| !Array.isArray(payload?.posts)
		|| payload.posts.length !== EXPECTED_POST_IDS.length
		|| !Array.isArray(payload?.featured_attachments)
	) fail('remote payload contract is invalid');
	const actualIds = payload.posts.map(({ post_id: id }) => id).sort((a, b) => a - b);
	const expectedIds = [...EXPECTED_POST_IDS].sort((a, b) => a - b);
	if (JSON.stringify(actualIds) !== JSON.stringify(expectedIds)) fail('post ID set drifted');
	const attachmentIds = payload.featured_attachments.map(({ attachment_id: id }) => id);
	if (new Set(attachmentIds).size !== attachmentIds.length) fail('duplicate attachment fingerprints');
	if (payload.posts.some(({ featured_media: id }) => !attachmentIds.includes(id))) {
		fail('one or more featured attachments lack a fingerprint');
	}
	if (payload.featured_attachments.some((item) => (
		!item.original_file?.exists || !item.original_file?.sha256
	))) fail('one or more featured originals could not be hashed');
	if (payload.public_read_only_probes.post_24977.status !== 301) fail('24977 no longer returns 301');
	if (!payload.public_read_only_probes.post_24977.location.includes(
		'/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/'
	)) fail('24977 redirect target drifted');
	if (payload.public_read_only_probes.post_22353.status !== 200) fail('22353 is not publicly 200');
	if (payload.public_read_only_probes.post_27460.status === 200) fail('draft 27460 is unexpectedly public');

	payload.validation = {
		result: 'PASS',
		expected_posts: EXPECTED_POST_IDS.length,
		captured_posts: payload.posts.length,
		unique_featured_attachments: payload.featured_attachments.length,
		all_featured_originals_sha256: true,
		redirect_24977_to_23334: true,
		visibility_22353_public_200: true,
		visibility_27460_not_public_200: true,
	};
	payload.payload_sha256 = sha256(canonicalJson(payload));
	const serialized = `${JSON.stringify(payload, null, 2)}\n`;
	writeAtomic(OUTPUT, serialized);
	const fileSha = sha256(serialized);
	writeAtomic(SHA_OUTPUT, `${fileSha}  ${path.basename(OUTPUT)}\n`);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: payload.posts.length,
		unique_featured_attachments: payload.featured_attachments.length,
		residue_matches: payload.bounded_remote_residue_scan.matching_items.length,
		payload_sha256: payload.payload_sha256,
		file_sha256: fileSha,
		output: OUTPUT,
	}, null, 2)}\n`);
}

main();
