#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DEFAULT_INPUT = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/blog-editorial-desired.json'
);
const DEFAULT_OUTPUT = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/blog-editorial-desired-audit.json'
);
const CANONICAL_ORIGIN = 'https://akibara.cl';

const LEGACY_EXPECTED_POST_IDS = Object.freeze([
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27460, 27528,
]);
const ACTIVE_EXPECTED_POST_IDS = Object.freeze(
	LEGACY_EXPECTED_POST_IDS.filter((postId) => postId !== 27460)
);

const REQUIRED_FIELDS = Object.freeze([
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
]);

const FORBIDDEN_NON_EDITORIAL_KEYS = new Set([
	'alt',
	'caption',
	'featured_image',
	'featured_image_alt',
	'image',
	'image_alt',
	'media',
	'thumbnail',
]);

const VOID_HTML_ELEMENTS = new Set([
	'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
	'meta', 'param', 'source', 'track', 'wbr',
]);

const KEYWORD_STOP_WORDS = new Set([
	'a', 'al', 'como', 'con', 'de', 'del', 'despues', 'donde', 'el', 'en',
	'la', 'las', 'leer', 'lo', 'los', 'manga', 'para', 'por', 'que', 'se',
	'seguir', 'su', 'un', 'una', 'y',
]);

const AI_AND_VENDOR_PATTERNS = Object.freeze([
	{
		code: 'tone.vendor_claude',
		severity: 'error',
		expression: /\bclaude(?:\s+code)?\b/iu,
		message: 'Claude or Claude Code residue is present.',
	},
	{
		code: 'tone.vendor_anthropic',
		severity: 'error',
		expression: /\banthropic\b/iu,
		message: 'Anthropic residue is present.',
	},
	{
		code: 'tone.vendor_chatgpt',
		severity: 'error',
		expression: /\bchatgpt\b/iu,
		message: 'ChatGPT residue is present.',
	},
	{
		code: 'tone.vendor_openai',
		severity: 'error',
		expression: /\bopenai\b/iu,
		message: 'OpenAI residue is present.',
	},
	{
		code: 'tone.ai_disclaimer',
		severity: 'error',
		expression: /(?:como (?:un )?modelo de lenguaje|generad[oa] por (?:una? )?(?:ia|inteligencia artificial)|no puedo (?:navegar|acceder|confirmar))/iu,
		message: 'An AI-assistant disclaimer or limitation is present.',
	},
	{
		code: 'tone.watermark_wording',
		severity: 'error',
		expression: /marca(?:\s|&nbsp;)+de(?:\s|&nbsp;)+agua/iu,
		message: 'Watermark wording is present.',
	},
]);

const PROHIBITED_EDITORIAL_PATTERNS = Object.freeze([
	['tone.masterpiece', /\bobras?(?:\s|&nbsp;)+maestras?\b/iu],
	['tone.must_see', /\bimperdibles?\b/iu],
	['tone.essential', /\bimprescindibles?\b/iu],
	['tone.mandatory_reading', /lecturas?(?:\s|&nbsp;)+obligatorias?/iu],
	['tone.definitive_guide', /gu[ií]as?(?:\s|&nbsp;)+definitivas?/iu],
	['tone.everything_you_need', /todo lo que necesitas(?: saber)?/iu],
	['tone.without_doubt', /\bsin duda\b/iu],
	['tone.gem', /\bjoyas?\b/iu],
	['tone.instant_classic', /cl[aá]sicos?(?:\s|&nbsp;)+instant[aá]neos?/iu],
	['tone.not_coincidence', /\bno es casualidad\b/iu],
	['tone.dive_in', /\bsum[eé]rgete\b/iu],
	['tone.cannot_miss', /no (?:te )?(?:lo|la|los|las) puedes perder/iu],
]);

const FORMULAIC_TONE_PATTERNS = Object.freeze([
	['tone.vast_world', /\ben el vasto mundo (?:de|del)\b/iu],
	['tone.captured_hearts', /ha (?:logrado )?capturad[oa] (?:el coraz[oó]n|los corazones)/iu],
	['tone.in_this_guide', /\ben esta gu[ií]a (?:veremos|exploraremos|descubrir[aá]s|te contamos)\b/iu],
	['tone.next_we_explore', /\ba continuaci[oó]n,? (?:veremos|exploraremos|descubriremos)\b/iu],
	['tone.not_only_but_also', /\bno solo .{0,100}\bsino (?:que )?tambi[eé]n\b/iu],
	['tone.generic_discover', /\bdescubre (?:por qu[eé]|todo|cu[aá]les|los secretos)\b/iu],
	['tone.absolute_best', /\b(?:el|la|los|las) mejores?\b/iu],
	['tone.absolute_most', /\b(?:el|la|los|las) m[aá]s (?:bell[oa]s?|complet[oa]s?|emocionantes?|importantes?|influyentes?|populares?|vendid[oa]s?|buscad[oa]s?)\b/iu],
]);

const CROSS_POST_FORMULAS = Object.freeze([
	{
		code: 'repetition.opening_short_answer',
		scope: 'opening',
		minimumPosts: 2,
		expression: /^respuesta breve\b/iu,
		label: 'Respuesta breve',
	},
	{
		code: 'repetition.opening_dated_status',
		scope: 'opening',
		minimumPosts: 2,
		expression: /^estado al \d{1,2} de \p{L}+ de \d{4}\b/iu,
		label: 'Estado al [fecha]',
	},
	{
		code: 'repetition.opening_finished_season',
		scope: 'opening',
		minimumPosts: 2,
		expression: /^(?:si terminaste|despu[eé]s de|para seguir).{0,90}\btemporada\b/iu,
		label: 'Continuación después de una temporada',
	},
	{
		code: 'repetition.cta_catalog_snapshot',
		scope: 'cta',
		minimumPosts: 2,
		expression: /\b(?:el )?(?:corte|snapshot) de cat[aá]logo de akibara\b/iu,
		label: 'Corte de catálogo de Akibara',
	},
	{
		code: 'repetition.cta_series_page',
		scope: 'cta',
		minimumPosts: 3,
		expression: /\bla p[aá]gina de .{1,100}\ben akibara\b/iu,
		label: 'La página de [serie] en Akibara',
	},
	{
		code: 'repetition.cta_live_price_stock',
		scope: 'cta',
		minimumPosts: 2,
		expression: /\b(?:precio, stock, despacho y medios de pago|precio y (?:la )?disponibilidad (?:actuales?|vigentes?))\b/iu,
		label: 'Precio y disponibilidad vigentes',
	},
]);

