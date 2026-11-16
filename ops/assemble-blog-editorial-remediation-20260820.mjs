#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EXPECTED_POST_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27528,
]);
const RETAINED_DRAFT_IDS = Object.freeze([24974, 25039]);
// This article documents Akibara's own preorder, cancellation and dispatch
// terms. The store's public policies are the primary official sources; adding
// an unrelated external URL would weaken, not improve, its factual support.
const SELF_SOURCED_POLICY_POST_IDS = Object.freeze([23334]);
const REQUIRED_FIELDS = Object.freeze([
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
]);
const FORBIDDEN_TEXT_PATTERNS = Object.freeze([
	['claude', /\bclaude(?:\s+code)?\b/iu],
	['anthropic', /\banthropic\b/iu],
	['chatgpt', /\bchatgpt\b/iu],
	['openai', /\bopenai\b/iu],
	['gemini', /\bgemini\b/iu],
	['firecrawl', /\bfirecrawl\b/iu],
	['ai_disclaimer', /(?:como (?:un )?modelo de lenguaje|generad[oa] por (?:una? )?(?:ia|inteligencia artificial))/iu],
	['watermark_wording', /marca(?:\s|&nbsp;)+de(?:\s|&nbsp;)+agua/iu],
	['format_or_bidi_character', /[\u00ad\u061c\u200b-\u200f\u202a-\u202e\u2060-\u206f\ufeff]/u],
	['private_use_character', /[\ue000-\uf8ff]/u],
	['replacement_character', /\ufffd/u],
	['em_or_en_dash', /[—–]/u],
	['curly_quote', /[“”‘’]/u],
]);

function fail(message) {
	throw new Error(`Blog remediation assembly failed: ${message}`);
}

function options(name) {
	const values = [];
	for (let index = 0; index < process.argv.length; index += 1) {
		if (process.argv[index] === name) values.push(process.argv[index + 1]);
	}
	return values;
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

function localInput(value, label) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const allowed = [
		path.join(ROOT, '.local-reports'),
		path.join(ROOT, '.local-checkpoints'),
	];
	if (!allowed.some((directory) => isInside(file, directory))) {
		fail(`${label} must remain under a local report directory`);
	}
	if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
		fail(`${label} must be a regular file`);
	}
	return file;
}

