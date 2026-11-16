#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const OUTPUT = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/production-structure.json'
);

function fail(message) {
	throw new Error(`Production blog structure capture failed: ${message}`);
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

function writeAtomic(file, value) {
	if (fs.existsSync(file)) fail(`refusing to overwrite ${file}`);
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8',
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

const php = String.raw`<?php
$posts = get_posts([
	'post_type' => 'post',
	'post_status' => ['publish', 'draft'],
	'posts_per_page' => -1,
	'orderby' => 'ID',
	'order' => 'ASC',
	'suppress_filters' => false,
]);
$rows = [];
foreach ($posts as $post) {
	$taxonomies = [];
	foreach (['category', 'post_tag'] as $taxonomy) {
		$terms = wp_get_post_terms($post->ID, $taxonomy);
		if (is_wp_error($terms)) {
			throw new RuntimeException($terms->get_error_message());
		}
		$taxonomies[$taxonomy] = array_map(
			static fn($term) => [
				'name' => $term->name,
				'slug' => $term->slug,
			],
			$terms
		);
	}
	$rows[] = [
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
echo wp_json_encode([
	'schema_version' => 1,
	'capture_type' => 'akibara_production_blog_structure_read_only',
	'environment' => 'production',
	'direct_writes' => 0,
	'captured_at_gmt' => gmdate('c'),
	'posts' => $rows,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
`;

function main() {
	const encoded = Buffer.from(php, 'utf8').toString('base64');
	const remoteCommand = [
		'cd domains/akibara.cl/public_html',
		`printf '%s' '${encoded}' | base64 -d | wp eval-file -`,
	].join(' && ');
	const result = spawnSync('ssh', ['akibara', remoteCommand], {
		encoding: 'utf8',
		maxBuffer: 4 * 1024 * 1024,
		timeout: 60_000,
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
	if (
		payload?.schema_version !== 1
		|| payload?.capture_type
			!== 'akibara_production_blog_structure_read_only'
		|| payload?.environment !== 'production'
		|| payload?.direct_writes !== 0
		|| !Array.isArray(payload?.posts)
		|| payload.posts.length !== 36
	) {
		fail('remote payload contract or post count is invalid');
	}
	const ids = new Set(payload.posts.map((post) => post.post_id));
	if (ids.size !== payload.posts.length) fail('duplicate production post IDs');
	payload.payload_sha256 = sha256(canonicalJson(payload));
	writeAtomic(OUTPUT, payload);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: payload.posts.length,
		payload_sha256: payload.payload_sha256,
		output: OUTPUT,
	}, null, 2)}\n`);
}

main();