function fail(message) {
	throw new Error(`Blog editorial desired audit failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function hasFlag(name) {
	return process.argv.includes(name);
}

function usage() {
	return `Usage:
  node ops/audit-blog-editorial-desired-20260818.mjs \\
    [--input PATH] [--output PATH] [--baseline PATH] [--fail-on-warnings]

Inputs:
  - akibara_blog_seo_desired (pre-Docker editorial artifact)
  - akibara_blog_seo_official_ui_read_only (post-Docker UI capture)

The optional --baseline must be an akibara_blog_seo_desired artifact. It makes
the post-Docker audit compare all six managed fields byte-for-byte after line
ending normalization. The script never connects to WordPress and never audits
or changes images.
`;
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

function normalizeLineEndings(value) {
	return String(value ?? '').replace(/\r\n?/gu, '\n');
}

function characterLength(value) {
	return [...String(value ?? '')].length;
}

function decodeHtmlEntities(value) {
	const named = new Map([
		['amp', '&'],
		['apos', "'"],
		['gt', '>'],
		['hellip', '…'],
		['laquo', '«'],
		['lt', '<'],
		['nbsp', ' '],
		['quot', '"'],
		['raquo', '»'],
	]);
	return String(value ?? '').replace(
		/&(#(?:x[0-9a-f]+|[0-9]+)|[a-z][a-z0-9]+);/giu,
		(match, entity) => {
			if (entity.startsWith('#x') || entity.startsWith('#X')) {
				const codePoint = Number.parseInt(entity.slice(2), 16);
				return Number.isSafeInteger(codePoint)
					? String.fromCodePoint(codePoint)
					: match;
			}
			if (entity.startsWith('#')) {
				const codePoint = Number.parseInt(entity.slice(1), 10);
				return Number.isSafeInteger(codePoint)
					? String.fromCodePoint(codePoint)
					: match;
			}
			return named.get(entity.toLocaleLowerCase('en')) ?? match;
		}
	);
}

function plainText(value) {
	return decodeHtmlEntities(
		normalizeLineEndings(value)
			.replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
			.replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ')
			.replace(/<!--[\s\S]*?-->/gu, ' ')
			.replace(/<[^>]+>/gu, ' ')
	).replace(/\s+/gu, ' ').trim();
}

function words(value) {
	return plainText(value).match(/[\p{L}\p{M}\p{N}]+(?:['’×-][\p{L}\p{M}\p{N}]+)*/gu) ?? [];
}

function comparableText(value) {
	return plainText(value)
		.normalize('NFD')
		.replace(/\p{M}/gu, '')
		.toLocaleLowerCase('es')
		.replace(/[^a-z0-9]+/gu, ' ')
		.replace(/\s+/gu, ' ')
		.trim();
}

function keywordCoverage(keyword, value) {
	const keywordTokens = comparableText(keyword).split(' ').filter((token) => (
		token
		&& !KEYWORD_STOP_WORDS.has(token)
		&& (token.length >= 3 || /\d/u.test(token))
	));
	const effectiveTokens = keywordTokens.length > 0
		? [...new Set(keywordTokens)]
		: comparableText(keyword).split(' ').filter(Boolean);
	if (effectiveTokens.length === 0) return 1;
	const haystack = new Set(comparableText(value).split(' ').filter(Boolean));
	return effectiveTokens.filter((token) => haystack.has(token)).length
		/ effectiveTokens.length;
}

function truncate(value, maximum = 180) {
	const text = String(value ?? '').replace(/\s+/gu, ' ').trim();
	return characterLength(text) <= maximum
		? text
		: `${[...text].slice(0, maximum - 1).join('')}…`;
}

function locationAt(value, index) {
	const before = String(value).slice(0, index);
	const lines = before.split('\n');
	return {
		line: lines.length,
		column: [...lines.at(-1)].length + 1,
	};
}

function makeFinding(severity, code, message, details = {}) {
	return Object.fromEntries(Object.entries({
		severity,
		code,
		message,
		...details,
	}).filter(([, value]) => value !== undefined));
}

function localInput(value, label) {
	const resolved = path.resolve(value);
	if (
		!fs.existsSync(resolved)
		|| !fs.lstatSync(resolved).isFile()
		|| fs.lstatSync(resolved).isSymbolicLink()
	) {
		fail(`${label} must be a regular, non-symlink file`);
	}
	return resolved;
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localOutput(value) {
	const output = path.resolve(value);
	const allowed = ['.local-reports', '.local-checkpoints']
		.map((name) => path.join(ROOT, name));
	if (!allowed.some((directory) => isInside(output, directory))) {
		fail('--output must stay below .local-reports or .local-checkpoints');
	}
	let current = output;
	while (current !== ROOT && current !== path.dirname(current)) {
		if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) {
			fail('--output may not traverse a symlink');
		}
		current = path.dirname(current);
	}
	if (fs.existsSync(output)) fail(`refusing to overwrite ${output}`);
	return output;
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8',
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function readJsonArtifact(file, label) {
	const raw = fs.readFileSync(file);
	let value;
	try {
		value = JSON.parse(raw.toString('utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	return { file, raw, value, file_sha256: sha256(raw) };
}

function forbiddenKeyLocations(value, location = 'root', result = []) {
	if (Array.isArray(value)) {
		value.forEach((item, index) => {
			forbiddenKeyLocations(item, `${location}[${index}]`, result);
		});
		return result;
	}
	if (!value || typeof value !== 'object') return result;
	for (const [key, item] of Object.entries(value)) {
		if (FORBIDDEN_NON_EDITORIAL_KEYS.has(key.toLocaleLowerCase('en'))) {
			result.push(`${location}.${key}`);
		}
		forbiddenKeyLocations(item, `${location}.${key}`, result);
	}
	return result;
	}

function adaptArtifact(input, { requireDesired = false } = {}) {
	const value = input.value;
	const contractFindings = [];
	let kind;
	let posts;
	let environment;
	let targetOrigin;
	let expectedPostIds;

	if (value?.desired_type === 'akibara_blog_seo_desired') {
		kind = 'desired';
		environment = value.environment;
		targetOrigin = CANONICAL_ORIGIN;
		if (value.schema_version !== 1) {
			contractFindings.push(makeFinding(
				'error', 'contract.schema_version', 'Desired schema_version must be 1.'
			));
		}
		expectedPostIds = value.inventory_post_count === 35
			? ACTIVE_EXPECTED_POST_IDS
			: LEGACY_EXPECTED_POST_IDS;
		if (![35, 36].includes(value.inventory_post_count)) {
			contractFindings.push(makeFinding(
				'error', 'contract.inventory_count', 'Desired inventory_post_count must be 35 active posts or the legacy 36-post inventory.'
			));
		}
		if (value.mutation_scope !== 'content_and_seo_only') {
			contractFindings.push(makeFinding(
				'error', 'contract.mutation_scope', 'Desired mutation scope must be content_and_seo_only.'
			));
		}
		if (value.direct_wordpress_writes !== 0) {
			contractFindings.push(makeFinding(
				'error', 'contract.direct_writes', 'Desired artifact must declare zero WordPress writes.'
			));
		}
		const declaredFields = value.content_and_seo_fields ?? [];
		if (JSON.stringify(declaredFields) !== JSON.stringify(REQUIRED_FIELDS)) {
			contractFindings.push(makeFinding(
				'error', 'contract.managed_fields', 'Desired managed-field contract is not the exact six-field editorial set.'
			));
		}
		if (typeof value.payload_sha256 !== 'string') {
			contractFindings.push(makeFinding(
				'error', 'contract.payload_hash_missing', 'Desired payload hash is missing.'
			));
		} else {
			const hashable = structuredClone(value);
			delete hashable.payload_sha256;
			if (sha256(canonicalJson(hashable)) !== value.payload_sha256) {
				contractFindings.push(makeFinding(
					'error', 'contract.payload_hash_mismatch', 'Desired payload hash does not match its content.'
				));
			}
		}
		posts = Array.isArray(value.posts) ? value.posts.map((post) => ({
			post_id: post?.source_post_id,
			content_key: post?.content_key,
			public_url: `${CANONICAL_ORIGIN}/${post?.content_key ?? ''}/`,
			fields: post?.fields,
		})) : [];
	} else if (
		value?.capture_type === 'akibara_blog_seo_official_ui_read_only'
	) {
		kind = 'official_ui_capture';
		environment = value.environment;
		targetOrigin = value.target_origin;
		expectedPostIds = value.inventory_post_count === 35
			? ACTIVE_EXPECTED_POST_IDS
			: LEGACY_EXPECTED_POST_IDS;
		if (requireDesired) {
			fail('the --baseline artifact must be akibara_blog_seo_desired');
		}
		if (value.schema_version !== 1) {
			contractFindings.push(makeFinding(
				'error', 'contract.schema_version', 'UI capture schema_version must be 1.'
			));
		}
		if (value.capture_scope !== 'content_and_seo') {
			contractFindings.push(makeFinding(
				'error', 'contract.capture_scope', 'UI capture must use content_and_seo scope.'
			));
		}
		if (![35, 36].includes(value.inventory_post_count)) {
			contractFindings.push(makeFinding(
				'error', 'contract.inventory_count', 'UI capture inventory_post_count must be 35 active posts or the legacy 36-post inventory.'
			));
		}
		if (value.direct_writes !== 0) {
			contractFindings.push(makeFinding(
				'error', 'contract.direct_writes', 'UI capture must declare zero direct writes.'
			));
		}
		posts = Array.isArray(value.posts) ? value.posts.map((post) => ({
			post_id: post?.post_id,
			content_key: post?.content_key,
			public_url: post?.public_url,
			fields: post?.fields,
		})) : [];
	} else {
		fail('unsupported input; expected desired artifact or official UI capture');
	}

	if (requireDesired && kind !== 'desired') {
		fail('the --baseline artifact must be akibara_blog_seo_desired');
	}
	if (!Array.isArray(value.posts)) {
		contractFindings.push(makeFinding(
			'error', 'contract.posts_missing', 'Artifact posts must be an array.'
		));
	}
	if (posts.length !== expectedPostIds.length) {
		contractFindings.push(makeFinding(
			'error', 'contract.post_count', `Artifact contains ${posts.length} posts; expected ${expectedPostIds.length}.`
		));
	}

	const forbiddenLocations = forbiddenKeyLocations(value);
	for (const location of forbiddenLocations) {
		contractFindings.push(makeFinding(
			'error',
			'contract.non_editorial_key',
			'The content-only artifact contains a forbidden image/media key.',
			{ location }
		));
	}

	return {
		kind,
		environment,
		target_origin: targetOrigin,
		posts,
		expected_post_ids: expectedPostIds,
		contract_findings: contractFindings,
	};
}

function auditInventory(posts, expectedPostIds, { enforceExpectedIdSet = true } = {}) {
	const findings = [];
	const ids = posts.map((post) => post.post_id);
	const keys = posts.map((post) => post.content_key);
	const validIds = ids.filter((id) => Number.isSafeInteger(id) && id > 0);
	const sortedIds = [...new Set(validIds)].sort((left, right) => left - right);
	if (validIds.length !== ids.length) {
		findings.push(makeFinding(
			'error', 'inventory.invalid_post_id', 'One or more post IDs are invalid.'
		));
	}
	if (new Set(validIds).size !== validIds.length) {
		findings.push(makeFinding(
			'error', 'inventory.duplicate_post_id', 'Post IDs are not unique.'
		));
	}
	if (
		enforceExpectedIdSet
		&& JSON.stringify(sortedIds) !== JSON.stringify(expectedPostIds)
	) {
		findings.push(makeFinding(
			'error',
			'inventory.unexpected_id_set',
			`Post IDs do not match the reviewed ${expectedPostIds.length}-post inventory.`,
			{
				missing_post_ids: expectedPostIds.filter((id) => !sortedIds.includes(id)),
				extra_post_ids: sortedIds.filter((id) => !expectedPostIds.includes(id)),
			}
		));
	}
	if (
		keys.some((key) => (
			typeof key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(key)
		))
	) {
		findings.push(makeFinding(
			'error', 'inventory.invalid_content_key', 'One or more content keys are invalid slugs.'
		));
	}
	if (new Set(keys).size !== keys.length) {
		findings.push(makeFinding(
			'error', 'inventory.duplicate_content_key', 'Content keys are not unique.'
		));
	}
	return findings;
}

function auditPostIdentity(post, artifact) {
	const findings = [];
	if (artifact.kind !== 'official_ui_capture') return findings;
	let target;
	let publicUrl;
	try {
		target = new URL(artifact.target_origin);
		publicUrl = new URL(post.public_url);
	} catch (error) {
		findings.push(makeFinding(
			'error',
			'inventory.invalid_public_url',
			`Captured public URL or target origin is invalid: ${error.message}`,
			{ public_url: truncate(post.public_url) }
		));
		return findings;
	}
	const expectedPath = `/${post.content_key}/`;
	if (publicUrl.origin !== target.origin || publicUrl.pathname !== expectedPath) {
		findings.push(makeFinding(
			'error',
			'inventory.public_url_identity_mismatch',
			'Captured public URL does not match its reviewed origin and content key.',
			{
				public_url: publicUrl.href,
				expected_origin: target.origin,
				expected_path: expectedPath,
			}
		));
	}
	return findings;
}

function auditWordPressBlocks(content) {
	const findings = [];
	const stack = [];
	const counts = {};
	let parsedMarkers = 0;
	const commentExpression = /<!--([\s\S]*?)-->/gu;
	for (const match of content.matchAll(commentExpression)) {
		const body = match[1].trim();
		if (!/^\/?wp:/iu.test(body)) continue;
		parsedMarkers += 1;
		const location = locationAt(content, match.index);
		const closing = body.match(/^\/wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)*)\s*$/iu);
		if (closing) {
			const name = closing[1].toLocaleLowerCase('en');
			counts[name] ??= { opened: 0, closed: 0, self_closing: 0 };
			counts[name].closed += 1;
			if (stack.length === 0) {
				findings.push(makeFinding(
					'error', 'blocks.orphan_close', `Orphan closing WordPress block ${name}.`,
					{ ...location, block: name }
				));
				continue;
			}
			const current = stack.at(-1);
			if (current.name !== name) {
				findings.push(makeFinding(
					'error',
					'blocks.mismatched_close',
					`WordPress block ${name} closes while ${current.name} is open.`,
					{ ...location, block: name, expected_block: current.name }
				));
				const matchingIndex = stack.map((item) => item.name).lastIndexOf(name);
				if (matchingIndex !== -1) stack.splice(matchingIndex);
				continue;
			}
			stack.pop();
			continue;
		}

		const opening = body.match(/^wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)*)([\s\S]*)$/iu);
		if (!opening) {
			findings.push(makeFinding(
				'error', 'blocks.malformed_marker', 'Malformed WordPress block marker.',
				{ ...location, sample: truncate(match[0]) }
			));
			continue;
		}
		const name = opening[1].toLocaleLowerCase('en');
		let remainder = opening[2].trim();
		const selfClosing = /\/\s*$/u.test(remainder);
		if (selfClosing) remainder = remainder.replace(/\/\s*$/u, '').trim();
		counts[name] ??= { opened: 0, closed: 0, self_closing: 0 };
		if (selfClosing) counts[name].self_closing += 1;
		else counts[name].opened += 1;
		if (remainder) {
			try {
				const attributes = JSON.parse(remainder);
				if (!attributes || typeof attributes !== 'object' || Array.isArray(attributes)) {
					throw new TypeError('attributes are not an object');
				}
			} catch (error) {
				findings.push(makeFinding(
					'error',
					'blocks.invalid_attributes_json',
					`WordPress block ${name} has invalid JSON attributes: ${error.message}`,
					{ ...location, block: name, sample: truncate(remainder) }
				));
			}
		}
		if (!selfClosing) stack.push({ name, ...location });
	}

	const rawMarkerCount = (content.match(/<!--\s*\/?wp:/giu) ?? []).length;
	if (rawMarkerCount !== parsedMarkers) {
		findings.push(makeFinding(
			'error',
			'blocks.unterminated_marker',
			'At least one WordPress block marker is not terminated with -->.',
			{ raw_marker_count: rawMarkerCount, parsed_marker_count: parsedMarkers }
		));
	}
	for (const block of stack) {
		findings.push(makeFinding(
			'error',
			'blocks.unclosed_open',
			`WordPress block ${block.name} is not closed.`,
			{ line: block.line, column: block.column, block: block.name }
		));
	}
	return { findings, counts, parsed_marker_count: parsedMarkers };
}

function findTagEnd(content, start) {
	let quote = '';
	for (let index = start + 1; index < content.length; index += 1) {
		const character = content[index];
		if (quote) {
			if (character === quote) quote = '';
			continue;
		}
		if (character === '"' || character === "'") {
			quote = character;
			continue;
		}
		if (character === '>') return index;
	}
	return -1;
}

function tokenizeHtml(content) {
	const tokens = [];
	const findings = [];
	let cursor = 0;
	while (cursor < content.length) {
		const start = content.indexOf('<', cursor);
		if (start === -1) break;
		if (content.startsWith('<!--', start)) {
			const end = content.indexOf('-->', start + 4);
			if (end === -1) {
				findings.push(makeFinding(
					'error', 'html.unterminated_comment', 'HTML comment is not terminated.',
					{ ...locationAt(content, start) }
				));
				break;
			}
			cursor = end + 3;
			continue;
		}
		const end = findTagEnd(content, start);
		if (end === -1) {
			findings.push(makeFinding(
				'error', 'html.unterminated_tag', 'HTML tag is not terminated.',
				{ ...locationAt(content, start), sample: truncate(content.slice(start)) }
			));
			break;
		}
		const raw = content.slice(start, end + 1);
		cursor = end + 1;
		if (/^<\s*[!?]/u.test(raw)) continue;
		const parsed = raw.match(/^<\s*(\/?)\s*([a-z][a-z0-9:-]*)([\s\S]*?)>$/iu);
		if (!parsed) {
			findings.push(makeFinding(
				'error', 'html.malformed_tag', 'Malformed HTML tag.',
				{ ...locationAt(content, start), sample: truncate(raw) }
			));
			continue;
		}
		const closing = parsed[1] === '/';
		const name = parsed[2].toLocaleLowerCase('en');
		const selfClosing = !closing && /\/\s*>$/u.test(raw);
		tokens.push({
			name,
			closing,
			self_closing: selfClosing,
			attributes_raw: parsed[3],
			raw,
			start,
			end: end + 1,
			...locationAt(content, start),
		});
	}
	return { tokens, findings };
}

function auditHtml(content) {
	const tokenized = tokenizeHtml(content);
	const findings = [...tokenized.findings];
	const stack = [];
	const startCounts = {};
	for (const token of tokenized.tokens) {
		if (!token.closing) {
			startCounts[token.name] = (startCounts[token.name] ?? 0) + 1;
			if (['script', 'iframe', 'object', 'embed'].includes(token.name)) {
				findings.push(makeFinding(
					'error',
					'html.disallowed_element',
					`Disallowed ${token.name} element is present in editorial content.`,
					{ line: token.line, column: token.column, tag: token.name }
				));
			}
			if (
				!token.self_closing
				&& !VOID_HTML_ELEMENTS.has(token.name)
			) {
				stack.push(token);
			}
			continue;
		}
		if (VOID_HTML_ELEMENTS.has(token.name)) {
			findings.push(makeFinding(
				'error',
				'html.void_element_close',
				`Void element ${token.name} must not have a closing tag.`,
				{ line: token.line, column: token.column, tag: token.name }
			));
			continue;
		}
		if (stack.length === 0) {
			findings.push(makeFinding(
				'error', 'html.orphan_close', `Orphan closing HTML tag ${token.name}.`,
				{ line: token.line, column: token.column, tag: token.name }
			));
			continue;
		}
		const current = stack.at(-1);
		if (current.name !== token.name) {
			findings.push(makeFinding(
				'error',
				'html.mismatched_close',
				`Closing ${token.name} appears while ${current.name} is open.`,
				{
					line: token.line,
					column: token.column,
					tag: token.name,
					expected_tag: current.name,
				}
			));
			const matchingIndex = stack.map((item) => item.name).lastIndexOf(token.name);
			if (matchingIndex !== -1) stack.splice(matchingIndex);
			continue;
		}
		stack.pop();
	}
	for (const token of stack) {
		findings.push(makeFinding(
			'error', 'html.unclosed_open', `HTML tag ${token.name} is not closed.`,
			{ line: token.line, column: token.column, tag: token.name }
		));
	}
	return {
		findings,
		tokens: tokenized.tokens,
		start_counts: startCounts,
	};
}

function auditBlockHtmlParity(blocks, html) {
	const findings = [];
	const parity = [
		['paragraph', ['p']],
		['heading', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']],
		['list', ['ul', 'ol']],
		['list-item', ['li']],
		['quote', ['blockquote']],
	];
	for (const [block, elements] of parity) {
		const blockCount = (blocks.counts[block]?.opened ?? 0)
			+ (blocks.counts[block]?.self_closing ?? 0);
		// Some legacy posts are valid classic-editor HTML without Gutenberg
		// comments, and Gutenberg also permits list innerHTML without one
		// wp:list-item marker per li. Parity is meaningful only for block types
		// that the serialized content actually declares.
		if (blockCount === 0) continue;
		const htmlCount = elements.reduce(
			(sum, element) => sum + (html.start_counts[element] ?? 0),
			0
		);
		if (blockCount !== htmlCount) {
			findings.push(makeFinding(
				'error',
				'blocks.html_wrapper_mismatch',
				`WordPress ${block} blocks (${blockCount}) do not match their HTML wrappers (${htmlCount}).`,
				{ block, elements, block_count: blockCount, html_count: htmlCount }
			));
		}
	}
	return findings;
}

function pairedElements(content, tokens, wantedNames) {
	const wanted = new Set(wantedNames);
	const stack = [];
	const pairs = [];
	for (const token of tokens) {
		if (!wanted.has(token.name)) continue;
		if (!token.closing && !token.self_closing) {
			stack.push(token);
			continue;
		}
		if (token.closing) {
			const index = stack.map((item) => item.name).lastIndexOf(token.name);
			if (index === -1) continue;
			const opening = stack[index];
			stack.splice(index);
			pairs.push({
				name: token.name,
				opening,
				closing: token,
				html: content.slice(opening.end, token.start),
				text: plainText(content.slice(opening.end, token.start)),
			});
		}
	}
	return pairs.sort((left, right) => left.opening.start - right.opening.start);
}

function auditHeadings(content, html) {
	const findings = [];
	const headingNames = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
	const headings = pairedElements(content, html.tokens, headingNames).map((item) => ({
		level: Number(item.name.slice(1)),
		text: item.text,
		line: item.opening.line,
		column: item.opening.column,
	}));
	const h1Count = html.start_counts.h1 ?? 0;
	if (h1Count > 0) {
		findings.push(makeFinding(
			'error',
			'headings.content_h1',
			'Post content contains H1; the single H1 must be supplied only by the theme template.',
			{ content_h1_count: h1Count }
		));
	}
	const h2Count = html.start_counts.h2 ?? 0;
	if (h2Count < 2) {
		findings.push(makeFinding(
			'error', 'headings.too_few_h2', 'Editorial content requires at least two H2 headings.',
			{ h2_count: h2Count }
		));
	}
	if (headings.length > 0 && headings[0].level !== 2) {
		findings.push(makeFinding(
			'error',
			'headings.first_not_h2',
			'The first heading inside post content must be H2.',
			{ first_heading_level: headings[0].level, first_heading: headings[0].text }
		));
	}
	let previousLevel;
	for (const heading of headings) {
		if (!heading.text) {
			findings.push(makeFinding(
				'error', 'headings.empty', 'Heading text is empty.',
				{ line: heading.line, column: heading.column, level: heading.level }
			));
		}
		if (previousLevel && heading.level > previousLevel + 1) {
			findings.push(makeFinding(
				'error',
				'headings.level_jump',
				`Heading level jumps from H${previousLevel} to H${heading.level}.`,
				{ line: heading.line, heading: heading.text }
			));
		}
		previousLevel = heading.level;
	}
	const normalized = new Map();
	for (const heading of headings) {
		const key = comparableText(heading.text);
		if (!key) continue;
		const matches = normalized.get(key) ?? [];
		matches.push(heading);
		normalized.set(key, matches);
	}
	for (const matches of normalized.values()) {
		if (matches.length < 2) continue;
		findings.push(makeFinding(
			'warning',
			'headings.duplicate_text',
			'A heading is repeated inside the same post.',
			{ heading: matches[0].text, count: matches.length }
		));
	}
	return {
		findings,
		template_h1_assumed: 1,
		content_h1_count: h1Count,
		effective_h1_count_assumed: h1Count + 1,
		h2_count: h2Count,
		headings,
	};
}

function parseAttributes(raw) {
	const attributes = new Map();
	const duplicates = [];
	const expression = /([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/gu;
	for (const match of raw.replace(/\/\s*$/u, '').matchAll(expression)) {
		const name = match[1].toLocaleLowerCase('en');
		const value = decodeHtmlEntities(match[2] ?? match[3] ?? match[4] ?? '');
		if (attributes.has(name)) duplicates.push(name);
		attributes.set(name, value);
	}
	return { attributes, duplicates };
}

function classifyHref(href, targetOrigin) {
	const trimmed = href.trim();
	if (!trimmed) return { kind: 'invalid', reason: 'empty' };
	if (trimmed === '#' || /^javascript:/iu.test(trimmed) || /^data:/iu.test(trimmed)) {
		return { kind: 'invalid', reason: 'placeholder_or_unsafe_scheme' };
	}
	if (trimmed.startsWith('#')) return { kind: 'fragment', normalized_url: trimmed };
	if (/^mailto:/iu.test(trimmed)) return { kind: 'email', normalized_url: trimmed };
	if (/^tel:/iu.test(trimmed)) return { kind: 'telephone', normalized_url: trimmed };
	if (/\s/u.test(trimmed)) return { kind: 'invalid', reason: 'whitespace' };
	let url;
	try {
		url = new URL(trimmed, CANONICAL_ORIGIN);
	} catch (error) {
		return { kind: 'invalid', reason: error.message };
	}
	if (!['http:', 'https:'].includes(url.protocol)) {
		return { kind: 'invalid', reason: `unsupported protocol ${url.protocol}` };
	}
	const targetHost = (() => {
		try {
			return new URL(targetOrigin).hostname.toLocaleLowerCase('en');
		} catch {
			return '';
		}
	})();
	const hostname = url.hostname.toLocaleLowerCase('en');
	const internalHosts = new Set(['akibara.cl', 'www.akibara.cl']);
	if (targetHost) internalHosts.add(targetHost);
	return {
		kind: internalHosts.has(hostname) ? 'internal' : 'external',
		normalized_url: url.href,
		protocol: url.protocol,
		hostname,
		pathname: url.pathname,
		search: url.search,
		hash: url.hash,
		has_credentials: Boolean(url.username || url.password),
		is_root_relative: trimmed.startsWith('/') && !trimmed.startsWith('//'),
		is_bare_relative: !/^(?:[a-z][a-z0-9+.-]*:|\/|#|\?)/iu.test(trimmed),
	};
}

function auditLinks(content, html, targetOrigin) {
	const findings = [];
	const anchors = pairedElements(content, html.tokens, ['a']);
	const links = [];
	for (const anchor of anchors) {
		const parsed = parseAttributes(anchor.opening.attributes_raw);
		for (const duplicate of parsed.duplicates) {
			findings.push(makeFinding(
				'error', 'links.duplicate_attribute', `Anchor repeats attribute ${duplicate}.`,
				{ line: anchor.opening.line, column: anchor.opening.column, attribute: duplicate }
			));
		}
		const href = parsed.attributes.get('href');
		if (href === undefined) {
			findings.push(makeFinding(
				'error', 'links.missing_href', 'Anchor has no href attribute.',
				{ line: anchor.opening.line, column: anchor.opening.column, text: truncate(anchor.text) }
			));
			continue;
		}
		const classification = classifyHref(href, targetOrigin);
		const record = {
			href,
			text: anchor.text,
			kind: classification.kind,
			normalized_url: classification.normalized_url,
			line: anchor.opening.line,
		};
		links.push(record);
		if (!anchor.text) {
			findings.push(makeFinding(
				'error', 'links.empty_anchor_text', 'Link has no accessible text.',
				{ line: anchor.opening.line, href: truncate(href) }
			));
		} else if (/^(?:aqu[ií]|clic aqu[ií]|ver m[aá]s|este enlace)$/iu.test(anchor.text.trim())) {
			findings.push(makeFinding(
				'warning', 'links.ambiguous_anchor_text', 'Link text is not descriptive.',
				{ line: anchor.opening.line, href: truncate(href), text: anchor.text }
			));
		}
		if (classification.kind === 'invalid') {
			findings.push(makeFinding(
				'error', 'links.invalid_url', 'Link URL is empty, malformed, unsafe or a placeholder.',
				{ line: anchor.opening.line, href: truncate(href), reason: classification.reason }
			));
			continue;
		}
		if (classification.has_credentials) {
			findings.push(makeFinding(
				'error', 'links.url_credentials', 'Link URL embeds credentials.',
				{ line: anchor.opening.line, href: truncate(href) }
			));
		}
		if (classification.kind === 'internal') {
			if (classification.is_bare_relative) {
				findings.push(makeFinding(
					'warning', 'links.bare_relative_url', 'Internal URL should be root-relative or canonical absolute.',
					{ line: anchor.opening.line, href: truncate(href) }
				));
			}
			if (
				classification.hostname === 'www.akibara.cl'
				|| (
					classification.hostname === 'akibara.cl'
					&& classification.protocol !== 'https:'
				)
			) {
				findings.push(makeFinding(
					'warning',
					'links.noncanonical_internal_origin',
					'Absolute Akibara URL should use https://akibara.cl.',
					{ line: anchor.opening.line, href: truncate(href) }
				));
			}
			if (/^(?:_blank)$/iu.test(parsed.attributes.get('target') ?? '')) {
				findings.push(makeFinding(
					'warning', 'links.internal_new_tab', 'Internal link unnecessarily opens a new tab.',
					{ line: anchor.opening.line, href: truncate(href) }
				));
			}
		}
		if (classification.kind === 'external') {
			if (classification.protocol !== 'https:') {
				findings.push(makeFinding(
					'warning', 'links.external_http', 'External source does not use HTTPS.',
					{ line: anchor.opening.line, href: truncate(href) }
				));
			}
			if ((parsed.attributes.get('target') ?? '').toLocaleLowerCase('en') === '_blank') {
				const rel = new Set(
					(parsed.attributes.get('rel') ?? '').toLocaleLowerCase('en')
						.split(/\s+/u).filter(Boolean)
				);
				if (!rel.has('noopener')) {
					findings.push(makeFinding(
						'error',
						'links.blank_without_noopener',
						'External target=_blank link is missing rel=noopener.',
						{ line: anchor.opening.line, href: truncate(href) }
					));
				}
			}
		}
		if (/([?&](?:fbclid|gclid|mc_cid|mc_eid|utm_[^=]+)=)/iu.test(href)) {
			findings.push(makeFinding(
				'warning', 'links.tracking_parameter', 'Link contains a tracking parameter.',
				{ line: anchor.opening.line, href: truncate(href) }
			));
		}
	}

	const byHref = new Map();
	for (const link of links) {
		const key = link.normalized_url ?? link.href;
		const matches = byHref.get(key) ?? [];
		matches.push(link);
		byHref.set(key, matches);
	}
	for (const matches of byHref.values()) {
		if (matches.length < 2) continue;
		findings.push(makeFinding(
			matches.length >= 3 ? 'warning' : 'info',
			'links.duplicate_destination',
			'The same URL is linked more than once in this post.',
			{ href: truncate(matches[0].href), count: matches.length }
		));
	}

	const contentWithoutTags = decodeHtmlEntities(
		content.replace(/<!--[\s\S]*?-->/gu, ' ').replace(/<[^>]+>/gu, ' ')
	);
	const bareUrls = contentWithoutTags.match(/https?:\/\/[^\s<>"']+/giu) ?? [];
	for (const bareUrl of bareUrls) {
		findings.push(makeFinding(
			'warning', 'links.bare_url_in_text', 'A bare URL appears outside an anchor.',
			{ href: truncate(bareUrl) }
		));
	}

	const counts = Object.fromEntries(
		['internal', 'external', 'fragment', 'email', 'telephone', 'invalid']
			.map((kind) => [kind, links.filter((link) => link.kind === kind).length])
	);
	if (links.length === 0) {
		findings.push(makeFinding(
			'error', 'links.none', 'Post content has no links.'
		));
	}
	if (counts.internal === 0) {
		findings.push(makeFinding(
			'warning', 'links.no_internal', 'Post has no internal Akibara link.'
		));
	}
	if (counts.external === 0) {
		findings.push(makeFinding(
			'info',
			'links.no_external_source',
			'Post has no external source link; this can be valid for operational/store-policy articles.'
		));
	}
	return {
		findings,
		counts,
		links,
		availability_checked_live: false,
	};
}

function auditMetadata(fields, postId) {
	const findings = [];
	const keys = Object.keys(fields ?? {});
	for (const field of REQUIRED_FIELDS) {
		if (!keys.includes(field)) {
			findings.push(makeFinding(
				'error', 'metadata.missing_field', `Managed field ${field} is missing.`,
				{ field }
			));
		}
	}
	for (const key of keys.filter((key) => !REQUIRED_FIELDS.includes(key))) {
		findings.push(makeFinding(
			'error', 'metadata.unexpected_field', `Unexpected managed field ${key} is present.`,
			{ field: key }
		));
	}
	const normalized = Object.fromEntries(
		REQUIRED_FIELDS.map((field) => [field, normalizeLineEndings(fields?.[field])])
	);
	for (const field of REQUIRED_FIELDS) {
		if (typeof fields?.[field] !== 'string' || !normalized[field].trim()) {
			findings.push(makeFinding(
				'error', 'metadata.blank_field', `Managed field ${field} must be a non-empty string.`,
				{ field }
			));
		}
	}
	const lengths = {
		post_title: characterLength(normalized.post_title),
		post_excerpt: characterLength(normalized.post_excerpt),
		rank_math_title: characterLength(normalized.rank_math_title),
		rank_math_description: characterLength(normalized.rank_math_description),
	};
	if (lengths.post_title > 60) {
		findings.push(makeFinding(
			'error', 'metadata.post_title_too_long', 'Post title exceeds 60 characters.',
			{ field: 'post_title', length: lengths.post_title, maximum: 60 }
		));
	}
	if (lengths.rank_math_title > 60) {
		findings.push(makeFinding(
			'error', 'metadata.seo_title_too_long', 'Rank Math title exceeds 60 characters.',
			{ field: 'rank_math_title', length: lengths.rank_math_title, maximum: 60 }
		));
	}
	if (lengths.rank_math_description < 80 || lengths.rank_math_description > 155) {
		findings.push(makeFinding(
			'error',
			'metadata.description_length',
			'Rank Math description must contain 80 to 155 characters.',
			{ field: 'rank_math_description', length: lengths.rank_math_description, range: [80, 155] }
		));
	}
	if (lengths.post_excerpt < 80 || lengths.post_excerpt > 160) {
		findings.push(makeFinding(
			'error',
			'metadata.excerpt_length',
			'Post excerpt must contain 80 to 160 characters.',
			{ field: 'post_excerpt', length: lengths.post_excerpt, range: [80, 160] }
		));
	}
	for (const field of [
		'post_title', 'post_excerpt', 'rank_math_title', 'rank_math_description',
	]) {
		if (/<[^>]+>/u.test(normalized[field])) {
			findings.push(makeFinding(
				'error', 'metadata.html_in_plain_text', `${field} contains HTML.`, { field }
			));
		}
	}
	for (const field of ['rank_math_title', 'rank_math_description']) {
		if (/\n/u.test(normalized[field])) {
			findings.push(makeFinding(
				'error', 'metadata.line_break', `${field} contains a line break.`, { field }
			));
		}
	}
	const focusKeywords = normalized.rank_math_focus_keyword
		.split(',').map((keyword) => keyword.trim()).filter(Boolean);
	const normalizedKeywords = focusKeywords.map((keyword) => comparableText(keyword));
	if (focusKeywords.length < 1 || focusKeywords.length > 5) {
		findings.push(makeFinding(
			'error', 'metadata.focus_keyword_count', 'Focus keyword count must be between 1 and 5.',
			{ count: focusKeywords.length, range: [1, 5] }
		));
	}
	if (new Set(normalizedKeywords).size !== normalizedKeywords.length) {
		findings.push(makeFinding(
			'error', 'metadata.duplicate_focus_keyword', 'Focus keywords are not unique.'
		));
	}
	const primaryKeyword = normalizedKeywords[0] ?? '';
	if (primaryKeyword) {
		const titleCoverage = Math.max(
			keywordCoverage(primaryKeyword, normalized.post_title),
			keywordCoverage(primaryKeyword, normalized.rank_math_title)
		);
		const placementCoverage = {
			title_fields: titleCoverage,
			rank_math_description: keywordCoverage(
				primaryKeyword,
				normalized.rank_math_description
			),
			opening: keywordCoverage(
				primaryKeyword,
				words(normalized.post_content).slice(0, 120).join(' ')
			),
		};
		for (const [field, coverage] of Object.entries(placementCoverage)) {
			const threshold = field === 'title_fields' ? 0.75 : 0.6;
			if (coverage >= threshold) continue;
			findings.push(makeFinding(
				'warning',
				'metadata.primary_keyword_low_coverage',
				`Primary focus keyword has low meaningful-token coverage in ${field}.`,
				{
					field,
					primary_keyword: focusKeywords[0],
					coverage: Number(coverage.toFixed(3)),
					minimum_coverage: threshold,
				}
			));
		}
	}
	const editorialWordCount = words(normalized.post_content).length;
	// Post 24977 is intentionally reduced to a short archive notice before it
	// transitions to draft and keeps its existing 301 redirect. Requiring a
	// full article here would recreate the cannibalization this remediation
	// removes.
	const minimumEditorialWordCount = postId === 24977 ? 25 : 250;
	if (editorialWordCount < minimumEditorialWordCount) {
		findings.push(makeFinding(
			'error',
			'content.too_short',
			`Editorial content contains fewer than ${minimumEditorialWordCount} words.`,
			{ word_count: editorialWordCount, minimum: minimumEditorialWordCount }
		));
	}
	if (/\uFFFD/u.test(REQUIRED_FIELDS.map((field) => normalized[field]).join('\n'))) {
		findings.push(makeFinding(
			'error', 'metadata.replacement_character', 'A Unicode replacement character is present.'
		));
	}
	return { findings, normalized_fields: normalized, lengths, focus_keywords: focusKeywords };
}

function auditTone(fields) {
	const findings = [];
	const rawHaystack = REQUIRED_FIELDS.map((field) => fields[field]).join('\n');
	const visibleHaystack = [
		fields.post_title,
		plainText(fields.post_content),
		fields.post_excerpt,
		fields.rank_math_title,
		fields.rank_math_description,
	].join('\n');
	for (const pattern of AI_AND_VENDOR_PATTERNS) {
		const match = rawHaystack.match(pattern.expression);
		if (!match) continue;
		findings.push(makeFinding(
			pattern.severity,
			pattern.code,
			pattern.message,
			{ sample: truncate(match[0]) }
		));
	}
	for (const [code, expression] of PROHIBITED_EDITORIAL_PATTERNS) {
		const match = visibleHaystack.match(expression);
		if (!match) continue;
		findings.push(makeFinding(
			'error', code, 'Prohibited promotional or formulaic wording is present.',
			{ sample: truncate(match[0]) }
		));
	}
	for (const [code, expression] of FORMULAIC_TONE_PATTERNS) {
		const match = visibleHaystack.match(expression);
		if (!match) continue;
		findings.push(makeFinding(
			'warning', code, 'Potentially formulaic or absolute wording requires human review.',
			{ sample: truncate(match[0]) }
		));
	}
	if (/```|(?:^|\n)\s*#{1,6}\s|\*\*[^*]+\*\*/u.test(fields.post_content)) {
		findings.push(makeFinding(
			'warning', 'tone.markdown_residue', 'Markdown syntax remains inside serialized WordPress HTML.'
		));
	}
	if (
		/\b(?:TODO|FIXME)\b/u.test(rawHaystack)
		|| /\b(?:placeholder|insertar (?:aqu[ií]|enlace)|pendiente de revisar)\b/iu.test(rawHaystack)
	) {
		findings.push(makeFinding(
			'error', 'tone.editorial_placeholder', 'An editorial placeholder or TODO remains.'
		));
	}
	return findings;
}

