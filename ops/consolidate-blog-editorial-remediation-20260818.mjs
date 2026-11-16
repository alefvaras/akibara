#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPORT_DIRECTORY = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818'
);

const INPUTS = Object.freeze({
	production_snapshot: path.join(REPORT_DIRECTORY, 'production-snapshot.json'),
	production_structure: path.join(REPORT_DIRECTORY, 'production-structure.json'),
	critical_remediation: path.join(REPORT_DIRECTORY, 'critical-remediation.json'),
	first_batch_remediation: path.join(
		REPORT_DIRECTORY,
		'first-batch-remediation.json'
	),
	second_batch_remediation: path.join(
		REPORT_DIRECTORY,
		'second-batch-remediation.json'
	),
});

const OUTPUTS = Object.freeze({
	desired: path.join(REPORT_DIRECTORY, 'blog-editorial-desired.json'),
	desired_sha256: path.join(
		REPORT_DIRECTORY,
		'blog-editorial-desired.sha256'
	),
	report: path.join(
		REPORT_DIRECTORY,
		'blog-editorial-consolidation-report.json'
	),
});

const EXPECTED_POST_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
]);

const EXPECTED_BATCH_IDS = Object.freeze({
	critical_remediation: Object.freeze([23329, 24974, 24977, 25039, 27460]),
	first_batch_remediation: Object.freeze([
		22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642,
		23323, 23324, 23325, 23326,
	]),
	second_batch_remediation: Object.freeze([
		23327, 23330, 23332, 23333, 23334, 23335, 23336, 24975, 24976,
		24978, 25045, 27176, 27452, 27454, 27455, 27456, 27457, 27458,
		27528,
	]),
});

const CONTENT_AND_SEO_FIELDS = Object.freeze([
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
]);

const FORBIDDEN_OUTPUT_KEYS = new Set([
	'featured_image',
	'featured_image_alt',
	'image',
	'image_alt',
	'media',
	'caption',
	'alt',
]);

const EDITORIAL_PATTERNS = Object.freeze([
	{ code: 'tool_vendor_claude', expression: /\bclaude(?:\s+code)?\b/iu },
	{ code: 'tool_vendor_anthropic', expression: /\banthropic\b/iu },
	{ code: 'tool_vendor_chatgpt', expression: /\bchatgpt\b/iu },
	{ code: 'tool_vendor_openai', expression: /\bopenai\b/iu },
	{ code: 'watermark_wording', expression: /marca(?:\s|&nbsp;)+de(?:\s|&nbsp;)+agua/iu },
	{ code: 'masterpiece_wording', expression: /\bobras?(?:\s|&nbsp;)+maestras?\b/iu },
	{ code: 'must_see_wording', expression: /\bimperdibles?\b/iu },
	{ code: 'definitive_guide_wording', expression: /gu[ií]as?(?:\s|&nbsp;)+definitivas?/iu },
	{ code: 'everything_you_need_wording', expression: /todo lo que necesitas(?: saber)?/iu },
	{ code: 'without_doubt_wording', expression: /\bsin duda\b/iu },
	{ code: 'gem_wording', expression: /\bjoyas?\b/iu },
	{ code: 'essential_wording', expression: /\bimprescindibles?\b/iu },
	{ code: 'mandatory_reading_wording', expression: /lectura(?:\s|&nbsp;)+obligatoria/iu },
	{ code: 'definitive_answer_wording', expression: /respuesta(?:\s|&nbsp;)+definitiva/iu },
	{ code: 'language_model_disclaimer', expression: /como (?:un )?modelo de lenguaje/iu },
	{
		code: 'ai_generated_disclaimer',
		expression: /generad[oa] por (?:una? )?(?:ia|inteligencia artificial)/iu,
	},
]);

