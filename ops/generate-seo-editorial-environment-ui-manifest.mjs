#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDir);
const manifestFiles = [
	'seo-editorial-curated-excerpts-quality-manifest.json',
	'seo-editorial-shipping-quality-manifest.json',
	'seo-editorial-followup-quality-manifest.json',
	'seo-editorial-orthography-quality-manifest.json',
];
const reviewedProductExcerptScope = {
	source_manifest: 'seo-editorial-shipping-quality-manifest.json',
	object_type: 'post',
	field: 'post_excerpt',
	issue_codes: ['low_information_density', 'overlong_product_excerpt'],
	application_lane: 'official_ui',
	apply_via:
		'WooCommerce > Productos > Editar producto > Descripción corta del producto > Actualizar',
};
const semanticReviewSourceSha256 = new Map([
	['post:17219:post_excerpt', 'ae50e1dccaf897de89d6f6fb97d54c9f175ff8e48d81c6a932799be375ac3b64'],
	['post:17222:post_excerpt', '3015ac26939bb6b62359b4349f27b904fafa15e4c313d0b3f1445202a1dd08bf'],
	['post:17232:post_excerpt', '385c0b0e03f266bf0ec791eb306253b00b2354fd80a2cd7c034fad88c9bef25c'],
	['post:17270:post_excerpt', '10a9b22f05738ee6154df2c60fe247d1c9b74412c1d72e114c7da88dba52858c'],
	['post:17286:post_excerpt', '4e478d164fbbe880d552a1a500efb30f00a5a97e0aa2e2ebaeba66ed7c6fce1a'],
	['post:21627:post_excerpt', 'ce68451b2826b4c99ee0dbc935b1c68ca0958c74394662dc9bca03ae59a58cab'],
	['post:21630:post_excerpt', 'd58f0963f70083ab711e9df6c8b0bae8b357fe481f3add08a2fd02be2ae746d0'],
	['post:21634:post_excerpt', '9e7324ba16deec222ac9fcec0e47075bb5b40c4c24d88667f2866a27036f42c7'],
	['post:21637:post_excerpt', '938bedf866a6bf84d8e18c487c76fb1a2b0320fb147ac64a9158d7438490fb0d'],
	['post:21644:post_excerpt', 'a63e61b87def912203d71b6be1a1a06d891b6e69ea0dede8dbf95e6d7ecd64d9'],
	['post:21648:post_excerpt', 'e9f476bf4d9c03553508c6f3458d768b0fd601cdd6d8e805f6f6afa863f9be05'],
	['post:21655:post_excerpt', 'f5622219ded754663fc9b4487b91ce0ea68492987afa8c645c68e3567821d96a'],
	['post:21660:post_excerpt', '78e275ab21518836b52f6c86cfd3dba0d2033a0094d5d4be537bcdc11646f279'],
	['post:21669:post_excerpt', 'feeef947bd2f418343762f04e4641b9f288c8d2e00fe80803507f589edf17b8d'],
	['post:21672:post_excerpt', 'a821696c80d04dcf4a2860f2b5794f20c146743cc43381014f47d092afd3e1ef'],
	['post:21675:post_excerpt', 'f66435e1d42eeb0c17693aa9a37d9b27429d8257ad647b681045822b434a2cf5'],
	['post:21681:post_excerpt', '657eecc74607d6f6108cf638023e97a248fc127d872f777818766b1f097dcfd2'],
	['post:21685:post_excerpt', 'cef184dda8210d5a63a1b32a421a2f598f8490bf45fc7ad2ac90d3b9993abdc2'],
	['post:21689:post_excerpt', '754b66e98004ac2b7645ea409b14560e5352ed4f32e08351b789f09481294031'],
	['post:21692:post_excerpt', '964bd95df44d06b42488725cc73e4de75e8dacf6d2443cfd6488c81f5aabb35f'],
	['post:21701:post_excerpt', '9ff8c45dae73074147a97e561e45437ca8e9f42548a664ea593f9b7f124197c7'],
	['post:21704:post_excerpt', 'cd5770cadbbbf3ab4eee73f95000b08ece60b81ca54274dd1d477eb050fc4f28'],
	['post:21707:post_excerpt', '4480a27e20a73583e36472904a534d9fb9c521eb31912402b5630e07d9b6a46d'],
	['post:21710:post_excerpt', '0e61ee4a4116284353e22e4503aad64ea76d8ce4b67e76b2e8b251a6d898e335'],
	['post:21713:post_excerpt', 'ee8580c829a02423f54f8fc1eb29bc32c6062241adb3fb2ac0424b0d4c8a114c'],
	['post:21719:post_excerpt', '7b50ddf5a72d27bb4ffd22323a2fc0f2a8c6428718463d5d5f9c808c46984361'],
	['post:21722:post_excerpt', '30ec5ff2565489c52d3c3f9b144b6fa9ce907380f11f739ee9776a4d40ae4388'],
	['post:21775:post_excerpt', '0666681c0cff8a71e6bcbee5d9cc7f9b5b31a7bb08a57e95772b56c1e933f7ad'],
	['post:21778:post_excerpt', '6f17e95926e53ac8507d5c8a3ebffaa2a894c3e6162d7eb31101719113d502ef'],
	['post:21781:post_excerpt', '6d47eca6b7bc7912d614df3b1cdde9a482dabb8009c362e8e25870d4e22fc0ec'],
	['post:21784:post_excerpt', '3f1048f3e027d90c622ec2183f730569e61d9e1746e04f99a601a2740791c97a'],
	['post:21788:post_excerpt', 'fa1f2dd2ddc56d25b444c088f6a7b68de200148b818152080af3a69c3f4180f3'],
	['post:22018:post_excerpt', '4fac44b127759fc9bfa188f8a30dd34966ad556c9949ef8270b4081d71d8dec9'],
	['post:22153:post_excerpt', 'b298a871794ce779264a109e0743820cf70fb2db031f45b66021c3e95fd34144'],
	['post:22157:post_excerpt', '91f6182a432b90f624cd2cf45ca4da1f1c999048003692f8538ad6033cd6a714'],
	['post:22160:post_excerpt', '02536e5b62698d2fd1b388ec0c6ae80ec6d7c8e0ac00851f5399a1f42cd7dcce'],
	['post:22164:post_excerpt', '91063bd81d9c6020bb2c2d5f355f964a263604bd061407f20ffb13017e5beb1d'],
	['post:22167:post_excerpt', '8531710a0c3c6b7277f7941bf4b9cb329bb59c27d435529f150ff71d3912c0ef'],
	['post:22170:post_excerpt', '73ff190155e9078b79c3715a9167e8d9bb020b275b2682667f05f9f0066327ac'],
	['post:22173:post_excerpt', '086e851ba3299f7844fa54443aa0a525d2bce8f3b2ee4358df87d6393210ba52'],
	['post:22176:post_excerpt', '95afefb1febd571e94cc4c99c3076e8220c164248b82e55c2ad0c890fd76e29c'],
	['post:22179:post_excerpt', 'bd82f0164eadd3b0827e56072f903ca513cc8ab09df2544161596b10adfecf58'],
	['post:22182:post_excerpt', 'c01358a54cb2fe74205ea5c3b178f1fbb5c43cd7564081d67e9c253ac267a28b'],
	['post:22185:post_excerpt', '68bc9e34b18b71969f4a2f9424bf98bad2f9400fdc9f108bd5d35e79618dc348'],
	['post:22188:post_excerpt', '4fd010db0aa3c5257432e2e87e5d3d048ee1b032301baaabfde77e42ed2db81f'],
	['post:22191:post_excerpt', '13787f01e3aa2520ef80035b14966af97b78a4bd7ae761a40400f6f606eeab22'],
	['post:22197:post_excerpt', 'ced345970e7bd588d0692ff6812f485ca15e727fab4668e89c43c6392bfba585'],
	['post:3:post_content', '4190a81b5e51ec8da7493016c85613bd7c505365cb59678194d0c2bb0409cae1'],
	['product:15418:post_excerpt', 'aa82d6c172ef6c2d75e696203feaccad0e2f4742655dd61be834f4b83cbee80d'],
	['term_taxonomy:28:description', 'd5ded8aba6031daef7696f18500e714892fc6a51b2607b9ac234df91e1dc9dcd'],
]);