function extractEditorialSegments(content, html, targetOrigin) {
	const paragraphs = pairedElements(content, html.tokens, ['p']).map((paragraph) => ({
		...paragraph,
		is_internal: pairedElements(paragraph.html, tokenizeHtml(paragraph.html).tokens, ['a'])
			.some((anchor) => classifyHref(
				parseAttributes(anchor.opening.attributes_raw).attributes.get('href') ?? '',
				targetOrigin
			).kind === 'internal'),
	}));
	const opening = paragraphs.find((paragraph) => paragraph.text)?.text ?? '';
	const ctaCue = /\b(?:compra|consulta|encuentra|explora|mira|revisa|visita|verifica)\b/iu;
	const internalParagraphs = paragraphs.filter((paragraph) => paragraph.is_internal);
	const cta = [...internalParagraphs].reverse().find(
		(paragraph) => ctaCue.test(paragraph.text)
	)?.text ?? internalParagraphs.at(-1)?.text ?? '';
	return { opening, cta };
}

function repeatedNgrams(segments, scope, size = 8, minimumPosts = 3) {
	const index = new Map();
	for (const segment of segments) {
		const tokens = comparableText(segment[scope]).split(' ').filter(Boolean).slice(0, 100);
		const uniqueForPost = new Set();
		for (let offset = 0; offset <= tokens.length - size; offset += 1) {
			const phrase = tokens.slice(offset, offset + size).join(' ');
			if (uniqueForPost.has(phrase)) continue;
			uniqueForPost.add(phrase);
			const postIds = index.get(phrase) ?? [];
			postIds.push(segment.post_id);
			index.set(phrase, postIds);
		}
	}
	const candidates = [...index.entries()]
		.filter(([, postIds]) => new Set(postIds).size >= minimumPosts)
		.map(([phrase, postIds]) => ({
			code: `repetition.${scope}_shared_phrase`,
			scope,
			kind: 'shared_ngram',
			phrase,
			post_ids: [...new Set(postIds)].sort((left, right) => left - right),
		}))
		.sort((left, right) => (
			right.post_ids.length - left.post_ids.length
			|| right.phrase.length - left.phrase.length
		));
	const selected = [];
	for (const candidate of candidates) {
		const postSignature = candidate.post_ids.join(',');
		if (selected.some((item) => (
			item.post_ids.join(',') === postSignature
			&& (
				item.phrase.includes(candidate.phrase)
				|| candidate.phrase.includes(item.phrase)
			)
		))) continue;
		selected.push(candidate);
		if (selected.length >= 10) break;
	}
	return selected;
}