function localOutput(value) {
	if (!value) fail('missing --output');
	const file = path.resolve(value);
	const reportRoot = path.join(ROOT, '.local-reports');
	if (!isInside(file, reportRoot)) {
		fail('--output must remain under .local-reports');
	}
	if (fs.existsSync(file)) fail('refusing to overwrite --output');
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

function readJson(file, label) {
	const bytes = fs.readFileSync(file);
	let value;
	try {
		value = JSON.parse(bytes.toString('utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	return { file, bytes, value, sha256: sha256(bytes) };
}

function normalizedString(value, label) {
	if (typeof value !== 'string' || !value.trim()) fail(`${label} is blank`);
	return value.replace(/\r\n?/gu, '\n');
}

function plainText(value) {
	return String(value)
		.replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
		.replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ')
		.replace(/<!--[\s\S]*?-->/gu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replace(/&(?:nbsp|amp|quot|apos|#39);/giu, ' ')
		.replace(/\s+/gu, ' ')
		.trim();
}

function validateBlocks(content, postId) {
	const stack = [];
	for (const match of content.matchAll(/<!--\s*(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+\{[\s\S]*?\})?\s*(\/?)-->/giu)) {
		const [, closing, name, selfClosing] = match;
		if (selfClosing) continue;
		if (!closing) {
			stack.push(name);
			continue;
		}
		const opened = stack.pop();
		if (opened !== name) {
			fail(`post ${postId} has mismatched Gutenberg blocks: ${opened ?? '(none)'} -> ${name}`);
		}
	}
	if (stack.length > 0) {
		fail(`post ${postId} has unclosed Gutenberg blocks: ${stack.join(', ')}`);
	}
}

function validateTextPatterns(fields, postId) {
	const haystack = Object.values(fields).join('\n');
	for (const [code, expression] of FORBIDDEN_TEXT_PATTERNS) {
		if (expression.test(haystack)) fail(`post ${postId} contains ${code}`);
	}
}

function validatePost(source, expected) {
	const postId = source?.source_post_id;
	if (
		!Number.isSafeInteger(postId)
		|| postId < 1
		|| typeof source.content_key !== 'string'
		|| source.content_key !== expected.content_key
		|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(source.content_key)
		|| !Number.isSafeInteger(source.minimum_rank_math_score)
		|| source.minimum_rank_math_score < 1
		|| source.minimum_rank_math_score > 100
		|| !source.fields
		|| Object.keys(source.fields).join(',') !== REQUIRED_FIELDS.join(',')
	) {
		fail(`invalid identity or field order for post ${postId ?? '(missing)'}`);
	}
	const fields = Object.fromEntries(REQUIRED_FIELDS.map((field) => [
		field,
		normalizedString(source.fields[field], `post ${postId} ${field}`),
	]));
	validateTextPatterns(fields, postId);
	if (/<h1\b/iu.test(fields.post_content)) {
		fail(`post ${postId} contains an H1 inside the body`);
	}
	validateBlocks(fields.post_content, postId);
	const words = plainText(fields.post_content).split(/\s+/u).filter(Boolean).length;
	const minimumWords = postId === 24977 ? 25 : 250;
	if (words < minimumWords) fail(`post ${postId} has only ${words} words`);
	const h2Count = (fields.post_content.match(/<h2\b/giu) ?? []).length;
	if (postId !== 24977 && h2Count < 3) {
		fail(`post ${postId} has only ${h2Count} H2 headings`);
	}
	if (
		postId !== 24977
		&& !/<h2\b[^>]*>Fuentes oficiales consultadas<\/h2>/iu.test(fields.post_content)
	) {
		fail(`post ${postId} lacks the official sources section`);
	}
	if (
		postId !== 24977
		&& !SELF_SOURCED_POLICY_POST_IDS.includes(postId)
		&& !/<a\b[^>]+href=["']https:\/\/(?!akibara\.cl\/)[^"']+["']/iu.test(fields.post_content)
	) {
		fail(`post ${postId} has no external official source link`);
	}
	if ([...fields.post_title].length > 75) {
		fail(`post ${postId} post_title exceeds 75 characters`);
	}
	if ([...fields.rank_math_title].length > 70) {
		fail(`post ${postId} Rank Math title exceeds 70 characters`);
	}
	const descriptionLength = [...fields.rank_math_description].length;
	if (descriptionLength < 80 || descriptionLength > 170) {
		fail(`post ${postId} Rank Math description length is ${descriptionLength}`);
	}
	const excerptLength = [...plainText(fields.post_excerpt)].length;
	if (postId !== 24977 && (excerptLength < 70 || excerptLength > 220)) {
		fail(`post ${postId} excerpt length is ${excerptLength}`);
	}
	const keywords = fields.rank_math_focus_keyword.split(',')
		.map((keyword) => keyword.trim()).filter(Boolean);
	if (
		keywords.length < 1
		|| keywords.length > 5
		|| new Set(keywords.map((keyword) => keyword.toLocaleLowerCase('es-CL'))).size
			!== keywords.length
		|| fields.rank_math_focus_keyword !== keywords.join(',')
	) {
		fail(`post ${postId} has an invalid focus keyword list`);
	}
	return {
		source_post_id: postId,
		content_key: source.content_key,
		minimum_rank_math_score: source.minimum_rank_math_score,
		fields,
	};
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8',
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function main() {
	const batchFiles = options('--batch').map((value) => localInput(value, '--batch'));
	if (batchFiles.length !== 3) fail('exactly three --batch files are required');
	const source = readJson(localInput(option('--source'), '--source'), 'source');
	const sourcePosts = Array.isArray(source.value?.posts) ? source.value.posts : [];
	const sourceById = new Map(sourcePosts.map((post) => [post.source_post_id, {
		content_key: post.content_key,
		post_status: post.post_status ?? (
			RETAINED_DRAFT_IDS.includes(post.source_post_id) ? 'draft' : 'publish'
		),
	}]));
	if (
		sourcePosts.length !== EXPECTED_POST_IDS.length
		|| sourceById.size !== EXPECTED_POST_IDS.length
		|| EXPECTED_POST_IDS.some((postId) => !sourceById.has(postId))
	) {
		fail('source does not contain the reviewed 35-post active inventory');
	}

	const batchInputs = batchFiles.map((file, index) => readJson(file, `batch ${index + 1}`));
	const candidates = batchInputs.flatMap((batch, index) => {
		if (!Array.isArray(batch.value?.posts)) fail(`batch ${index + 1} has no posts array`);
		return batch.value.posts;
	});
	const candidateById = new Map();
	for (const candidate of candidates) {
		if (candidateById.has(candidate?.source_post_id)) {
			fail(`duplicate candidate post ${candidate?.source_post_id}`);
		}
		candidateById.set(candidate?.source_post_id, candidate);
	}
	if (
		candidateById.size !== EXPECTED_POST_IDS.length
		|| EXPECTED_POST_IDS.some((postId) => !candidateById.has(postId))
	) {
		fail('batches do not cover the exact reviewed 35-post inventory');
	}

	const posts = EXPECTED_POST_IDS.map((postId) => validatePost(
		candidateById.get(postId),
		sourceById.get(postId),
	));
	const desired = {
		schema_version: 1,
		desired_type: 'akibara_blog_seo_desired',
		environment: 'local_reviewed_candidate_20260820',
		inventory_post_count: posts.length,
		mutation_scope: 'content_and_seo_only',
		direct_wordpress_writes: 0,
		content_and_seo_fields: [...REQUIRED_FIELDS],
		assembled_at_gmt: new Date().toISOString(),
		sources: {
			active_inventory_path: path.relative(ROOT, source.file),
			active_inventory_file_sha256: source.sha256,
			batches: batchInputs.map((batch) => ({
				path: path.relative(ROOT, batch.file),
				file_sha256: batch.sha256,
			})),
		},
		preservation_contract: {
			post_slugs: 'preserve_exactly',
			post_dates: 'preserve_exactly',
			taxonomies: 'preserve_exactly',
			featured_images: 'preserve_exactly',
			attachment_metadata: 'preserve_exactly',
			image_alt_and_captions: 'outside_mutation_scope',
		},
		retained_drafts: RETAINED_DRAFT_IDS.map((postId) => ({ post_id: postId })),
		status_transitions: [{
			post_id: 24977,
			before: 'publish',
			after: 'draft',
			reason: 'Consolidated into canonical post 23334; preserve the existing 301.',
		}],
		excluded_posts: [{
			post_id: 27460,
			status: 'trash',
			policy: 'preserve_untouched',
		}],
		posts,
	};
	desired.payload_sha256 = sha256(canonicalJson(desired));
	const output = localOutput(option('--output'));
	writeAtomic0600(output, desired);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: posts.length,
		retained_drafts: RETAINED_DRAFT_IDS,
		status_transition: '24977:publish->draft',
		excluded_trash: 27460,
		payload_sha256: desired.payload_sha256,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