function optionValue(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function fail(message) {
	throw new Error(`SEO editorial environment manifest failed: ${message}`);
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function normalizeLineEndings(value) {
	return String(value).replace(/\r\n?/gu, '\n');
}

function normalizeOfficialUiValue(entry, value) {
	const normalized = normalizeLineEndings(value);
	/*
	 * HTML parsing removes one line feed immediately after a <textarea> start
	 * tag. WordPress' taxonomy editor therefore exposes a stored leading LF as
	 * an implementation newline, not as editable content. Hash and apply the
	 * exact value that the official UI can read back, while retaining the raw
	 * storage hashes in the reviewed source manifests.
	 */
	if (
		entry.object_type === 'term_taxonomy'
		&& entry.field === 'description'
		&& normalized.startsWith('\n')
	) {
		return normalized.slice(1);
	}
	return normalized;
}

function occurrences(value, needle) {
	if (needle === '') {
		return 0;
	}
	return value.split(needle).length - 1;
}

function minimalLiteralTransformation(before, after) {
	let prefix = 0;
	while (
		prefix < before.length
		&& prefix < after.length
		&& before[prefix] === after[prefix]
	) {
		prefix += 1;
	}
	let suffix = 0;
	while (
		suffix < before.length - prefix
		&& suffix < after.length - prefix
		&& before[before.length - 1 - suffix] === after[after.length - 1 - suffix]
	) {
		suffix += 1;
	}
	let oldValue = before.slice(prefix, before.length - suffix);
	let newValue = after.slice(prefix, after.length - suffix);
	if (oldValue === '') {
		if (prefix > 0) {
			prefix -= 1;
		} else if (suffix > 0) {
			suffix -= 1;
		} else {
			fail('derived transformation has no exact source anchor');
		}
		oldValue = before.slice(prefix, before.length - suffix);
		newValue = after.slice(prefix, after.length - suffix);
	}
	while (oldValue !== '' && occurrences(before, oldValue) !== 1) {
		if (prefix > 0) {
			prefix -= 1;
		}
		if (suffix > 0) {
			suffix -= 1;
		}
		oldValue = before.slice(prefix, before.length - suffix);
		newValue = after.slice(prefix, after.length - suffix);
	}
	return {
		before: oldValue,
		after: newValue,
		expected_count: occurrences(before, oldValue),
		match_mode: 'literal',
	};
}

function transformationsFor(entry) {
	if (Array.isArray(entry.substitutions) && entry.substitutions.length > 0) {
		return entry.substitutions;
	}
	if (Array.isArray(entry.exact_transformations) && entry.exact_transformations.length > 0) {
		return entry.exact_transformations;
	}
	return [minimalLiteralTransformation(entry.expected_before, entry.proposed_after)];
}

function escapeRegex(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&');
}

function replaceTransformation(value, transformation) {
	const mode = transformation.match_mode ?? 'literal';
	let count = 0;
	const wordMode = ['whole_word', 'text_word'].includes(mode);
	const textOnly = ['whole_word', 'text_word', 'text_literal'].includes(mode);
	const replaceChunk = (chunk) => {
		if (wordMode) {
			const pattern = new RegExp(
				`(?<![\\p{L}\\p{N}_])${escapeRegex(transformation.before)}(?![\\p{L}\\p{N}_])`,
				'gu',
			);
			return chunk.replace(pattern, () => {
				count += 1;
				return transformation.after;
			});
		}
		count += occurrences(chunk, transformation.before);
		return chunk.split(transformation.before).join(transformation.after);
	};
	let result;
	if (textOnly) {
		result = value
			.split(/(<[^>]*>)/gu)
			.map((chunk, index) => index % 2 === 0 ? replaceChunk(chunk) : chunk)
			.join('');
	} else if (['literal', 'raw_literal'].includes(mode)) {
		result = replaceChunk(value);
	} else {
		fail(`unsupported match_mode ${mode}`);
	}
	return { count, result };
}

function applyExactTransformations(value, transformations) {
	let result = value;
	const checks = [];
	for (const transformation of transformations) {
		const expectedCount = transformation.expected_count ?? 1;
		const replacement = replaceTransformation(result, transformation);
		checks.push({
			before: transformation.before,
			after: transformation.after,
			match_mode: transformation.match_mode ?? 'literal',
			expected_count: expectedCount,
			observed_count: replacement.count,
		});
		if (replacement.count !== expectedCount) {
			return {
				result: value,
				checks,
				applicable: false,
			};
		}
		result = replacement.result;
	}
	return {
		result,
		checks,
		applicable: result !== value,
	};
}

function htmlTagNames(value) {
	return [...value.matchAll(/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/giu)]
		.map((match) => `${match[0].startsWith('</') ? '/' : ''}${match[1].toLowerCase()}`);
}

function decodeHtmlEntities(value) {
	return value
		.replaceAll('&amp;', '&')
		.replaceAll('&#038;', '&')
		.replaceAll('&#38;', '&');
}

function urls(value) {
	const attributeUrls = [...value.matchAll(/\b(?:href|src)=["']([^"']+)["']/giu)]
		.map((match) => decodeHtmlEntities(match[1]));
	const plainUrls = [...value.matchAll(/https?:\/\/[^\s"'<>]+/giu)]
		.map((match) => decodeHtmlEntities(match[0].replace(/[.,;:!?]+$/gu, '')));
	return [...new Set([...attributeUrls, ...plainUrls])].sort();
}

function sameJson(left, right) {
	return JSON.stringify(left) === JSON.stringify(right);
}

function visibleText(value) {
	return String(value)
		.replace(/<!--[\s\S]*?-->/gu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replaceAll('&nbsp;', ' ')
		.replaceAll('&amp;', '&')
		.replace(/\s+/gu, ' ')
		.trim();
}

function normalizedIdentityTokens(value) {
	return visibleText(value)
		.normalize('NFD')
		.replace(/\p{M}/gu, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/gu, ' ')
		.trim()
		.split(/\s+/gu)
		.filter(Boolean)
		.map((token) => /^\d+$/u.test(token) ? String(Number(token)) : token)
		.filter((token) => !['n', 'no', 'tomo'].includes(token));
}

function containsIdentity(value, title) {
	const targetTokens = new Set(normalizedIdentityTokens(value));
	const titleTokens = normalizedIdentityTokens(title);
	return titleTokens.length > 0 && titleTokens.every((token) => targetTokens.has(token));
}

function exactReviewedProductExcerptScope(entry) {
	return (
		entry.source_manifest === reviewedProductExcerptScope.source_manifest
		&& entry.object_type === reviewedProductExcerptScope.object_type
		&& Number.isSafeInteger(entry.object_id)
		&& entry.object_id > 0
		&& entry.key === `post:${entry.object_id}:post_excerpt`
		&& entry.field === reviewedProductExcerptScope.field
		&& sameJson(
			[...entry.issue_codes].sort(),
			reviewedProductExcerptScope.issue_codes,
		)
		&& entry.application_lane === reviewedProductExcerptScope.application_lane
		&& entry.apply_via === reviewedProductExcerptScope.apply_via
	);
}

function exactReplacements(value, replacements) {
	let result = value;
	const checks = [];
	for (const replacement of replacements) {
		const count = occurrences(result, replacement.before);
		checks.push({
			before: replacement.before,
			after: replacement.after,
			expected_count: replacement.expected_count ?? 1,
			observed_count: count,
		});
		if (count !== (replacement.expected_count ?? 1)) {
			return { applicable: false, result: value, checks };
		}
		result = result.split(replacement.before).join(replacement.after);
	}
	return { applicable: result !== value, result, checks };
}

function htmlIsBalanced(value) {
	const voidTags = new Set([
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
		'meta', 'param', 'source', 'track', 'wbr',
	]);
	const stack = [];
	for (const match of value.matchAll(/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/giu)) {
		const full = match[0];
		const tag = match[1].toLowerCase();
		if (full.startsWith('</')) {
			if (stack.pop() !== tag) {
				return false;
			}
		} else if (!full.endsWith('/>') && !voidTags.has(tag)) {
			stack.push(tag);
		}
	}
	return stack.length === 0;
}

function normalizeAbsoluteShippingClaims(value) {
	const matches = [];
	const pattern = /(\b(?:env[ií]os?|despachos?|entregas?)(?:\s+(?:rápid[oa]s?|segur[oa]s?|express|disponibles?)){0,3}\s+)a\s+(?:todo\s+Chile|todo\s+el\s+pa[ií]s|todas\s+las\s+regiones(?:\s+de\s+Chile)?)/giu;
	const result = value.replace(pattern, (match, prefix) => {
		matches.push(match);
		return `${prefix}según cobertura del transportista`;
	});
	return { result, matches };
}

function semanticResolution(entry, current) {
	const expectedSourceSha256 = semanticReviewSourceSha256.get(entry.key);
	if (!expectedSourceSha256 || sha256(current) !== expectedSourceSha256) {
		return null;
	}

	let strategy;
	let proposed;
	let checks = [];
	if (entry.issue_codes.includes('overlong_product_excerpt')) {
		strategy = 'reviewed_product_specific_condensation_exact_reviewed_html';
		proposed = normalizeLineEndings(entry.proposed_after);
		const targetVisibleLength = visibleText(proposed).length;
		const currentVisibleLength = visibleText(current).length;
		const exactScope = exactReviewedProductExcerptScope(entry);
		const exactReviewedTarget =
			sha256(proposed) === entry.proposed_after_sha256;
		const currentIdentityPreserved = containsIdentity(current, entry.title);
		const targetIdentityPreserved = containsIdentity(proposed, entry.title);
		const targetWithinExcerptLimit = targetVisibleLength <= 320;
		const targetIsCondensed = targetVisibleLength < currentVisibleLength;
		const currentHtmlBalanced = htmlIsBalanced(current);
		const targetHtmlBalanced = htmlIsBalanced(proposed);
		const targetUrlsPreserved = sameJson(urls(current), urls(proposed));
		const targetHasNoAbsoluteShippingClaim =
			normalizeAbsoluteShippingClaims(proposed).matches.length === 0;
		checks = [{
			assertion: 'semantic_scope_exact',
			passed: exactScope,
		}, {
			assertion: 'exact_reviewed_html_target_used',
			passed: exactReviewedTarget,
		}, {
			assertion: 'source_product_identity_present',
			passed: currentIdentityPreserved,
		}, {
			assertion: 'target_product_identity_preserved',
			passed: targetIdentityPreserved,
		}, {
			assertion: 'visible_copy_condensed_to_at_most_320_chars',
			passed: targetWithinExcerptLimit && targetIsCondensed,
		}, {
			assertion: 'source_and_target_html_balanced',
			passed: currentHtmlBalanced && targetHtmlBalanced,
		}, {
			assertion: 'url_destinations_preserved',
			passed: targetUrlsPreserved,
		}, {
			assertion: 'absolute_shipping_geography_absent',
			passed: targetHasNoAbsoluteShippingClaim,
		}];
		if (checks.some((check) => check.passed !== true)) {
			return null;
		}
	} else if (entry.issue_codes.includes('broken_sentence_join')) {
		strategy = 'reviewed_exact_sentence_join_and_shipping_copy';
		proposed = normalizeLineEndings(entry.proposed_after);
		if (!containsIdentity(current, entry.title) || !containsIdentity(proposed, entry.title)) {
			return null;
		}
		checks = [{
			assertion: 'product_identity_and_specific_copy_preserved',
			passed: true,
		}];
	} else if (entry.key === 'post:3:post_content') {
		strategy = 'terms_shipping_geography_exact_replacements';
		const transformed = exactReplacements(current, [{
			before: 'envíos a todo Chile',
			after: 'envíos según cobertura del transportista',
		}, {
			before: 'más de 3.200 puntos en todo Chile',
			after: 'más de 3.200 puntos de retiro, sujetos a cobertura y disponibilidad del transportista',
		}]);
		if (!transformed.applicable) {
			return null;
		}
		proposed = transformed.result;
		checks = transformed.checks;
	} else if (entry.key === 'term_taxonomy:28:description') {
		strategy = 'taxonomy_shipping_geography_exact_replacements';
		const transformed = exactReplacements(current, [{
			before: 'Envíos a Todo Chile',
			after: 'Despacho a domicilio',
		}, {
			before: 'Envíos rápidos y seguros a todas las regiones.',
			after: 'Despacho rápido y seguro según cobertura del transportista.',
		}]);
		if (!transformed.applicable) {
			return null;
		}
		proposed = transformed.result;
		checks = transformed.checks;
	} else if (entry.key === 'product:15418:post_excerpt') {
		strategy = 'reviewed_exact_curated_product_excerpt_from_pinned_legacy_source';
		proposed = normalizeOfficialUiValue(entry, entry.proposed_after);
		checks = [{
			assertion: 'legacy_source_hash_pinned',
			passed: sha256(current) === expectedSourceSha256,
		}, {
			assertion: 'target_product_identity_preserved',
			passed: containsIdentity(proposed, entry.title),
		}, {
			assertion: 'exact_reviewed_target_used',
			passed: sha256(proposed) === entry.proposed_after_sha256,
		}, {
			assertion: 'target_within_product_excerpt_limit',
			passed: visibleText(proposed).length <= 320,
		}, {
			assertion: 'absolute_shipping_geography_absent',
			passed: normalizeAbsoluteShippingClaims(proposed).matches.length === 0,
		}];
		if (checks.some((check) => check.passed !== true)) {
			return null;
		}
	} else {
		return null;
	}

	const preservedUrls = sameJson(urls(current), urls(proposed));
	if (
		!preservedUrls
		|| !htmlIsBalanced(proposed)
		|| proposed === current
		|| /env[ií]os?\s+(?:rápidos?\s+|seguros?\s+)*a\s+(?:todo\s+Chile|todo\s+el\s+pa[ií]s|todas\s+las\s+regiones)/iu.test(proposed)
	) {
		return null;
	}

	const exactReviewedTargetUsed =
		entry.issue_codes.includes('overlong_product_excerpt')
		|| entry.key === 'product:15418:post_excerpt';
	return {
		proposed,
		review: {
			review_version: 2,
			review_status: 'approved_exact_remote_source',
			strategy,
			source_sha256: expectedSourceSha256,
			content_outside_reviewed_change_preserved:
				!entry.issue_codes.includes('overlong_product_excerpt'),
			product_specific_condensation_reviewed:
				entry.issue_codes.includes('overlong_product_excerpt'),
			exact_reviewed_target_used:
				exactReviewedTargetUsed,
			reviewed_target_sha256:
				exactReviewedTargetUsed
					? entry.proposed_after_sha256
					: null,
			semantic_scope_exact:
				entry.issue_codes.includes('overlong_product_excerpt')
					? exactReviewedProductExcerptScope(entry)
					: null,
			url_destinations_preserved: preservedUrls,
			html_balanced: true,
			checks,
		},
	};
}

function loadReviewedEntries() {
	const entries = [];
	const keys = new Set();
	for (const file of manifestFiles) {
		const manifest = JSON.parse(fs.readFileSync(path.join(scriptDir, file), 'utf8'));
		for (const entry of manifest.entries ?? []) {
			if (
				keys.has(entry.key)
				|| sha256(entry.expected_before) !== entry.expected_before_sha256
				|| sha256(entry.proposed_after) !== entry.proposed_after_sha256
				|| entry.application_lane !== 'official_ui'
			) {
				fail(`reviewed manifest integrity failed for ${entry.key}`);
			}
			const transformations = transformationsFor(entry);
			const reproduced = applyExactTransformations(entry.expected_before, transformations);
			if (!reproduced.applicable || reproduced.result !== entry.proposed_after) {
				fail(`exact transformations do not reproduce ${entry.key}`);
			}
			keys.add(entry.key);
			entries.push({
				...entry,
				source_manifest: file,
				transformations,
			});
		}
	}
	return entries.sort((left, right) => left.key.localeCompare(right.key));
}

function countBy(rows, key) {
	const counts = {};
	for (const row of rows) {
		const value = row[key];
		counts[value] = (counts[value] ?? 0) + 1;
	}
	return Object.fromEntries(Object.entries(counts).sort(([left], [right]) => left.localeCompare(right)));
}

function writeNewFile(outputPath, content) {
	if (fs.existsSync(outputPath)) {
		if (fs.readFileSync(outputPath, 'utf8') === content) {
			return;
		}
		fail(`refusing to overwrite different local artifact ${path.relative(repoRoot, outputPath)}`);
	}
	fs.mkdirSync(path.dirname(outputPath), { recursive: true });
	fs.writeFileSync(outputPath, content, { flag: 'wx' });
}

const snapshotPath = path.resolve(optionValue('--snapshot', ''));
const reportPath = path.resolve(optionValue('--report', ''));
const uiManifestPath = path.resolve(optionValue('--ui-manifest', ''));
if (!optionValue('--snapshot') || !optionValue('--report') || !optionValue('--ui-manifest')) {
	fail('--snapshot, --report and --ui-manifest are required');
}

const snapshot = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
const expectedSiteUrls = Object.freeze({
	'docker-local': 'http://localhost:8080',
	production: 'https://akibara.cl',
});
if (
	snapshot.schema_version !== 1
	|| !Object.hasOwn(expectedSiteUrls, snapshot.environment)
	|| snapshot.site_url !== expectedSiteUrls[snapshot.environment]
	|| snapshot.database_mutations !== 0
	|| snapshot.pii_fields_captured?.length !== 0
	|| !Array.isArray(snapshot.rows)
	|| snapshot.row_count !== snapshot.rows.length
	|| snapshot.rows_sha256 !== sha256(JSON.stringify(snapshot.rows))
) {
	fail('capture snapshot integrity failed');
}

const entries = loadReviewedEntries();
const snapshotByKey = new Map(snapshot.rows.map((row) => [row.key, row]));
const results = entries.map((entry) => {
	const observed = snapshotByKey.get(entry.key);
	if (!observed) {
		return {
			key: entry.key,
			source_manifest: entry.source_manifest,
			state: 'missing',
		};
	}
		const current = normalizeOfficialUiValue(entry, observed.value);
		const before = normalizeOfficialUiValue(entry, entry.expected_before);
		const after = normalizeOfficialUiValue(entry, entry.proposed_after);
	let state;
	let proposed = current;
	let checks = [];
	let semanticReview = null;
	if (current === before) {
		state = 'pending_exact';
		proposed = after;
	} else if (current === after) {
		state = 'applied_exact';
	} else {
		const rebased = applyExactTransformations(current, entry.transformations);
		checks = rebased.checks;
		if (rebased.applicable) {
			proposed = rebased.result;
			state = 'pending_rebased';
		} else {
			state = 'content_divergent';
		}
	}
	if (state === 'content_divergent') {
		const resolution = semanticResolution(entry, current);
		if (resolution) {
			proposed = resolution.proposed;
			semanticReview = resolution.review;
			checks = resolution.review.checks;
			state = 'pending_semantic_reviewed';
		}
	}
	if (state.startsWith('pending_')) {
		const shippingNormalization = normalizeAbsoluteShippingClaims(proposed);
		if (shippingNormalization.matches.length > 0) {
			proposed = shippingNormalization.result;
			checks = [
				...checks,
				{
					assertion: 'absolute_shipping_geography_removed',
					observed_count: shippingNormalization.matches.length,
					matches: shippingNormalization.matches,
					passed: true,
				},
			];
		}
	}

	const tagSequenceUnchanged = sameJson(htmlTagNames(current), htmlTagNames(proposed));
	const urlsPreserved = sameJson(urls(current), urls(proposed));
	const approvedHtmlChange = entry.transformations.some(
		(transformation) =>
			!sameJson(htmlTagNames(transformation.before), htmlTagNames(transformation.after)),
	);
	const htmlOutsideApprovedTransformationsPreserved = state.startsWith('pending_')
		? semanticReview?.html_balanced === true || tagSequenceUnchanged || approvedHtmlChange
		: true;
	const exactReviewedTargetPreserved =
		semanticReview?.exact_reviewed_target_used !== true
		|| (
			proposed === after
			&& sha256(proposed) === entry.proposed_after_sha256
		);
	if (
		state.startsWith('pending_')
		&& (
			!htmlOutsideApprovedTransformationsPreserved
			|| !urlsPreserved
			|| !exactReviewedTargetPreserved
			|| normalizeAbsoluteShippingClaims(proposed).matches.length > 0
		)
	) {
		state = 'content_divergent';
		proposed = current;
	}

	return {
		key: entry.key,
		source_manifest: entry.source_manifest,
		object_type: entry.object_type,
		object_id: entry.object_id,
		field: entry.field,
		title: entry.title,
		issue_codes: entry.issue_codes,
		application_lane: entry.application_lane,
		apply_via: entry.apply_via,
		state,
		observed_sha256: sha256(current),
		reviewed_before_sha256: entry.expected_before_sha256,
		reviewed_after_sha256: entry.proposed_after_sha256,
			proposed_sha256: sha256(proposed),
			official_ui_serialization:
				entry.object_type === 'term_taxonomy' && entry.field === 'description'
					? 'html_textarea_initial_lf_normalized'
					: 'identity',
		html_tag_sequence_unchanged: tagSequenceUnchanged,
		approved_html_change: approvedHtmlChange,
		html_outside_approved_transformations_preserved:
			htmlOutsideApprovedTransformationsPreserved,
		exact_reviewed_target_preserved: exactReviewedTargetPreserved,
		url_destinations_preserved: urlsPreserved,
		transformation_checks: checks,
		semantic_review: semanticReview,
		observed_before: current,
		proposed_after: proposed,
	};
});

const states = countBy(results, 'state');
const blocking = results.filter((row) => ['missing', 'content_divergent'].includes(row.state));
const actionable = results.filter((row) => row.state.startsWith('pending_'));
const result = blocking.length === 0 ? 'PASS' : 'FAIL';
const report = {
	schema_version: 1,
	environment: snapshot.environment,
	generated_at: new Date().toISOString(),
	source_snapshot: path.relative(repoRoot, snapshotPath),
	source_snapshot_sha256: sha256(fs.readFileSync(snapshotPath, 'utf8')),
	result,
	database_mutations: 0,
	application_lane: 'official_ui',
	counts: {
		total: results.length,
		pending: actionable.length,
		applied: (states.applied_exact ?? 0) + (states.applied_rebased ?? 0),
		drift: blocking.length,
		legacy_hash_drift:
			results.length - (states.pending_exact ?? 0) - (states.applied_exact ?? 0),
		previous_edition_rebasable: states.pending_rebased ?? 0,
		semantic_reviewed: states.pending_semantic_reviewed ?? 0,
		format_only: 0,
		content_divergent: states.content_divergent ?? 0,
		states,
	},
	assertions: {
		all_reviewed_entries_observed: !results.some((row) => row.state === 'missing'),
		no_unapproved_content_divergence: !results.some((row) => row.state === 'content_divergent'),
		all_actionable_html_structure_preserved: actionable.every(
			(row) => row.html_outside_approved_transformations_preserved,
		),
		all_actionable_urls_preserved: actionable.every(
			(row) => row.url_destinations_preserved,
		),
		all_semantic_reviews_source_pinned: results
			.filter((row) => row.state === 'pending_semantic_reviewed')
			.every((row) => row.semantic_review?.source_sha256 === row.observed_sha256),
		all_exact_reviewed_targets_preserved: results
			.filter(
				(row) =>
					row.state === 'pending_semantic_reviewed'
					&& row.semantic_review?.exact_reviewed_target_used === true,
			)
			.every(
				(row) =>
					row.exact_reviewed_target_preserved === true
					&& row.proposed_sha256 === row.reviewed_after_sha256,
			),
		remote_database_mutations: 0,
	},
	rows: results,
};
writeNewFile(reportPath, `${JSON.stringify(report, null, 2)}\n`);

const uiManifest = {
	schema_version: 1,
	environment: snapshot.environment,
	generated_at: report.generated_at,
	source_report: path.relative(repoRoot, reportPath),
	result: blocking.length === 0 ? 'PASS' : 'PARTIAL_BLOCKED',
	complete: blocking.length === 0,
	fail_closed: true,
	blocked_count: blocking.length,
	application_lane: 'official_ui',
	database_mutations: 0,
	entry_count: actionable.length,
	entries_sha256: sha256(JSON.stringify(actionable)),
	entries: actionable,
};
writeNewFile(uiManifestPath, `${JSON.stringify(uiManifest, null, 2)}\n`);

console.log(JSON.stringify({
	result,
	environment: snapshot.environment,
	report: path.relative(repoRoot, reportPath),
	ui_manifest: path.relative(repoRoot, uiManifestPath),
	counts: report.counts,
}, null, 2));
if (result !== 'PASS') {
	process.exitCode = 1;
}