function auditCrossPostRepetition(segments) {
	const groups = [];
	for (const formula of CROSS_POST_FORMULAS) {
		const matching = segments.filter((segment) => (
			formula.expression.test(segment[formula.scope])
		));
		if (matching.length < formula.minimumPosts) continue;
		groups.push({
			code: formula.code,
			scope: formula.scope,
			kind: 'formula_family',
			phrase: formula.label,
			post_ids: matching.map((segment) => segment.post_id)
				.sort((left, right) => left - right),
		});
	}
	groups.push(...repeatedNgrams(segments, 'opening'));
	groups.push(...repeatedNgrams(segments, 'cta'));
	return groups;
}

function auditGlobalDuplicates(posts) {
	const findings = [];
	for (const field of ['post_title', 'rank_math_title', 'rank_math_description']) {
		const values = new Map();
		for (const post of posts) {
			const key = comparableText(post.fields[field]);
			if (!key) continue;
			const matches = values.get(key) ?? [];
			matches.push(post.post_id);
			values.set(key, matches);
		}
		for (const [value, postIds] of values.entries()) {
			if (postIds.length < 2) continue;
			findings.push(makeFinding(
				'error',
				'metadata.duplicate_across_posts',
				`${field} is duplicated across posts.`,
				{ field, normalized_value: truncate(value), post_ids: postIds }
			));
		}
	}
	return findings;
}

