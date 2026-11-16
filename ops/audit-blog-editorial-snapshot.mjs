#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

function fail(message) {
	throw new Error(`Blog editorial snapshot audit failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function normalize(value) {
	return String(value ?? '')
		.replace(/\r\n?/gu, '\n')
		.replace(/\s+/gu, ' ')
		.trim();
}

function textOnly(value) {
	return normalize(
		String(value ?? '')
			.replace(/<!--.*?-->/gsu, ' ')
			.replace(/<[^>]+>/gu, ' ')
			.replace(/&nbsp;/gu, ' ')
			.replace(/&amp;/gu, '&')
			.replace(/&quot;/gu, '"')
			.replace(/&#(?:x27|39);/gu, "'")
	);
}

function writeAtomic(file, value) {
	const output = path.resolve(file);
	const repository = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
	const allowed = ['.local-reports', '.local-checkpoints'].some((name) => {
		const relative = path.relative(path.join(repository, name), output);
		return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
	});
	if (!allowed) fail('output must stay under .local-reports or .local-checkpoints');
	if (fs.existsSync(output)) fail(`refusing to overwrite ${output}`);
	fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
	const temporary = `${output}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8',
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, output);
	fs.chmodSync(output, 0o600);
}

const STYLE_PATTERNS = Object.freeze([
	['obra_maestra', /\bobras? maestras?\b/giu],
	['imperdible', /\bimperdibles?\b/giu],
	['imprescindible', /\bimprescindibles?\b/giu],
	['lectura_obligatoria', /\blecturas? obligatorias?\b/giu],
	['guia_definitiva', /\bgu[ií]as? definitivas?\b/giu],
	['todo_lo_que_necesitas', /\btodo lo que necesitas saber\b/giu],
	['sin_duda', /\bsin duda\b/giu],
	['no_es_casualidad', /\bno es casualidad\b/giu],
	['joya', /\bjoyas?\b/giu],
	['descubre', /\bdescubre\b/giu],
	['sumergente', /\bsum[eé]rgete\b/giu],
	['clasico_instantaneo', /\bcl[aá]sico instant[aá]neo\b/giu],
	['superlativo_mejor', /\b(?:el|la|los|las) mejores?\b/giu],
	['superlativo_mas', /\b(?:el|la|los|las)?\s*m[aá]s (?:bello|popular|importante|influyente|vendido|buscado|bajo)\b/giu],
]);

function styleHits(value) {
	const text = textOnly(value);
	return STYLE_PATTERNS.flatMap(([key, pattern]) => {
		pattern.lastIndex = 0;
		const matches = [...text.matchAll(pattern)].map((match) => match[0]);
		return matches.length > 0 ? [{ key, count: matches.length, samples: [...new Set(matches)] }] : [];
	});
}

function postHaystack(post) {
	return [
		post.post_title,
		post.post_content,
		post.post_excerpt,
		post.rank_math?.title,
		post.rank_math?.description,
		post.rank_math?.focus_keyword,
	].join('\n');
}

function collectLegacyFindings(legacy) {
	const groups = Array.isArray(legacy?.result) ? legacy.result : [];
	return groups.flatMap((group) => group?.posts ?? []);
}

function literalStillPresent(post, literal) {
	const current = postHaystack(post);
	const exact = normalize(current).includes(normalize(literal));
	const plainLiteral = textOnly(literal);
	const plain = plainLiteral.length >= 20 && textOnly(current).includes(plainLiteral);
	return exact || plain;
}

function main() {
	const snapshotPath = option('--snapshot');
	const legacyPath = option('--legacy-audit');
	const outputPath = option('--output');
	if (!snapshotPath || !outputPath) fail('missing --snapshot or --output');

	const snapshot = readJson(path.resolve(snapshotPath));
	if (
		snapshot?.schema_version !== 1
		|| snapshot?.snapshot_type !== 'akibara_blog_production_read_only'
		|| snapshot?.environment !== 'production'
		|| snapshot?.direct_writes !== 0
		|| !Array.isArray(snapshot?.posts)
	) {
		fail('snapshot contract is invalid');
	}
	const byId = new Map(snapshot.posts.map((post) => [String(post.post_id), post]));
	if (byId.size !== snapshot.posts.length) fail('duplicate post IDs');

	const legacyPosts = legacyPath
		? collectLegacyFindings(readJson(path.resolve(legacyPath)))
		: [];
	const legacyById = new Map(legacyPosts.map((post) => [String(post.id), post]));

	const posts = snapshot.posts.map((post) => {
		const contentText = textOnly(post.post_content);
		const legacy = legacyById.get(String(post.post_id));
		const legacyFindings = (legacy?.hallazgos ?? []).map((finding) => ({
			...finding,
			still_present_exact_or_plain: literalStillPresent(post, finding.texto_literal),
		}));
		const urls = [...String(post.post_content).matchAll(/<a\b[^>]*\bhref=["']([^"']+)["']/giu)]
			.map((match) => match[1]);
		return {
			post_id: post.post_id,
			post_status: post.post_status,
			post_slug: post.post_slug,
			post_title: post.post_title,
			word_count: contentText ? contentText.split(/\s+/u).length : 0,
			heading_count: (String(post.post_content).match(/<h[2-6]\b/giu) ?? []).length,
			internal_link_count: urls.filter((url) => (
				url.startsWith('/') || url.startsWith('https://akibara.cl/')
			)).length,
			external_link_count: urls.filter((url) => /^https?:\/\//u.test(url) && !url.startsWith('https://akibara.cl/')).length,
			missing_fields: [
				['post_excerpt', post.post_excerpt],
				['rank_math_focus_keyword', post.rank_math?.focus_keyword],
				['rank_math_title', post.rank_math?.title],
				['rank_math_description', post.rank_math?.description],
				['featured_image_alt', post.featured_image?.alt],
			].filter(([, value]) => !normalize(value)).map(([field]) => field),
			meta_lengths: {
				title: [...String(post.rank_math?.title ?? '')].length,
				description: [...String(post.rank_math?.description ?? '')].length,
			},
			style_hits: styleHits(postHaystack(post)),
			legacy_audit: legacy ? {
				verdict: legacy.veredicto,
				finding_count: legacyFindings.length,
				still_present_count: legacyFindings.filter((finding) => finding.still_present_exact_or_plain).length,
				findings: legacyFindings,
			} : null,
		};
	});

	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_snapshot_audit',
		generated_at: new Date().toISOString(),
		source: {
			snapshot_path: path.resolve(snapshotPath),
			snapshot_payload_sha256: snapshot.payload_sha256,
			legacy_audit_path: legacyPath ? path.resolve(legacyPath) : null,
		},
		summary: {
			post_count: posts.length,
			published_count: posts.filter((post) => post.post_status === 'publish').length,
			draft_count: posts.filter((post) => post.post_status === 'draft').length,
			posts_with_style_hits: posts.filter((post) => post.style_hits.length > 0).length,
			posts_with_missing_fields: posts.filter((post) => post.missing_fields.length > 0).length,
			posts_with_live_legacy_findings: posts.filter((post) => (
				post.legacy_audit?.still_present_count > 0
			)).length,
			live_legacy_finding_count: posts.reduce((sum, post) => (
				sum + (post.legacy_audit?.still_present_count ?? 0)
			), 0),
		},
		posts,
	};
	writeAtomic(outputPath, report);
	process.stdout.write(`${JSON.stringify(report.summary, null, 2)}\n`);
}

main();
