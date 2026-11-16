#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	finalizeInlineVisualManifest,
	inlineVisualSha256,
	INLINE_VISUAL_CORPUS,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';
import {
	normalizeEditorialValue,
	validateManifest as validateSeoManifest,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	resolveInlineVisualAnchor,
} from './lib/blog-inline-visuals-content.mjs';

function fail(message) {
	throw new Error(`Inline visual manifest generation failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function fileSha256(file) {
	return inlineVisualSha256(fs.readFileSync(file));
}

function readJson(file, label) {
	if (
		!fs.existsSync(file)
		|| !fs.lstatSync(file).isFile()
		|| fs.lstatSync(file).isSymbolicLink()
	) {
		fail(`${label} must be a regular local file`);
	}
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch {
		fail(`${label} is not valid JSON`);
	}
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localOutput(value, repository) {
	if (!value) fail('missing --output');
	const output = path.resolve(value);
	const roots = ['.local-reports', '.local-checkpoints']
		.map((name) => path.join(repository, name));
	if (!roots.some((root) => inside(output, root))) {
		fail('output must remain under .local-reports or .local-checkpoints');
	}
	if (fs.existsSync(output)) fail('refusing to overwrite output');
	return output;
}

function validateAssetsSource(source) {
	if (
		source?.schema_version !== 1
		|| source.asset_count !== INLINE_VISUAL_CORPUS.assetCount
		|| source.post_count !== INLINE_VISUAL_CORPUS.assetPostCount
		|| source.format !== 'WebP'
		|| source.dimensions
			!== `${INLINE_VISUAL_CORPUS.width}x${INLINE_VISUAL_CORPUS.height}`
		|| !Array.isArray(source.assets)
		|| source.assets.length !== INLINE_VISUAL_CORPUS.assetCount
	) {
		fail('assets source is not the complete reviewed visual corpus');
	}
	const files = new Set();
	const hashes = new Set();
	for (const asset of source.assets) {
		if (
			!Number.isSafeInteger(asset?.post_id)
			|| asset.post_id < 1
			|| typeof asset.file !== 'string'
			|| !/^[^/\\]+\.webp$/u.test(asset.file)
			|| files.has(asset.file)
			|| typeof asset.role !== 'string'
			|| !/^[a-z0-9_]+$/u.test(asset.role)
			|| !/^[a-f0-9]{64}$/u.test(asset.sha256 ?? '')
			|| hashes.has(asset.sha256)
			|| !Number.isSafeInteger(asset.bytes)
			|| asset.bytes < 1024
			|| asset.dimensions
				!== `${INLINE_VISUAL_CORPUS.width}x${INLINE_VISUAL_CORPUS.height}`
			|| typeof asset.alt !== 'string'
			|| !asset.alt.trim()
			|| typeof asset.caption !== 'string'
			|| !asset.caption.trim()
		) {
			fail(`invalid asset source row ${asset?.file ?? '(missing)'}`);
		}
		files.add(asset.file);
		hashes.add(asset.sha256);
	}
	return new Map(source.assets.map((asset) => [asset.file, asset]));
}

function validateCapture(source, expectedEnvironment, label) {
	const expectedOrigin = expectedEnvironment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
	if (
		source?.schema_version !== 1
		|| source.capture_type
			!== 'akibara_blog_seo_official_ui_read_only'
		|| source.capture_tool !== 'akibara-playwright-official-ui-v1'
		|| source.capture_policy
			!== 'official_wordpress_rankmath_ui_read_only'
		|| source.direct_writes !== 0
		|| source.environment !== expectedEnvironment
		|| !expectedOrigin.test(source.target_origin ?? '')
		|| !Array.isArray(source.posts)
		|| source.posts.length !== INLINE_VISUAL_CORPUS.postCount
		|| Object.prototype.hasOwnProperty.call(
			source,
			'deferred_content_keys'
		)
		|| Object.prototype.hasOwnProperty.call(
			source,
			'validation_artifact'
		)
	) {
		fail(`${label} must be a complete 25-post official-UI capture`);
	}
	const keys = new Set();
	const ids = new Set();
	for (const post of source.posts) {
		if (
			typeof post?.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
			|| !Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| ids.has(post.post_id)
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${source.target_origin}/`)
			|| typeof post.fields?.post_title !== 'string'
			|| !post.fields.post_title.trim()
			|| typeof post.fields?.post_content !== 'string'
			|| !post.fields.post_content.trim()
		) {
			fail(`${label} has an invalid post row`);
		}
		keys.add(post.content_key);
		ids.add(post.post_id);
	}
	return {
		source,
		byKey: new Map(source.posts.map((post) => [post.content_key, post])),
		byId: new Map(source.posts.map((post) => [post.post_id, post])),
	};
}

