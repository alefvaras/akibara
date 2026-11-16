import crypto from 'node:crypto';

export const INLINE_VISUAL_CORPUS = Object.freeze({
	postCount: 25,
	assetCount: 32,
	assetPostCount: 18,
	width: 1200,
	height: 675,
	mime: 'image/webp',
	localDirectory: 'output/blog-seo-inline-visuals-20260726',
});

function fail(message) {
	throw new Error(`Inline visual official UI manifest rejected: ${message}`);
}

export function canonicalJson(value) {
	if (Array.isArray(value)) {
		return `[${value.map((entry) => canonicalJson(entry)).join(',')}]`;
	}
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => (
			`${JSON.stringify(key)}:${canonicalJson(value[key])}`
		)).join(',')}}`;
	}
	return JSON.stringify(value);
}

export function inlineVisualSha256(value) {
	return crypto
		.createHash('sha256')
		.update(Buffer.isBuffer(value) ? value : String(value))
		.digest('hex');
}

function exactKeys(value, allowed, label) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		fail(`${label} must be an object`);
	}
	const unexpected = Object.keys(value)
		.filter((key) => !allowed.includes(key));
	const missing = allowed.filter((key) => !(key in value));
	if (unexpected.length > 0 || missing.length > 0) {
		fail(
			`${label} keys differ; unexpected=${unexpected.join(',')}; `
			+ `missing=${missing.join(',')}`
		);
	}
}

function validOrigin(environment, origin) {
	if (environment === 'docker') {
		return /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
			.test(origin ?? '');
	}
	return environment === 'production'
		&& /^https:\/\/(?:www\.)?akibara\.cl$/u.test(origin ?? '');
}

function validatePolicy(policy) {
	exactKeys(
		policy,
		[
			'official_wordpress_media_ui_only',
			'official_gutenberg_ui_only',
			'global_preflight_before_first_write',
			'local_preimage_rollback',
			'production_requires_complete_manifest',
			'direct_rest_writes',
			'direct_sql_writes',
			'direct_wp_cli_content_writes',
			'remote_backups_or_temporary_files',
		],
		'application_policy'
	);
	if (
		policy.official_wordpress_media_ui_only !== true
		|| policy.official_gutenberg_ui_only !== true
		|| policy.global_preflight_before_first_write !== true
		|| policy.local_preimage_rollback !== true
		|| policy.production_requires_complete_manifest !== true
		|| policy.direct_rest_writes !== 0
		|| policy.direct_sql_writes !== 0
		|| policy.direct_wp_cli_content_writes !== 0
		|| policy.remote_backups_or_temporary_files !== 0
	) {
		fail('application policy is incomplete');
	}
}

function validateAnchor(anchor, label) {
	exactKeys(
		anchor,
		['kind', 'value', 'occurrence', 'relation'],
		label
	);
	if (
		!['gutenberg_block_text', 'custom_html_fragment'].includes(anchor.kind)
		|| typeof anchor.value !== 'string'
		|| anchor.value.trim().length < 8
		|| anchor.occurrence !== 1
		|| anchor.relation !== 'after'
	) {
		fail(`${label} is not a unique explicit editorial anchor`);
	}
}

function validateSources(sources) {
	const required = [
		'assets_manifest_sha256',
		'placements_sha256',
		'production_capture_sha256',
		'environment_capture_sha256',
	];
	const optional = [
		'seo_manifest_sha256',
		'target_content_policy',
	];
	if (!sources || typeof sources !== 'object' || Array.isArray(sources)) {
		fail('sources must be an object');
	}
	const unexpected = Object.keys(sources)
		.filter((key) => ![...required, ...optional].includes(key));
	const missing = required.filter((key) => !(key in sources));
	if (unexpected.length > 0 || missing.length > 0) {
		fail(
			`sources keys differ; unexpected=${unexpected.join(',')}; `
			+ `missing=${missing.join(',')}`
		);
	}
	for (const key of required) {
		if (!/^[a-f0-9]{64}$/u.test(sources[key] ?? '')) {
			fail(`invalid source hash ${key}`);
		}
	}
	const hasSeoHash = Object.prototype.hasOwnProperty.call(
		sources,
		'seo_manifest_sha256'
	);
	const hasTargetPolicy = Object.prototype.hasOwnProperty.call(
		sources,
		'target_content_policy'
	);
	if (hasSeoHash !== hasTargetPolicy) {
		fail('SEO source binding must include both hash and policy');
	}
	if (
		hasSeoHash
		&& (
			!/^[a-f0-9]{64}$/u.test(sources.seo_manifest_sha256 ?? '')
			|| sources.target_content_policy
				!== 'reviewed_seo_manifest_after_values'
		)
	) {
		fail('SEO source binding is invalid');
	}
}

function manifestPayload(manifest) {
	const { manifest_sha256: ignored, ...payload } = manifest;
	return payload;
}

export function calculateInlineVisualManifestSha256(manifest) {
	return inlineVisualSha256(canonicalJson(manifestPayload(manifest)));
}

export function finalizeInlineVisualManifest(draft) {
	const manifest = structuredClone(draft);
	manifest.manifest_sha256 = calculateInlineVisualManifestSha256(manifest);
	return validateInlineVisualManifest(manifest);
}

export function validateInlineVisualManifest(manifest) {
	exactKeys(
		manifest,
		[
			'schema_version',
			'manifest_type',
			'environment',
			'target_origin',
			'corpus',
			'application_policy',
			'sources',
			'posts',
			'assets',
			'manifest_sha256',
		],
		'manifest'
	);
	if (
		manifest.schema_version !== 1
		|| manifest.manifest_type
			!== 'akibara_blog_inline_visuals_official_ui'
		|| !validOrigin(manifest.environment, manifest.target_origin)
		|| !/^[a-f0-9]{64}$/u.test(manifest.manifest_sha256 ?? '')
		|| calculateInlineVisualManifestSha256(manifest)
			!== manifest.manifest_sha256
	) {
		fail('schema, environment, origin or manifest hash is invalid');
	}
	exactKeys(
		manifest.corpus,
		['post_count', 'asset_count', 'asset_post_count'],
		'corpus'
	);
	if (
		manifest.corpus.post_count !== INLINE_VISUAL_CORPUS.postCount
		|| manifest.corpus.asset_count !== INLINE_VISUAL_CORPUS.assetCount
		|| manifest.corpus.asset_post_count
			!== INLINE_VISUAL_CORPUS.assetPostCount
	) {
		fail('corpus must be the complete 25-post/32-asset reviewed set');
	}
	validatePolicy(manifest.application_policy);
	validateSources(manifest.sources);
	if (
		!Array.isArray(manifest.posts)
		|| manifest.posts.length !== INLINE_VISUAL_CORPUS.postCount
		|| !Array.isArray(manifest.assets)
		|| manifest.assets.length !== INLINE_VISUAL_CORPUS.assetCount
	) {
		fail('manifest is not globally complete');
	}

	const postsByKey = new Map();
	const postIds = new Set();
	for (const post of manifest.posts) {
		exactKeys(
			post,
			[
				'content_key',
				'post_id',
				'edit_path',
				'public_url',
				'post_title',
				'before_content_sha256',
				'asset_sha256s',
			],
			`post ${post?.content_key ?? '(missing key)'}`
		);
		if (
			typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| postsByKey.has(post.content_key)
			|| !Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| postIds.has(post.post_id)
			|| post.edit_path
				!== `/wp-admin/post.php?post=${post.post_id}&action=edit`
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${manifest.target_origin}/`)
			|| typeof post.post_title !== 'string'
			|| !post.post_title.trim()
			|| !/^[a-f0-9]{64}$/u.test(post.before_content_sha256 ?? '')
			|| !Array.isArray(post.asset_sha256s)
		) {
			fail(`invalid post contract for ${post?.content_key}`);
		}
		postsByKey.set(post.content_key, post);
		postIds.add(post.post_id);
	}

	const assetHashes = new Set();
	const assetFiles = new Set();
	const assetPosts = new Set();
	const sequenceByPost = new Map();
	for (const asset of manifest.assets) {
		exactKeys(
			asset,
			[
				'content_key',
				'post_id',
				'role',
				'local_file',
				'expected_filename',
				'sha256',
				'bytes',
				'width',
				'height',
				'mime',
				'alt',
				'caption',
				'marker_class',
				'sequence',
				'anchor',
			],
			`asset ${asset?.expected_filename ?? '(missing file)'}`
		);
		const post = postsByKey.get(asset.content_key);
		if (
			!post
			|| asset.post_id !== post.post_id
			|| typeof asset.role !== 'string'
			|| !/^[a-z0-9_]+$/u.test(asset.role)
			|| typeof asset.local_file !== 'string'
			|| !new RegExp(
				`^${INLINE_VISUAL_CORPUS.localDirectory.replaceAll('/', '\\/')}`
				+ '/[^/\\\\]+\\.webp$',
				'u'
			).test(asset.local_file)
			|| asset.expected_filename !== asset.local_file.split('/').at(-1)
			|| assetFiles.has(asset.expected_filename)
			|| !/^[a-f0-9]{64}$/u.test(asset.sha256 ?? '')
			|| assetHashes.has(asset.sha256)
			|| !Number.isSafeInteger(asset.bytes)
			|| asset.bytes < 1024
			|| asset.width !== INLINE_VISUAL_CORPUS.width
			|| asset.height !== INLINE_VISUAL_CORPUS.height
			|| asset.mime !== INLINE_VISUAL_CORPUS.mime
			|| typeof asset.alt !== 'string'
			|| asset.alt.trim().length < 20
			|| typeof asset.caption !== 'string'
			|| asset.caption.trim().length < 20
			|| asset.marker_class !== `akb-inline-visual--${asset.sha256.slice(0, 12)}`
			|| !Number.isSafeInteger(asset.sequence)
			|| asset.sequence < 1
		) {
			fail(`invalid asset contract for ${asset?.expected_filename}`);
		}
		validateAnchor(asset.anchor, `anchor ${asset.expected_filename}`);
		assetHashes.add(asset.sha256);
		assetFiles.add(asset.expected_filename);
		assetPosts.add(asset.content_key);
		if (!sequenceByPost.has(asset.content_key)) {
			sequenceByPost.set(asset.content_key, new Set());
		}
		const sequences = sequenceByPost.get(asset.content_key);
		if (sequences.has(asset.sequence)) {
			fail(`duplicate sequence for ${asset.content_key}`);
		}
		sequences.add(asset.sequence);
	}
	if (assetPosts.size !== INLINE_VISUAL_CORPUS.assetPostCount) {
		fail('asset-bearing post count is incomplete');
	}
	for (const [contentKey, sequences] of sequenceByPost) {
		const ordered = [...sequences].sort((left, right) => left - right);
		if (!ordered.every((value, index) => value === index + 1)) {
			fail(`asset sequence is not contiguous for ${contentKey}`);
		}
	}
	for (const post of manifest.posts) {
		const expected = manifest.assets
			.filter((asset) => asset.content_key === post.content_key)
			.sort((a, b) => a.sequence - b.sequence)
			.map((asset) => asset.sha256);
		if (
			JSON.stringify(post.asset_sha256s)
				!== JSON.stringify(expected)
		) {
			fail(`post asset index drifted for ${post.content_key}`);
		}
	}
	return manifest;
}