const EDITORIAL_POLISH_REPLACEMENTS = Object.freeze({
	22638: Object.freeze([Object.freeze([
		'<p>La serie principal terminó en <strong>30 tomos</strong>. El corte de catálogo de Akibara registra los 30 volúmenes de Panini Argentina. Puedes revisar la colección y la disponibilidad actual en <a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen en Akibara</a>.</p>',
		'<p>La serie principal terminó en <strong>30 tomos</strong> y Akibara tiene catalogada la edición completa de Panini Argentina. Revisa la página de <a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen</a> para confirmar qué volúmenes están disponibles hoy.</p>',
	])]),
	23323: Object.freeze([Object.freeze([
		'<p>El corte de catálogo de Akibara registra los 42 tomos de la edición de Ivrea Argentina. “Catalogado” no significa que todos estén en stock al mismo tiempo: revisa <a href="https://akibara.cl/serie/my-hero-academia/">My Hero Academia en Akibara</a> para ver agotados, reposiciones y preventas.</p>',
		'<p>En Akibara están catalogados los 42 tomos de Ivrea Argentina, aunque eso no garantiza que todos tengan stock al mismo tiempo. La página de <a href="https://akibara.cl/serie/my-hero-academia/">My Hero Academia</a> muestra qué números están agotados, en reposición o en preventa.</p>',
	])]),
	23325: Object.freeze([Object.freeze([
		'<p>El corte de catálogo de Akibara registra los 23 tomos de Ivrea Argentina. La obra está completa; el stock de cada número puede variar. Consulta <a href="https://akibara.cl/serie/demon-slayer-kimetsu-no-yaiba/">Demon Slayer en Akibara</a> para ver el estado actual.</p>',
		'<p>En Akibara están catalogados los 23 tomos de Ivrea Argentina. La obra está completa, pero la disponibilidad cambia por número; revisa la página de <a href="https://akibara.cl/serie/demon-slayer-kimetsu-no-yaiba/">Demon Slayer</a> antes de comprar.</p>',
	])]),
	23327: Object.freeze([Object.freeze([
		'<p><strong>Respuesta breve:</strong> <em>Tokyo Revengers</em>, de Ken Wakui, está completo en 31 tomos y 278 capítulos. Si terminaste el arco Tenjiku del anime, retoma en el capítulo 186, incluido en el tomo 21. Empezar en el 22 deja fuera los capítulos 186, 187 y 188.</p>',
		'<p>El orden de lectura de <em>Tokyo Revengers</em> es sencillo: la obra de Ken Wakui terminó con 31 tomos y 278 capítulos. Si terminaste el arco Tenjiku del anime, retoma en el capítulo 186, incluido en el tomo 21. Empezar en el 22 deja fuera los capítulos 186, 187 y 188.</p>',
	])]),
	24974: Object.freeze([Object.freeze([
		'<p><strong>Respuesta breve:</strong> si vas a comenzar desde cero, lee <em>Jujutsu Kaisen 0</em> y después los tomos 1 al 30. El tomo 0 es la precuela oficial y forma parte de la historia; no es una novela ni un resumen de la película.</p>',
		'<p>Si partes desde cero, lee <em>Jujutsu Kaisen 0</em> antes de avanzar por los tomos 1 al 30. Es la precuela oficial de la historia, no una novela ni un resumen de la película.</p>',
	])]),
	25039: Object.freeze([Object.freeze([
		'<p><strong>Respuesta breve:</strong> después de la primera temporada de <em>Dandadan</em>, continúa en el <strong>capítulo 34</strong>, incluido en el <strong>tomo 5</strong>. El episodio 12 adapta material del comienzo de ese volumen, por lo que comprar el tomo completo implica repetir una parte breve.</p>',
		'<p>La primera temporada de <em>Dandadan</em> deja la historia en el <strong>tomo 5</strong>: el material nuevo comienza en el <strong>capítulo 34</strong>. Como el episodio 12 ya adapta el inicio de ese volumen, al comprarlo tendrás que releer un tramo breve.</p>',
	])]),
});

