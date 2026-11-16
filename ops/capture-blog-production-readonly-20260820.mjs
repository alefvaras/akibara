#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EXPECTED_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
]);

function fail(message) {
	throw new Error(`Production blog read-only capture failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function hasFlag(name) {
	return process.argv.includes(name);
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localOutput(value, label) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	if (!isInside(file, path.join(ROOT, '.local-reports'))) {
		fail(`${label} must remain under .local-reports`);
	}
	if (fs.existsSync(file) || fs.existsSync(`${file}.sha256`)) {
		fail(`${label} or its hash sidecar already exists`);
	}
	return file;
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

function writeAtomic0600(file, content) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, content, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function curlProbe(url) {
	const result = spawnSync('curl', [
		'--silent', '--show-error', '--max-time', '30', '--connect-timeout', '10',
		'--request', 'GET', '--output', '/dev/null', '--dump-header', '-',
		'--user-agent', 'AkibaraBlogEditorialReadOnly/20260820',
		'--header', 'Cache-Control: no-cache', '--header', 'Pragma: no-cache', url,
	], { encoding: 'utf8', timeout: 40_000, maxBuffer: 2 * 1024 * 1024 });
	if (result.error || result.status !== 0) {
		fail(`curl probe failed for ${url}: ${result.stderr || result.error?.message}`);
	}
	const blocks = result.stdout.trim().split(/\r?\n\r?\n/u).filter(Boolean);
	const lines = (blocks.at(-1) ?? '').split(/\r?\n/u);
	const status = Number((lines.shift() ?? '').match(/^HTTP\/\S+\s+(\d{3})/u)?.[1] ?? 0);
	const headers = Object.fromEntries(lines.flatMap((line) => {
		const separator = line.indexOf(':');
		return separator > 0
			? [[line.slice(0, separator).trim().toLocaleLowerCase('en'), line.slice(separator + 1).trim()]]
			: [];
	}));
	return { url, status, location: headers.location ?? '', x_redirect_by: headers['x-redirect-by'] ?? '' };
}

const php = String.raw`<?php
$expected_ids = [
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
];
$snapshot_posts = [];
$structure_posts = [];
foreach ($expected_ids as $post_id) {
	$post = get_post($post_id);
	if (!$post instanceof WP_Post || $post->post_type !== 'post') {
		throw new RuntimeException('Missing expected post ' . $post_id);
	}
	$thumbnail_id = (int) get_post_thumbnail_id($post_id);
	$attachment_file = $thumbnail_id > 0 ? get_attached_file($thumbnail_id, true) : '';
	$taxonomies = [];
	foreach (['category', 'post_tag'] as $taxonomy) {
		$terms = wp_get_object_terms($post_id, $taxonomy, ['orderby' => 'term_id', 'order' => 'ASC']);
		if (is_wp_error($terms)) throw new RuntimeException($terms->get_error_message());
		$taxonomies[$taxonomy] = array_map(static fn($term) => [
			'name' => $term->name,
			'slug' => $term->slug,
		], $terms);
	}
	$snapshot_posts[] = [
		'post_id' => (int) $post->ID,
		'post_status' => $post->post_status,
		'post_slug' => $post->post_name,
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
		'post_excerpt' => $post->post_excerpt,
		'modified_gmt' => $post->post_modified_gmt,
		'rank_math' => [
			'title' => (string) get_post_meta($post_id, 'rank_math_title', true),
			'description' => (string) get_post_meta($post_id, 'rank_math_description', true),
			'focus_keyword' => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
			'robots' => (string) get_post_meta($post_id, 'rank_math_robots', true),
			'canonical_url' => (string) get_post_meta($post_id, 'rank_math_canonical_url', true),
		],
		'featured_image' => [
			'attachment_id' => $thumbnail_id,
			'file' => $attachment_file ? wp_make_link_relative($attachment_file) : '',
			'url' => $thumbnail_id > 0 ? (string) wp_get_attachment_url($thumbnail_id) : '',
			'alt' => $thumbnail_id > 0 ? (string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true) : '',
		],
	];
	$structure_posts[] = [
		'post_id' => (int) $post->ID,
		'post_status' => $post->post_status,
		'post_date_gmt' => $post->post_date_gmt,
		'post_modified_gmt' => $post->post_modified_gmt,
		'post_author_id' => (int) $post->post_author,
		'post_parent' => (int) $post->post_parent,
		'menu_order' => (int) $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status' => $post->ping_status,
		'taxonomies' => $taxonomies,
	];
}
global $wpdb;
$redirect_table = $wpdb->prefix . 'rank_math_redirections';
$redirect = $wpdb->get_row(
	$wpdb->prepare("SELECT * FROM {$redirect_table} WHERE id = %d", 588),
	ARRAY_A
);
echo wp_json_encode([
	'snapshot_posts' => $snapshot_posts,
	'structure_posts' => $structure_posts,
	'redirect_588' => is_array($redirect) ? $redirect : null,
	'wordpress' => [
		'home_url' => home_url('/'),
		'site_url' => site_url('/'),
		'core_version' => get_bloginfo('version'),
	],
	'captured_at_gmt' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
`;

function main() {
	const expectAfterRemediation = hasFlag('--expect-after-remediation');
	const snapshotOutput = localOutput(option('--snapshot-output'), '--snapshot-output');
	const structureOutput = localOutput(option('--structure-output'), '--structure-output');
	if (snapshotOutput === structureOutput) fail('snapshot and structure outputs must differ');
	const encoded = Buffer.from(php, 'utf8').toString('base64');
	const remoteCommand = [
		'cd domains/akibara.cl/public_html',
		`printf '%s' '${encoded}' | base64 -d | wp eval-file -`,
	].join(' && ');
	const result = spawnSync('ssh', [
		'-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
		'-o', 'ConnectTimeout=15', 'akibara', remoteCommand,
	], { encoding: 'utf8', timeout: 120_000, maxBuffer: 32 * 1024 * 1024 });
	if (result.error || result.status !== 0) {
		fail(`SSH/WP-CLI exited ${result.status ?? 'spawn'}: ${result.stderr || result.error?.message}`);
	}
	let capture;
	try {
		capture = JSON.parse(result.stdout);
	} catch {
		fail('remote output is not valid JSON');
	}
	const snapshotPosts = capture?.snapshot_posts;
	const structurePosts = capture?.structure_posts;
	const ids = snapshotPosts?.map((post) => post.post_id).sort((a, b) => a - b);
	if (
		!Array.isArray(snapshotPosts)
		|| !Array.isArray(structurePosts)
		|| snapshotPosts.length !== EXPECTED_IDS.length
		|| structurePosts.length !== EXPECTED_IDS.length
		|| JSON.stringify(ids) !== JSON.stringify([...EXPECTED_IDS].sort((a, b) => a - b))
		|| capture?.wordpress?.home_url !== 'https://akibara.cl/'
	) {
		fail('remote inventory contract drifted');
	}
	const statusCount = Object.fromEntries(['publish', 'draft', 'trash'].map((status) => [
		status,
		snapshotPosts.filter((post) => post.post_status === status).length,
	]));
	const byId = new Map(snapshotPosts.map((post) => [post.post_id, post]));
	if (
		statusCount.publish !== (expectAfterRemediation ? 32 : 33)
		|| statusCount.draft !== (expectAfterRemediation ? 3 : 2)
		|| statusCount.trash !== 1
		|| byId.get(24977)?.post_status !== (expectAfterRemediation ? 'draft' : 'publish')
		|| byId.get(27460)?.post_status !== 'trash'
		|| byId.get(23334)?.post_status !== 'publish'
	) {
		fail(`production status contract drifted: ${JSON.stringify(statusCount)}`);
	}
	const redirect = capture.redirect_588;
	if (
		String(redirect?.id) !== '588'
		|| String(redirect?.header_code) !== '301'
		|| redirect?.status !== 'active'
		|| !String(redirect?.sources ?? '').includes('que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile')
		|| redirect?.url_to !== 'https://akibara.cl/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/'
	) {
		fail('Rank Math redirect 588 drifted');
	}
	const sourceProbe = curlProbe('https://akibara.cl/que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile/');
	const destinationProbe = curlProbe('https://akibara.cl/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/');
	if (
		sourceProbe.status !== 301
		|| !sourceProbe.location.includes('/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/')
		|| destinationProbe.status !== 200
	) {
		fail('public redirect or destination preflight failed');
	}
	const snapshot = {
		schema_version: 1,
		snapshot_type: 'akibara_blog_production_read_only',
		environment: 'production',
		target_origin: 'https://akibara.cl',
		direct_writes: 0,
		expected_phase: expectAfterRemediation ? 'after_remediation' : 'before_remediation',
		captured_at_gmt: capture.captured_at_gmt,
		counts: { total: snapshotPosts.length, by_status: statusCount },
		posts: snapshotPosts,
		rank_math_redirect_588: redirect,
		public_probes: { source_24977: sourceProbe, destination_23334: destinationProbe },
		payload_sha256: sha256(JSON.stringify(snapshotPosts)),
	};
	const structure = {
		schema_version: 1,
		capture_type: 'akibara_production_blog_structure_read_only',
		environment: 'production',
		direct_writes: 0,
		expected_phase: expectAfterRemediation ? 'after_remediation' : 'before_remediation',
		captured_at_gmt: capture.captured_at_gmt,
		posts: structurePosts,
	};
	structure.payload_sha256 = sha256(canonicalJson(structure));
	for (const [file, value] of [[snapshotOutput, snapshot], [structureOutput, structure]]) {
		const serialized = `${JSON.stringify(value, null, 2)}\n`;
		writeAtomic0600(file, serialized);
		writeAtomic0600(`${file}.sha256`, `${sha256(serialized)}  ${path.basename(file)}\n`);
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: snapshotPosts.length,
		status_count: statusCount,
		expected_phase: expectAfterRemediation ? 'after_remediation' : 'before_remediation',
		redirect_588: 'active_301',
		remote_temporary_files: 0,
		snapshot_output: snapshotOutput,
		structure_output: structureOutput,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