function compareWithBaseline(currentPosts, baselinePosts) {
	// Content keys are the cross-environment identity. A Docker clone can map a
	// reviewed production post to a different local numeric ID (The Climber is
	// the known case), while the official UI still persists the exact same slug
	// and six managed fields.
	const currentByKey = new Map(currentPosts.map((post) => [post.content_key, post]));
	const baselineByKey = new Map(baselinePosts.map((post) => [post.content_key, post]));
	const missingKeys = [...baselineByKey.keys()].filter((key) => !currentByKey.has(key));
	const extraKeys = [...currentByKey.keys()].filter((key) => !baselineByKey.has(key));
	const missingPostIds = missingKeys.map((key) => baselineByKey.get(key).post_id);
	const extraPostIds = extraKeys.map((key) => currentByKey.get(key).post_id);
	const identityMismatches = [];
	const fieldMismatches = [];
	const environmentIdMappings = [];
	for (const [contentKey, expected] of baselineByKey.entries()) {
		const actual = currentByKey.get(contentKey);
		if (!actual) continue;
		if (actual.post_id !== expected.post_id) {
			environmentIdMappings.push({
				content_key: contentKey,
				expected_post_id: expected.post_id,
				actual_post_id: actual.post_id,
			});
		}
		const changedFields = REQUIRED_FIELDS.filter((field) => (
			normalizeLineEndings(actual.fields?.[field])
			!== normalizeLineEndings(expected.fields?.[field])
		));
		if (changedFields.length > 0) {
			fieldMismatches.push({
				post_id: expected.post_id,
				environment_post_id: actual.post_id,
				content_key: expected.content_key,
				fields: changedFields,
				expected_fields_sha256: sha256(canonicalJson(expected.fields)),
				actual_fields_sha256: sha256(canonicalJson(actual.fields)),
			});
		}
	}
	return {
		enabled: true,
		exact_match: missingPostIds.length === 0
			&& extraPostIds.length === 0
			&& identityMismatches.length === 0
			&& fieldMismatches.length === 0,
		missing_post_ids: missingPostIds,
		extra_post_ids: extraPostIds,
		missing_content_keys: missingKeys,
		extra_content_keys: extraKeys,
		identity_mismatches: identityMismatches,
		field_mismatches: fieldMismatches,
		environment_id_mappings: environmentIdMappings,
	};
}

