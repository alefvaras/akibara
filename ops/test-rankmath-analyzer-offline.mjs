#!/usr/bin/env node

import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import {
	createRankMathOfflineAnalyzer,
	RANK_MATH_ANALYZER_VERSION,
	RANK_MATH_RESEARCH_TESTS,
} from './lib/rankmath-analyzer-offline.mjs';

const desired = JSON.parse(fs.readFileSync(new URL(
	'../.local-reports/seo-blog-score-audit/all-posts-desired.json',
	import.meta.url
)));
const productionSource = JSON.parse(fs.readFileSync(new URL(
	'../.local-reports/seo-blog-score-audit/production-source.json',
	import.meta.url
)));
const runtime = createRankMathOfflineAnalyzer();

function postById(id) {
	const post = desired.posts.find(
		(candidate) => candidate.production_post_id === id
	);
	assert.ok(post, `missing desired post ${id}`);
	return post;
}

test('official Rank Math 1.0.274.1 runtime and test list are pinned', () => {
	assert.equal(runtime.evidence.analyzer_version, RANK_MATH_ANALYZER_VERSION);
	assert.deepEqual(
		runtime.evidence.research_tests,
		[...RANK_MATH_RESEARCH_TESTS]
	);
	assert.equal(runtime.evidence.ui_adapter.paper_thumbnail_populated, false);
	assert.equal(
		runtime.evidence.ui_adapter.featured_image_appended_to_content,
		true
	);
});

test('offline adapter reproduces the calibrated Rank Math UI score', async () => {
	const analysis = await runtime.analyzePost(postById(22353));
	assert.equal(analysis.predicted_score, 78);
	assert.equal(analysis.earned_score, 83);
	assert.equal(analysis.max_score, 106);
	assert.deepEqual(
		analysis.omitted_not_applicable_tests,
		['titleSentiment']
	);
	assert.equal(analysis.tests.keywordNotUsed.max_score, 0);
});

test('focus alignment preserves every production slug', () => {
	const sourceSlugs = new Map(
		productionSource.posts.map((post) => [post.id, post.slug])
	);
	for (const post of desired.posts) {
		assert.equal(
			post.content_key,
			sourceSlugs.get(post.production_post_id),
			`slug changed for post ${post.production_post_id}`
		);
	}
	const expectedFocuses = new Map([
		[22610, 'Berserk guía para leer'],
		[22640, 'Dandadan'],
		[22641, 'mejores mangas para empezar'],
		[23323, 'My Hero Academia guía completa'],
		[23324, 'Blue Lock la revolución del manga deportivo'],
		[23325, 'Demon Slayer Kimetsu no Yaiba'],
		[23326, 'Hunter x Hunter por qué debes leer el manga'],
		[23335, 'leer los tomos de manga'],
		[24976, 'mangas seinen'],
		[
			25045,
			'cuántos tomos tiene El verano en que Hikaru murió',
		],
	]);
	for (const [id, focus] of expectedFocuses) {
		assert.equal(postById(id).fields.rank_math_focus_keyword, focus);
	}
});

test('preserve-mode post 22641 remains byte-identical to its guarded base', () => {
	const digest = crypto.createHash('sha256')
		.update(JSON.stringify(postById(22641)))
		.digest('hex');
	assert.equal(
		digest,
		'113044d1ae8882cfcc9dac089844fc73b2202afe4a280bc5af14a11056af61b7'
	);
});

test('post 22638 Gutenberg list-item repair survives Docker round trip', () => {
	const content = postById(22638).fields.post_content;
	assert.equal(
		content.includes(
			'<!-- wp:list-item -->\n<!-- wp:list-item -->'
		),
		false
	);
	assert.equal(
		content.match(/<!-- wp:list-item -->/gu)?.length,
		content.match(/<!-- \/wp:list-item -->/gu)?.length
	);
	const php = String.raw`
require "/var/www/html/wp-load.php";
$content = stream_get_contents(STDIN);
$first = parse_blocks($content);
$serialized = serialize_blocks($first);
$second = parse_blocks($serialized);
echo json_encode([
	"same" => json_encode($first) === json_encode($second),
	"top_blocks" => count($first),
	"open" => substr_count($serialized, "<!-- wp:list-item -->"),
	"close" => substr_count($serialized, "<!-- /wp:list-item -->"),
]);
`;
	const docker = spawnSync(
		'docker',
		['exec', '-i', 'akibara-wordpress-1', 'php', '-r', php],
		{
			encoding: 'utf8',
			input: content,
			maxBuffer: 2 * 1024 * 1024,
		}
	);
	assert.equal(docker.status, 0, docker.stderr);
	const result = JSON.parse(docker.stdout);
	assert.deepEqual(result, {
		same: true,
		top_blocks: 13,
		open: 2,
		close: 2,
	});
});