function validatePlacements(source, assetsByFile, production) {
	if (
		source?.schema_version !== 1
		|| source.placement_type
			!== 'akibara_blog_inline_visual_editorial_placements'
		|| source.reviewed !== true
		|| !/^[a-f0-9]{64}$/u.test(source.assets_manifest_sha256 ?? '')
		|| !Array.isArray(source.entries)
		|| source.entries.length !== INLINE_VISUAL_CORPUS.assetCount
	) {
		fail('placements must be the complete explicitly reviewed set');
	}
	const files = new Set();
	const byFile = new Map();
	for (const entry of source.entries) {
		const sourceAsset = assetsByFile.get(entry?.file);
		const productionPost = production.byId.get(entry?.production_post_id);
		if (
			!sourceAsset
			|| files.has(entry.file)
			|| sourceAsset.post_id !== entry.production_post_id
			|| !productionPost
			|| entry.content_key !== productionPost.content_key
			|| !Number.isSafeInteger(entry.sequence)
			|| entry.sequence < 1
			|| !entry.anchor
			|| !['gutenberg_block_text', 'custom_html_fragment']
				.includes(entry.anchor.kind)
			|| typeof entry.anchor.value !== 'string'
			|| entry.anchor.value.trim().length < 8
			|| entry.anchor.occurrence !== 1
			|| entry.anchor.relation !== 'after'
		) {
			fail(`invalid placement for ${entry?.file ?? '(missing)'}`);
		}
		files.add(entry.file);
		byFile.set(entry.file, entry);
	}
	const sequenceByPost = new Map();
	for (const entry of source.entries) {
		if (!sequenceByPost.has(entry.content_key)) {
			sequenceByPost.set(entry.content_key, new Set());
		}
		if (sequenceByPost.get(entry.content_key).has(entry.sequence)) {
			fail(`duplicate sequence for ${entry.content_key}`);
		}
		sequenceByPost.get(entry.content_key).add(entry.sequence);
	}
	for (const [contentKey, sequences] of sequenceByPost) {
		const ordered = [...sequences].sort((left, right) => left - right);
		if (!ordered.every((value, index) => value === index + 1)) {
			fail(`placement sequence is not contiguous for ${contentKey}`);
		}
	}
	return byFile;
}