function severityCounts(findings) {
	return Object.fromEntries(['error', 'warning', 'info'].map((severity) => [
		severity,
		findings.filter((finding) => finding.severity === severity).length,
	]));
}

function main() {
	if (hasFlag('--help') || hasFlag('-h')) {
		process.stdout.write(usage());
		return;
	}
	const inputPath = localInput(option('--input') ?? DEFAULT_INPUT, '--input');
	const outputPath = localOutput(option('--output') ?? DEFAULT_OUTPUT);
	const inputRaw = readJsonArtifact(inputPath, '--input');
	const artifact = adaptArtifact(inputRaw);
	const globalFindings = [
		...artifact.contract_findings,
		...auditInventory(artifact.posts, artifact.expected_post_ids, {
			enforceExpectedIdSet: !(
				artifact.kind === 'official_ui_capture'
				&& artifact.environment === 'docker'
			),
		}),
	];

	const auditedPosts = artifact.posts.map((post) => {
		const metadata = auditMetadata(post.fields, post.post_id);
		const content = metadata.normalized_fields.post_content;
		const blocks = auditWordPressBlocks(content);
		const html = auditHtml(content);
		const headings = auditHeadings(content, html);
		const links = auditLinks(content, html, artifact.target_origin);
		const findings = [
			...auditPostIdentity(post, artifact),
			...metadata.findings,
			...blocks.findings,
			...html.findings,
			...auditBlockHtmlParity(blocks, html),
			...headings.findings,
			...links.findings,
			...auditTone(metadata.normalized_fields),
		];
		const segments = extractEditorialSegments(
			content,
			html,
			artifact.target_origin
		);
		return {
			post_id: post.post_id,
			content_key: post.content_key,
			metrics: {
				word_count: words(content).length,
				serialization_mode: blocks.parsed_marker_count > 0
					? 'wordpress_blocks'
					: 'classic_html',
				wordpress_block_marker_count: blocks.parsed_marker_count,
				html_element_count: html.tokens.filter((token) => !token.closing).length,
				template_h1_assumed: headings.template_h1_assumed,
				content_h1_count: headings.content_h1_count,
				effective_h1_count_assumed: headings.effective_h1_count_assumed,
				h2_count: headings.h2_count,
				link_count: links.links.length,
				internal_link_count: links.counts.internal,
				external_link_count: links.counts.external,
			},
			metadata_lengths: metadata.lengths,
			focus_keywords: metadata.focus_keywords,
			headings: headings.headings,
			links: {
				counts: links.counts,
				availability_checked_live: links.availability_checked_live,
				items: links.links,
			},
			editorial_segments: segments,
			findings,
		};
	});

	globalFindings.push(...auditGlobalDuplicates(
		auditedPosts.map((post) => ({
			post_id: post.post_id,
			fields: artifact.posts.find((source) => source.post_id === post.post_id)?.fields ?? {},
		}))
	));
	const repetitions = auditCrossPostRepetition(auditedPosts.map((post) => ({
		post_id: post.post_id,
		opening: post.editorial_segments.opening,
		cta: post.editorial_segments.cta,
	})));
	for (const repetition of repetitions) {
		for (const postId of repetition.post_ids) {
			const post = auditedPosts.find((item) => item.post_id === postId);
			post?.findings.push(makeFinding(
				'warning',
				repetition.code,
				`Potentially formulaic ${repetition.scope} is repeated across posts.`,
				{ phrase: repetition.phrase, related_post_ids: repetition.post_ids }
			));
		}
	}

	let comparison = { enabled: false };
	let baselineReceipt = null;
	const baselineOption = option('--baseline');
	if (baselineOption) {
		const baselinePath = localInput(baselineOption, '--baseline');
		const baselineRaw = readJsonArtifact(baselinePath, '--baseline');
		const baseline = adaptArtifact(baselineRaw, { requireDesired: true });
		comparison = compareWithBaseline(artifact.posts, baseline.posts);
		baselineReceipt = {
			path: baselinePath,
			file_sha256: baselineRaw.file_sha256,
			payload_sha256: baselineRaw.value.payload_sha256,
		};
		if (baseline.contract_findings.some((finding) => finding.severity === 'error')) {
			globalFindings.push(makeFinding(
				'error', 'comparison.invalid_baseline', 'Baseline desired artifact failed its contract validation.',
				{ baseline_findings: baseline.contract_findings }
			));
		}
		if (!comparison.exact_match) {
			globalFindings.push(makeFinding(
				'error',
				'comparison.persisted_fields_mismatch',
				'Captured Docker/WordPress fields do not exactly match the reviewed desired artifact.',
				{
					missing_post_ids: comparison.missing_post_ids,
					extra_post_ids: comparison.extra_post_ids,
					identity_mismatch_count: comparison.identity_mismatches.length,
					field_mismatch_count: comparison.field_mismatches.length,
				}
			));
		}
	}

	const allFindings = [
		...globalFindings,
		...auditedPosts.flatMap((post) => post.findings),
	];
	const counts = severityCounts(allFindings);
	const result = counts.error > 0 ? 'FAIL' : counts.warning > 0 ? 'WARN' : 'PASS';
	const uniqueInternalUrls = new Set();
	const uniqueExternalUrls = new Set();
	const externalDomains = new Map();
	for (const post of auditedPosts) {
		for (const link of post.links.items) {
			if (link.kind === 'internal') uniqueInternalUrls.add(link.normalized_url ?? link.href);
			if (link.kind === 'external') {
				uniqueExternalUrls.add(link.normalized_url ?? link.href);
				try {
					const domain = new URL(link.normalized_url ?? link.href).hostname;
					externalDomains.set(domain, (externalDomains.get(domain) ?? 0) + 1);
				} catch {
					// Invalid URLs already have a finding and are excluded here.
				}
			}
		}
	}
	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_desired_audit',
		generated_at_gmt: new Date().toISOString(),
		result,
		read_only_contract: {
			wordpress_connections: 0,
			wordpress_reads: 0,
			wordpress_writes: 0,
			image_fields_audited: 0,
			image_mutations: 0,
			live_url_requests: 0,
		},
		source: {
			path: inputPath,
			kind: artifact.kind,
			environment: artifact.environment,
			target_origin: artifact.target_origin,
			file_sha256: inputRaw.file_sha256,
			payload_sha256: inputRaw.value.payload_sha256 ?? null,
			baseline: baselineReceipt,
		},
		criteria: {
			expected_post_count: artifact.expected_post_ids.length,
			expected_post_ids: artifact.expected_post_ids,
			managed_fields: REQUIRED_FIELDS,
			minimum_editorial_word_count: 250,
			minimum_editorial_word_count_exceptions: { 24977: 25 },
			post_title_max_characters: 60,
			rank_math_title_max_characters: 60,
			rank_math_description_character_range: [80, 155],
			excerpt_character_range: [80, 160],
			focus_keyword_count_range: [1, 5],
			content_h1_required: 0,
			template_h1_assumed: 1,
			minimum_h2_count: 2,
			wordpress_blocks_balanced: true,
			html_tags_balanced: true,
			url_validation: 'offline_structure_scheme_canonicalization_and_anchor_security',
			live_url_availability_checked: false,
			cross_post_repetition: 'formula_families_and_repeated_8_word_phrases',
		},
		summary: {
			post_count: auditedPosts.length,
			posts_with_errors: auditedPosts.filter((post) => (
				post.findings.some((finding) => finding.severity === 'error')
			)).length,
			posts_with_warnings: auditedPosts.filter((post) => (
				post.findings.some((finding) => finding.severity === 'warning')
			)).length,
			global_error_count: globalFindings.filter((finding) => finding.severity === 'error').length,
			finding_counts: counts,
			content_h1_total: auditedPosts.reduce((sum, post) => sum + post.metrics.content_h1_count, 0),
			assumed_template_h1_per_post: 1,
			unique_internal_url_count: uniqueInternalUrls.size,
			unique_external_url_count: uniqueExternalUrls.size,
			repetition_group_count: repetitions.length,
			baseline_exact_match: comparison.enabled ? comparison.exact_match : null,
		},
		url_inventory: {
			internal_urls: [...uniqueInternalUrls].sort(),
			external_urls: [...uniqueExternalUrls].sort(),
			external_domains: [...externalDomains.entries()]
				.sort(([left], [right]) => left.localeCompare(right))
				.map(([domain, link_count]) => ({ domain, link_count })),
			availability_checked_live: false,
		},
		cross_post_repetition: repetitions,
		comparison,
		global_findings: globalFindings,
		posts: auditedPosts,
	};

	writeAtomic(outputPath, report);
	process.stdout.write(`${JSON.stringify({
		result,
		posts: report.summary.post_count,
		posts_with_errors: report.summary.posts_with_errors,
		posts_with_warnings: report.summary.posts_with_warnings,
		finding_counts: counts,
		content_h1_total: report.summary.content_h1_total,
		unique_internal_urls: report.summary.unique_internal_url_count,
		unique_external_urls: report.summary.unique_external_url_count,
		repetition_groups: report.summary.repetition_group_count,
		baseline_exact_match: report.summary.baseline_exact_match,
		wordpress_writes: 0,
		image_mutations: 0,
		output: outputPath,
	}, null, 2)}\n`);
	if (counts.error > 0 || (hasFlag('--fail-on-warnings') && counts.warning > 0)) {
		process.exitCode = 1;
	}
}

main();