function fail(message) {
	throw new Error(`Blog editorial consolidation failed: ${message}`);
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

function sameArray(left, right) {
	return JSON.stringify(left) === JSON.stringify(right);
}

function sortedIds(posts) {
	return posts.map((post) => post.post_id).sort((left, right) => left - right);
}

function characterLength(value) {
	return [...value].length;
}

function plainText(value) {
	return value
		.replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
		.replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ')
		.replace(/<!--[\s\S]*?-->/gu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replace(/&(?:nbsp|amp|quot|apos|#39);/giu, ' ')
		.replace(/\s+/gu, ' ')
		.trim();
}

function wordCount(value) {
	const text = plainText(value);
	return text ? text.split(/\s+/u).length : 0;
}

function readInput(label, file) {
	if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
		fail(`missing ${label} at ${path.relative(ROOT, file)}`);
	}
	const raw = fs.readFileSync(file);
	let value;
	try {
		value = JSON.parse(raw.toString('utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	return {
		label,
		path: file,
		relative_path: path.relative(ROOT, file),
		raw,
		file_sha256: sha256(raw),
		value,
	};
}

function assertUniquePosts(label, posts, expectedCount) {
	if (!Array.isArray(posts) || posts.length !== expectedCount) {
		fail(`${label} must contain exactly ${expectedCount} posts`);
	}
	const ids = posts.map((post) => post?.post_id);
	if (
		ids.some((id) => !Number.isSafeInteger(id) || id < 1)
		|| new Set(ids).size !== ids.length
	) {
		fail(`${label} contains an invalid or duplicate post ID`);
	}
	return ids;
}

function validateSnapshot(input) {
	const snapshot = input.value;
	assertUniquePosts(input.label, snapshot?.posts, 36);
	if (
		snapshot?.schema_version !== 1
		|| snapshot?.snapshot_type !== 'akibara_blog_production_read_only'
		|| snapshot?.environment !== 'production'
		|| snapshot?.direct_writes !== 0
		|| snapshot?.counts?.total !== 36
		|| snapshot?.counts?.by_status?.publish !== 33
		|| snapshot?.counts?.by_status?.draft !== 3
		|| !sameArray(sortedIds(snapshot.posts), EXPECTED_POST_IDS)
	) {
		fail('production snapshot contract is invalid');
	}
	const calculatedPayloadHash = sha256(JSON.stringify(snapshot.posts));
	if (calculatedPayloadHash !== snapshot.payload_sha256) {
		fail('production snapshot payload hash mismatch');
	}
	for (const post of snapshot.posts) {
		if (
			!['publish', 'draft'].includes(post.post_status)
			|| typeof post.post_slug !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.post_slug)
			|| typeof post.modified_gmt !== 'string'
		) {
			fail(`invalid production snapshot row ${post.post_id}`);
		}
	}
	return snapshot;
}

function validateStructure(input, snapshotById) {
	const structure = input.value;
	assertUniquePosts(input.label, structure?.posts, 36);
	if (
		structure?.schema_version !== 1
		|| structure?.capture_type
			!== 'akibara_production_blog_structure_read_only'
		|| structure?.environment !== 'production'
		|| structure?.direct_writes !== 0
		|| !sameArray(sortedIds(structure.posts), EXPECTED_POST_IDS)
	) {
		fail('production structure contract is invalid');
	}
	const hashable = structuredClone(structure);
	delete hashable.payload_sha256;
	if (sha256(canonicalJson(hashable)) !== structure.payload_sha256) {
		fail('production structure payload hash mismatch');
	}
	for (const post of structure.posts) {
		const snapshotPost = snapshotById.get(post.post_id);
		if (
			!snapshotPost
			|| post.post_status !== snapshotPost.post_status
			|| post.post_modified_gmt !== snapshotPost.modified_gmt
			|| typeof post.post_date_gmt !== 'string'
			|| !post.post_date_gmt.trim()
		) {
			fail(`production structure drift for post ${post.post_id}`);
		}
	}
	return structure;
}

function validateCriticalInput(input, snapshot) {
	const remediation = input.value;
	assertUniquePosts(input.label, remediation?.posts, 5);
	if (
		remediation?.schema_version !== 1
		|| remediation?.remediation_type
			!== 'akibara_blog_critical_editorial_after_values'
		|| remediation?.direct_wordpress_writes !== 0
		|| remediation?.source_snapshot_payload_sha256
			!== snapshot.payload_sha256
		|| remediation?.source_snapshot_file_sha256
			!== sha256(INPUT_CACHE.production_snapshot.raw)
	) {
		fail('critical remediation contract is invalid');
	}
	const hashable = structuredClone(remediation);
	delete hashable.payload_sha256;
	if (sha256(canonicalJson(hashable)) !== remediation.payload_sha256) {
		fail('critical remediation payload hash mismatch');
	}
}

function validateFirstBatchInput(input, snapshot) {
	const remediation = input.value;
	assertUniquePosts(input.label, remediation?.posts, 12);
	if (
		remediation?.schema_version !== 1
		|| remediation?.report_type
			!== 'akibara_blog_first_batch_editorial_remediation'
		|| remediation?.environment !== 'local-only'
		|| remediation?.production_writes !== false
		|| remediation?.source_snapshot_sha256
			!== sha256(INPUT_CACHE.production_snapshot.raw)
		|| remediation?.source_payload_sha256 !== snapshot.payload_sha256
		|| remediation?.validation?.required_fields_complete !== true
	) {
		fail('first-batch remediation contract is invalid');
	}
	const sidecar = `${input.path.replace(/\.json$/u, '')}.sha256`;
	if (fs.existsSync(sidecar)) {
		const declaredHash = fs.readFileSync(sidecar, 'utf8').trim().split(/\s+/u)[0];
		if (declaredHash !== input.file_sha256) {
			fail('first-batch remediation sidecar hash mismatch');
		}
	}
}

function validateSecondBatchInput(input, snapshot) {
	const remediation = input.value;
	assertUniquePosts(input.label, remediation?.posts, 19);
	if (
		remediation?.schema_version !== '1.0.0'
		|| remediation?.artifact_type
			!== 'akibara_blog_second_batch_editorial_remediation'
		|| remediation?.environment !== 'local_only'
		|| remediation?.write_policy?.wordpress_written !== false
		|| remediation?.write_policy?.production_written !== false
		|| remediation?.source?.snapshot_payload_sha256 !== snapshot.payload_sha256
		|| remediation?.validation?.ok !== true
	) {
		fail('second-batch remediation contract is invalid');
	}
	const expectedPostIds = EXPECTED_BATCH_IDS.second_batch_remediation;
	const hashable = {
		source_snapshot_payload_sha256: snapshot.payload_sha256,
		expected_post_ids: expectedPostIds,
		posts: remediation.posts,
	};
	if (sha256(JSON.stringify(hashable)) !== remediation.payload_sha256) {
		fail('second-batch remediation payload hash mismatch');
	}
	const sidecar = `${input.path}.sha256`;
	if (fs.existsSync(sidecar)) {
		const declaredHash = fs.readFileSync(sidecar, 'utf8').trim().split(/\s+/u)[0];
		if (declaredHash !== input.file_sha256) {
			fail('second-batch remediation sidecar hash mismatch');
		}
	}
}

function normalizeFocusKeyword(postId, after) {
	const value = after.rank_math_focus_keyword
		?? after.rank_math_focus_keywords
		?? after.focus_keyword;
	const keywords = Array.isArray(value)
		? value.map((keyword) => String(keyword).trim()).filter(Boolean)
		: String(value ?? '').split(',').map((keyword) => keyword.trim()).filter(Boolean);
	if (
		keywords.length < 1
		|| keywords.length > 5
		|| new Set(keywords.map((keyword) => keyword.toLocaleLowerCase('es'))).size
			!== keywords.length
	) {
		fail(`post ${postId} must contain 1 to 5 unique focus keywords`);
	}
	return keywords.join(',');
}

function validateEditorialFields(postId, fields) {
	if (!sameArray(Object.keys(fields), CONTENT_AND_SEO_FIELDS)) {
		fail(`post ${postId} does not have the exact content/SEO field contract`);
	}
	for (const [field, value] of Object.entries(fields)) {
		if (typeof value !== 'string' || !value.trim()) {
			fail(`post ${postId} has blank ${field}`);
		}
	}
	if (characterLength(fields.post_title) > 60) {
		fail(`post ${postId} title exceeds 60 characters`);
	}
	if (characterLength(fields.rank_math_title) > 60) {
		fail(`post ${postId} Rank Math title exceeds 60 characters`);
	}
	const descriptionLength = characterLength(fields.rank_math_description);
	if (descriptionLength < 80 || descriptionLength > 155) {
		fail(
			`post ${postId} Rank Math description has ${descriptionLength} characters`
		);
	}
	const excerptLength = characterLength(fields.post_excerpt);
	if (excerptLength < 80 || excerptLength > 160) {
		fail(`post ${postId} excerpt has ${excerptLength} characters`);
	}
	if (
		/<[^>]+>/u.test(fields.post_title)
		|| /<[^>]+>/u.test(fields.post_excerpt)
		|| /<[^>]+>/u.test(fields.rank_math_title)
		|| /<[^>]+>/u.test(fields.rank_math_description)
		|| /[\r\n]/u.test(fields.rank_math_title)
		|| /[\r\n]/u.test(fields.rank_math_description)
	) {
		fail(`post ${postId} has HTML or line breaks in plain-text metadata`);
	}
	if (wordCount(fields.post_content) < 250) {
		fail(`post ${postId} has fewer than 250 editorial words`);
	}
	if (
		(fields.post_content.match(/<h2\b/giu) ?? []).length < 2
		|| !/href\s*=/iu.test(fields.post_content)
	) {
		fail(`post ${postId} requires at least two H2 headings and one link`);
	}
	const haystack = CONTENT_AND_SEO_FIELDS.map((field) => fields[field]).join('\n');
	for (const pattern of EDITORIAL_PATTERNS) {
		if (pattern.expression.test(haystack)) {
			fail(`post ${postId} contains prohibited editorial pattern ${pattern.code}`);
		}
	}
}

function applyEditorialPolish(postId, fields) {
	const replacements = EDITORIAL_POLISH_REPLACEMENTS[postId] ?? [];
	if (replacements.length === 0) return fields;
	const polished = { ...fields };
	for (const [before, after] of replacements) {
		const matches = polished.post_content.split(before).length - 1;
		if (matches !== 1) {
			fail(`post ${postId} editorial polish expected one source match, found ${matches}`);
		}
		polished.post_content = polished.post_content.replace(before, after);
	}
	return polished;
}

function assertNoForbiddenOutputKeys(value, location = 'desired') {
	if (Array.isArray(value)) {
		value.forEach((item, index) => {
			assertNoForbiddenOutputKeys(item, `${location}[${index}]`);
		});
		return;
	}
	if (!value || typeof value !== 'object') return;
	for (const [key, item] of Object.entries(value)) {
		if (FORBIDDEN_OUTPUT_KEYS.has(key.toLocaleLowerCase('en'))) {
			fail(`forbidden non-editorial key ${key} at ${location}`);
		}
		assertNoForbiddenOutputKeys(item, `${location}.${key}`);
	}
}

function extractAfter(remediationName, post) {
	const after = remediationName === 'first_batch_remediation'
		? post
		: post.after;
	if (!after || typeof after !== 'object') {
		fail(`${remediationName} post ${post.post_id} has no after state`);
	}
	return after;
}

function consolidatePosts(remediationInputs, snapshotById, structureById) {
	const ownerById = new Map();
	const desiredPosts = [];
	const partition = {};

	for (const [remediationName, input] of Object.entries(remediationInputs)) {
		const posts = input.value.posts;
		const actualIds = sortedIds(posts);
		const expectedIds = [...EXPECTED_BATCH_IDS[remediationName]]
			.sort((left, right) => left - right);
		if (!sameArray(actualIds, expectedIds)) {
			fail(`${remediationName} does not contain its exact reviewed ID set`);
		}
		partition[remediationName] = actualIds;

		for (const post of posts) {
			if (ownerById.has(post.post_id)) {
				fail(
					`post ${post.post_id} overlaps ${ownerById.get(post.post_id)} and `
					+ remediationName
				);
			}
			ownerById.set(post.post_id, remediationName);

			const sourcePost = snapshotById.get(post.post_id);
			const structurePost = structureById.get(post.post_id);
			if (!sourcePost || !structurePost) {
				fail(`post ${post.post_id} is absent from production inventory`);
			}
			if (
				post.post_slug !== sourcePost.post_slug
				|| post.post_status !== sourcePost.post_status
			) {
				fail(`post ${post.post_id} remediation changed production identity/state`);
			}

			const after = extractAfter(remediationName, post);
			const fields = applyEditorialPolish(post.post_id, {
				post_title: after.post_title,
				post_content: after.post_content,
				post_excerpt: after.post_excerpt,
				rank_math_focus_keyword: normalizeFocusKeyword(post.post_id, after),
				rank_math_title: after.rank_math_title,
				rank_math_description: after.rank_math_description,
			});
			validateEditorialFields(post.post_id, fields);
			desiredPosts.push({
				source_post_id: post.post_id,
				content_key: sourcePost.post_slug,
				// Rank Math is kept as a live-UI health signal, not as an editorial
				// acceptance proxy. The independent content/metadata audit below is
				// authoritative, so require a stable non-zero score without stuffing
				// copy or changing image ALT merely to chase the plugin heuristic.
				minimum_rank_math_score: 1,
				fields,
			});
		}
	}

	desiredPosts.sort(
		(left, right) => left.source_post_id - right.source_post_id
	);
	if (
		ownerById.size !== 36
		|| !sameArray([...ownerById.keys()].sort((a, b) => a - b), EXPECTED_POST_IDS)
		|| !sameArray(
			desiredPosts.map((post) => post.source_post_id),
			EXPECTED_POST_IDS
		)
	) {
		fail('remediation batches do not form the exact 36-post production union');
	}
	if (new Set(desiredPosts.map((post) => post.content_key)).size !== 36) {
		fail('production content keys are not unique');
	}
	return { desiredPosts, partition };
}

function latestInputTimestamp(inputs) {
	const candidates = [
		inputs.production_snapshot.value.captured_at_gmt,
		inputs.production_structure.value.captured_at_gmt,
		inputs.critical_remediation.value.generated_at,
		inputs.first_batch_remediation.value.generated_at,
		inputs.second_batch_remediation.value.generated_at_gmt,
	];
	const milliseconds = candidates.map((value) => Date.parse(value));
	if (milliseconds.some((value) => !Number.isFinite(value))) {
		fail('an input has an invalid capture/generation timestamp');
	}
	return new Date(Math.max(...milliseconds)).toISOString();
}

function assertInputsUnchanged(inputs) {
	for (const input of Object.values(inputs)) {
		const currentHash = sha256(fs.readFileSync(input.path));
		if (currentHash !== input.file_sha256) {
			fail(`${input.label} changed during consolidation; rerun the script`);
		}
	}
}

function writeAtomic(file, contents) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, contents, {
		encoding: 'utf8',
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

const INPUT_CACHE = Object.fromEntries(
	Object.entries(INPUTS).map(([label, file]) => [label, readInput(label, file)])
);

function main() {
	const snapshot = validateSnapshot(INPUT_CACHE.production_snapshot);
	const snapshotById = new Map(
		snapshot.posts.map((post) => [post.post_id, post])
	);
	const structure = validateStructure(
		INPUT_CACHE.production_structure,
		snapshotById
	);
	const structureById = new Map(
		structure.posts.map((post) => [post.post_id, post])
	);

	validateCriticalInput(INPUT_CACHE.critical_remediation, snapshot);
	validateFirstBatchInput(INPUT_CACHE.first_batch_remediation, snapshot);
	validateSecondBatchInput(INPUT_CACHE.second_batch_remediation, snapshot);

	const remediationInputs = {
		critical_remediation: INPUT_CACHE.critical_remediation,
		first_batch_remediation: INPUT_CACHE.first_batch_remediation,
		second_batch_remediation: INPUT_CACHE.second_batch_remediation,
	};
	const { desiredPosts, partition } = consolidatePosts(
		remediationInputs,
		snapshotById,
		structureById
	);
	const assembledAt = latestInputTimestamp(INPUT_CACHE);
	const inputReceipts = Object.fromEntries(
		Object.entries(INPUT_CACHE).map(([label, input]) => [label, {
			path: input.relative_path,
			file_sha256: input.file_sha256,
		}])
	);

	const desired = {
		schema_version: 1,
		desired_type: 'akibara_blog_seo_desired',
		environment: 'local_only',
		assembled_at_gmt: assembledAt,
		direct_wordpress_writes: 0,
		inventory_post_count: 36,
		mutation_scope: 'content_and_seo_only',
		rank_math_score_policy: {
			purpose: 'stable_nonzero_ui_health_signal',
			acceptance_gate: 'independent_editorial_and_metadata_audit',
			keyword_stuffing_for_score: false,
			image_alt_changes_for_score: false,
		},
		content_and_seo_fields: [...CONTENT_AND_SEO_FIELDS],
		preservation_contract: {
			post_ids: 'preserve_from_production',
			content_keys: 'preserve_production_slugs',
			post_dates_gmt: 'preserve_from_production_structure',
			post_statuses: 'preserve_except_declared_transition',
		},
		status_transitions: [
			{
				post_id: 24977,
				content_key: snapshotById.get(24977).post_slug,
				before: 'publish',
				after: 'draft',
			},
			{
				post_id: 27460,
				content_key: snapshotById.get(27460).post_slug,
				before: 'draft',
				after: 'publish',
			},
		],
		retained_drafts: [
			{
				post_id: 24974,
				content_key: snapshotById.get(24974).post_slug,
				status: 'draft',
			},
			{
				post_id: 25039,
				content_key: snapshotById.get(25039).post_slug,
				status: 'draft',
			},
		],
		sources: inputReceipts,
		posts: desiredPosts,
	};
	if (
		snapshotById.get(27460).post_status !== 'draft'
		|| snapshotById.get(24977).post_status !== 'publish'
		|| snapshotById.get(24974).post_status !== 'draft'
		|| snapshotById.get(25039).post_status !== 'draft'
	) {
		fail('declared status transition/draft retention no longer matches production');
	}
	assertNoForbiddenOutputKeys(desired);
	const desiredPayloadHash = sha256(canonicalJson(desired));
	desired.payload_sha256 = desiredPayloadHash;
	assertNoForbiddenOutputKeys(desired);

	const desiredContents = `${JSON.stringify(desired, null, 2)}\n`;
	const desiredFileHash = sha256(desiredContents);
	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_consolidation_validation',
		result: 'PASS',
		assembled_at_gmt: assembledAt,
		direct_wordpress_writes: 0,
		desired_artifact: {
			path: path.relative(ROOT, OUTPUTS.desired),
			payload_sha256: desiredPayloadHash,
			file_sha256: desiredFileHash,
		},
		inputs: inputReceipts,
		inventory: {
			production_posts: 36,
			production_published_before: 33,
			production_drafts_before: 3,
			remediated_posts: 36,
			unique_content_keys: 36,
			batch_partition: partition,
			exact_union_without_overlap: true,
		},
		field_contract: {
			allowed_fields: [...CONTENT_AND_SEO_FIELDS],
			fields_per_post: CONTENT_AND_SEO_FIELDS.length,
			non_content_mutation_keys_absent: true,
			wordpress_mutations_performed: 0,
		},
		production_preservation: {
			slugs_match_snapshot: true,
			dates_match_structure: true,
			statuses_match_snapshot_before_transition: true,
			retained_draft_ids: [24974, 25039],
			declared_transitions: [
				{
					post_id: 24977,
					before: 'publish',
					after: 'draft',
				},
				{
					post_id: 27460,
					before: 'draft',
					after: 'publish',
				},
			],
		},
		validation: {
			rank_math_score_policy: 'stable_nonzero_ui_health_signal',
			required_fields_complete: true,
			post_title_max_characters: 60,
			rank_math_title_max_characters: 60,
			rank_math_description_character_range: [80, 155],
			excerpt_character_range: [80, 160],
			focus_keyword_count_range: [1, 5],
			minimum_editorial_word_count: 250,
			minimum_h2_count: 2,
			minimum_link_count: 1,
			prohibited_editorial_patterns_absent: true,
			editorial_polish_post_ids: Object.keys(EDITORIAL_POLISH_REPLACEMENTS)
				.map(Number).sort((left, right) => left - right),
		},
	};

	assertInputsUnchanged(INPUT_CACHE);
	writeAtomic(OUTPUTS.desired, desiredContents);
	writeAtomic(
		OUTPUTS.desired_sha256,
		`${desiredFileHash}  ${path.basename(OUTPUTS.desired)}\n`
	);
	writeAtomic(OUTPUTS.report, `${JSON.stringify(report, null, 2)}\n`);
	assertInputsUnchanged(INPUT_CACHE);

	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: desiredPosts.length,
		content_and_seo_fields_per_post: CONTENT_AND_SEO_FIELDS.length,
		non_content_mutations: 0,
		status_transitions: [
			'24977:publish->draft',
			'27460:draft->publish',
		],
		retained_drafts: [24974, 25039],
		desired_payload_sha256: desiredPayloadHash,
		desired_file_sha256: desiredFileHash,
		outputs: Object.fromEntries(
			Object.entries(OUTPUTS).map(([label, file]) => [
				label,
				path.relative(ROOT, file),
			])
		),
	}, null, 2)}\n`);
}

main();