export function generateInlineVisualManifest({
	assetsSource,
	assetsSourceSha256,
	placements,
	placementsSha256,
	productionCapture,
	productionCaptureSha256,
	environmentCapture,
	environmentCaptureSha256,
	environment,
	seoManifest = null,
}) {
	const assetsByFile = validateAssetsSource(assetsSource);
	const production = validateCapture(
		productionCapture,
		'production',
		'production capture'
	);
	const target = validateCapture(
		environmentCapture,
		environment,
		'environment capture'
	);
	const reviewedSeo = seoManifest === null
		? null
		: validateSeoManifest(structuredClone(seoManifest));
	if (
		reviewedSeo
		&& (
			reviewedSeo.environment !== environment
			|| reviewedSeo.target_origin !== target.source.target_origin
			|| reviewedSeo.posts.length !== INLINE_VISUAL_CORPUS.postCount
			|| (reviewedSeo.sources?.deferred_content_keys ?? []).length !== 0
		)
	) {
		fail(
			'reviewed SEO manifest must be the complete non-deferred '
			+ '25-post target for this environment'
		);
	}
	const seoById = reviewedSeo
		? new Map(reviewedSeo.posts.map((post) => [post.post_id, post]))
		: null;
	if (seoById && seoById.size !== target.byId.size) {
		fail('reviewed SEO manifest and target capture post sets differ');
	}
	const reviewedTargetByKey = new Map();
	for (const targetPost of target.source.posts) {
		const seoPost = seoById?.get(targetPost.post_id);
		if (
			seoById
			&& (
				!seoPost
				|| seoPost.public_url !== targetPost.public_url
				|| seoPost.edit_path
					!== `/wp-admin/post.php?post=${targetPost.post_id}&action=edit`
			)
		) {
			fail(
				`reviewed SEO identity or historical URL drift for `
				+ `${targetPost.content_key}`
			);
		}
		reviewedTargetByKey.set(targetPost.content_key, {
			...targetPost,
			fields: seoPost
				? {
					post_title: normalizeEditorialValue(
						seoPost.fields.post_title.after
					),
					post_content: normalizeEditorialValue(
						seoPost.fields.post_content.after
					),
				}
				: targetPost.fields,
		});
	}
	if (placements.assets_manifest_sha256 !== assetsSourceSha256) {
		fail('placements were reviewed against another asset manifest');
	}
	const placementsByFile = validatePlacements(
		placements,
		assetsByFile,
		production
	);
	const productionKeys = [...production.byKey.keys()].sort();
	const targetKeys = [...target.byKey.keys()].sort();
	if (JSON.stringify(productionKeys) !== JSON.stringify(targetKeys)) {
		fail('production and target captures do not contain the same 25 keys');
	}

	const assets = assetsSource.assets.map((sourceAsset) => {
		const placement = placementsByFile.get(sourceAsset.file);
		const targetPost = reviewedTargetByKey.get(placement.content_key);
		try {
			resolveInlineVisualAnchor(
				targetPost.fields.post_content,
				placement.anchor
			);
		} catch (error) {
			fail(
				`anchor does not resolve for ${sourceAsset.file}: ${error.message}`
			);
		}
		return {
			content_key: placement.content_key,
			post_id: targetPost.post_id,
			role: sourceAsset.role,
			local_file:
				`${INLINE_VISUAL_CORPUS.localDirectory}/${sourceAsset.file}`,
			expected_filename: sourceAsset.file,
			sha256: sourceAsset.sha256,
			bytes: sourceAsset.bytes,
			width: INLINE_VISUAL_CORPUS.width,
			height: INLINE_VISUAL_CORPUS.height,
			mime: INLINE_VISUAL_CORPUS.mime,
			alt: sourceAsset.alt.trim(),
			caption: sourceAsset.caption.trim(),
			marker_class:
				`akb-inline-visual--${sourceAsset.sha256.slice(0, 12)}`,
			sequence: placement.sequence,
			anchor: placement.anchor,
		};
	});
	const posts = target.source.posts
		.map((post) => ({
			content_key: post.content_key,
			post_id: post.post_id,
			edit_path: `/wp-admin/post.php?post=${post.post_id}&action=edit`,
			public_url: post.public_url,
			post_title:
				reviewedTargetByKey.get(post.content_key).fields.post_title,
			before_content_sha256:
				inlineVisualSha256(
					reviewedTargetByKey.get(post.content_key)
						.fields.post_content.replace(/\r\n?/gu, '\n')
				),
			asset_sha256s: assets
				.filter((asset) => asset.content_key === post.content_key)
				.sort((left, right) => left.sequence - right.sequence)
				.map((asset) => asset.sha256),
		}))
		.sort((left, right) => left.content_key.localeCompare(right.content_key));
	return finalizeInlineVisualManifest({
		schema_version: 1,
		manifest_type: 'akibara_blog_inline_visuals_official_ui',
		environment,
		target_origin: target.source.target_origin,
		corpus: {
			post_count: INLINE_VISUAL_CORPUS.postCount,
			asset_count: INLINE_VISUAL_CORPUS.assetCount,
			asset_post_count: INLINE_VISUAL_CORPUS.assetPostCount,
		},
		application_policy: {
			official_wordpress_media_ui_only: true,
			official_gutenberg_ui_only: true,
			global_preflight_before_first_write: true,
			local_preimage_rollback: true,
			production_requires_complete_manifest: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_content_writes: 0,
			remote_backups_or_temporary_files: 0,
		},
		sources: {
			assets_manifest_sha256: assetsSourceSha256,
			placements_sha256: placementsSha256,
			production_capture_sha256: productionCaptureSha256,
			environment_capture_sha256: environmentCaptureSha256,
			...(reviewedSeo
				? {
					seo_manifest_sha256: reviewedSeo.manifest_sha256,
					target_content_policy:
						'reviewed_seo_manifest_after_values',
				}
				: {}),
		},
		posts,
		assets,
	});
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function main() {
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const values = {
		assets: option('--assets'),
		placements: option('--placements'),
		productionCapture: option('--production-capture'),
		environmentCapture: option('--environment-capture'),
	};
	for (const [label, value] of Object.entries(values)) {
		if (!value) fail(`missing --${label.replace(/[A-Z]/gu, (c) => `-${c.toLowerCase()}`)}`);
	}
	const environment = option('--environment');
	if (!['docker', 'production'].includes(environment)) {
		fail('--environment must be docker or production');
	}
	const files = Object.fromEntries(Object.entries(values).map(([key, value]) => [
		key,
		path.resolve(value),
	]));
	const output = localOutput(option('--output'), repository);
	const sources = Object.fromEntries(Object.entries(files).map(([key, file]) => [
		key,
		readJson(file, key),
	]));
	const seoManifestFile = option('--seo-manifest');
	const seoManifest = seoManifestFile
		? readJson(path.resolve(seoManifestFile), 'seoManifest')
		: null;
	const manifest = generateInlineVisualManifest({
		assetsSource: sources.assets,
		assetsSourceSha256: fileSha256(files.assets),
		placements: sources.placements,
		placementsSha256: fileSha256(files.placements),
		productionCapture: sources.productionCapture,
		productionCaptureSha256: fileSha256(files.productionCapture),
		environmentCapture: sources.environmentCapture,
		environmentCaptureSha256: fileSha256(files.environmentCapture),
		environment,
		seoManifest,
	});
	writeAtomic(output, manifest);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		posts: manifest.posts.length,
		assets: manifest.assets.length,
		manifest_sha256: manifest.manifest_sha256,
		output,
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	try {
		main();
	} catch (error) {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	}
}
