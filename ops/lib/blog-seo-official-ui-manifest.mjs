import crypto from 'node:crypto';

const REQUIRED_FIELDS = Object.freeze([
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
]);

const ALLOWED_ENVIRONMENTS = Object.freeze(['docker', 'production']);

function fail(message) {
	throw new Error(`Blog SEO official UI manifest rejected: ${message}`);
}

export function normalizeEditorialValue(value) {
	return String(value).replace(/\r\n?/gu, '\n');
}

export function sha256(value) {
	return crypto
		.createHash('sha256')
		.update(String(value), 'utf8')
		.digest('hex');
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

function assertExactKeys(value, allowed, label) {
	const keys = Object.keys(value ?? {});
	const unexpected = keys.filter((key) => !allowed.includes(key));
	if (unexpected.length > 0) {
		fail(`${label} has unexpected keys: ${unexpected.join(', ')}`);
	}
}

function expectedTargetOrigin(environment) {
	return environment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
}

function validateValueContract(contract, label, identityKeys = []) {
	assertExactKeys(
		contract,
		[
			...identityKeys,
			'before',
			'after',
			'before_sha256',
			'after_sha256',
		],
		label
	);
	if (
		typeof contract?.before !== 'string'
		|| typeof contract?.after !== 'string'
		|| !/^[a-f0-9]{64}$/u.test(contract?.before_sha256 ?? '')
		|| !/^[a-f0-9]{64}$/u.test(contract?.after_sha256 ?? '')
	) {
		fail(`${label} has an incomplete before/after contract`);
	}
	const before = normalizeEditorialValue(contract.before);
	const after = normalizeEditorialValue(contract.after);
	if (
		contract.before_sha256 !== sha256(before)
		|| contract.after_sha256 !== sha256(after)
	) {
		fail(`${label} before/after hash mismatch`);
	}
}

function validateSeoQuality(fields, postId) {
	const after = Object.fromEntries(
		REQUIRED_FIELDS.map((field) => [
			field,
			normalizeEditorialValue(fields[field].after).trim(),
		])
	);
	if (
		!after.post_title
		|| !after.post_content
		|| !after.post_excerpt
		|| !after.rank_math_focus_keyword
		|| !after.rank_math_title
		|| !after.rank_math_description
	) {
		fail(`post ${postId} has a blank required after value`);
	}
	if (after.rank_math_title.length > 70) {
		fail(`post ${postId} Rank Math title exceeds 70 characters`);
	}
	if (
		after.rank_math_description.length < 80
		|| after.rank_math_description.length > 170
	) {
		fail(
			`post ${postId} Rank Math description must be 80-170 characters`
		);
	}
	const keywords = after.rank_math_focus_keyword
		.split(',')
		.map((keyword) => keyword.trim())
		.filter(Boolean);
	if (
		keywords.length < 1
		|| keywords.length > 5
		|| new Set(keywords.map((keyword) => keyword.toLocaleLowerCase('es-CL')))
			.size !== keywords.length
	) {
		fail(`post ${postId} must have 1-5 unique Rank Math focus keywords`);
	}
}

export function manifestPayload(manifest) {
	return {
		schema_version: manifest.schema_version,
		manifest_type: manifest.manifest_type,
		environment: manifest.environment,
		target_origin: manifest.target_origin,
		application_policy: manifest.application_policy,
		...(manifest.sources ? { sources: manifest.sources } : {}),
		posts: manifest.posts,
		items_sha256: manifest.items_sha256,
	};
}

export function flattenManifestItems(manifest) {
	const items = [];
	for (const post of manifest.posts ?? []) {
		for (const field of REQUIRED_FIELDS) {
			const contract = post.fields[field];
			items.push({
				key: `post:${post.post_id}:${field}`,
				object_type: 'post',
				object_id: post.post_id,
				field,
				before_sha256: contract.before_sha256,
				after_sha256: contract.after_sha256,
			});
		}
		if (post.featured_image_alt) {
			const contract = post.featured_image_alt;
			items.push({
				key: `attachment:${contract.attachment_id}:alt`,
				object_type: 'attachment',
				object_id: contract.attachment_id,
				field: 'featured_image_alt',
				before_sha256: contract.before_sha256,
				after_sha256: contract.after_sha256,
			});
		}
	}
	return items;
}

export function calculateItemsSha256(manifest) {
	return sha256(canonicalJson(flattenManifestItems(manifest)));
}

export function calculateManifestSha256(manifest) {
	return sha256(canonicalJson(manifestPayload(manifest)));
}

export function validateManifest(manifest) {
	assertExactKeys(
		manifest,
		[
			'schema_version',
			'manifest_type',
			'environment',
			'target_origin',
			'application_policy',
			'sources',
			'posts',
			'items_sha256',
			'manifest_sha256',
		],
		'manifest'
	);
	if (
		manifest?.schema_version !== 1
		|| manifest?.manifest_type !== 'akibara_blog_seo_official_ui'
		|| !ALLOWED_ENVIRONMENTS.includes(manifest?.environment)
		|| !expectedTargetOrigin(manifest?.environment)
			.test(manifest?.target_origin ?? '')
	) {
		fail('schema, type, environment or target origin is invalid');
	}
	assertExactKeys(
		manifest.application_policy,
		[
			'official_wordpress_ui_only',
			'official_rank_math_ui_only',
			'direct_rest_writes',
			'direct_sql_writes',
			'direct_wp_cli_writes',
			'direct_meta_writes',
			'remote_artifacts',
		],
		'application_policy'
	);
	if (
		manifest.application_policy.official_wordpress_ui_only !== true
		|| manifest.application_policy.official_rank_math_ui_only !== true
		|| manifest.application_policy.direct_rest_writes !== 0
		|| manifest.application_policy.direct_sql_writes !== 0
		|| manifest.application_policy.direct_wp_cli_writes !== 0
		|| manifest.application_policy.direct_meta_writes !== 0
		|| manifest.application_policy.remote_artifacts !== 0
	) {
		fail('official-UI-only policy assertions failed');
	}
	if (manifest.sources !== undefined) {
		assertExactKeys(
			manifest.sources,
			[
				'desired_sha256',
				'production_source_sha256',
				'environment_source_sha256',
				'production_capture_policy',
				'environment_capture_policy',
				'deferred_content_keys',
				'deferred_validation_artifact_sha256',
				'deferred_validation_plan_sha256',
				'deferred_validation_score',
				'deferred_cleanup_result',
			],
			'sources'
		);
		if (
			![
				manifest.sources.desired_sha256,
				manifest.sources.production_source_sha256,
				manifest.sources.environment_source_sha256,
			].every((hash) => /^[a-f0-9]{64}$/u.test(hash ?? ''))
			|| manifest.sources.production_capture_policy
				!== 'official_wordpress_rankmath_ui_read_only'
			|| manifest.sources.environment_capture_policy
				!== 'official_wordpress_rankmath_ui_read_only'
		) {
			fail('source hashes or UI capture policies are invalid');
		}
		const deferred = manifest.sources.deferred_content_keys ?? [];
		if (
			manifest.environment === 'production'
			&& (
				deferred.length !== 0
				|| manifest.sources.deferred_validation_artifact_sha256
					!== undefined
				|| manifest.sources.deferred_validation_plan_sha256 !== undefined
				|| manifest.sources.deferred_validation_score !== undefined
				|| manifest.sources.deferred_cleanup_result !== undefined
			)
		) {
			fail('production manifest may not contain deferred validation');
		}
		if (
			manifest.environment === 'docker'
			&& deferred.length > 0
			&& (
				deferred.length !== 1
				|| deferred[0]
					!== 'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile'
				|| !/^[a-f0-9]{64}$/u.test(
					manifest.sources.deferred_validation_artifact_sha256 ?? ''
				)
				|| !/^[a-f0-9]{64}$/u.test(
					manifest.sources.deferred_validation_plan_sha256 ?? ''
				)
				|| !Number.isFinite(
					manifest.sources.deferred_validation_score
				)
				|| manifest.sources.deferred_validation_score < 82
				|| manifest.sources.deferred_cleanup_result !== 'PASS'
			)
		) {
			fail('Docker deferred validation contract is invalid');
		}
		if (
			manifest.environment === 'docker'
			&& deferred.length === 0
			&& (
				manifest.sources.deferred_validation_artifact_sha256
					!== undefined
				|| manifest.sources.deferred_validation_plan_sha256 !== undefined
				|| manifest.sources.deferred_validation_score !== undefined
				|| manifest.sources.deferred_cleanup_result !== undefined
			)
		) {
			fail('Docker deferred evidence has no deferred content key');
		}
	}
	if (!Array.isArray(manifest.posts) || manifest.posts.length === 0) {
		fail('posts must be a non-empty array');
	}

	const postIds = new Set();
	const attachmentIds = new Set();
	for (const post of manifest.posts) {
		assertExactKeys(
			post,
			[
				'post_id',
				'post_type',
				'post_status',
				'edit_path',
				'public_url',
				'minimum_rank_math_score',
				'fields',
				'featured_image_alt',
			],
			'post'
		);
		if (
			!Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| postIds.has(post.post_id)
			|| post.post_type !== 'post'
			|| !['publish', 'draft'].includes(post.post_status ?? 'publish')
			|| !Number.isSafeInteger(post.minimum_rank_math_score)
			|| post.minimum_rank_math_score < 1
			|| post.minimum_rank_math_score > 100
			|| post.edit_path
				!== `/wp-admin/post.php?post=${post.post_id}&action=edit`
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${manifest.target_origin}/`)
		) {
			fail(`post identity or path is invalid: ${post.post_id}`);
		}
		postIds.add(post.post_id);
		assertExactKeys(post.fields, REQUIRED_FIELDS, `post ${post.post_id} fields`);
		for (const field of REQUIRED_FIELDS) {
			validateValueContract(
				post.fields[field],
				`post ${post.post_id} ${field}`
			);
		}
		validateSeoQuality(post.fields, post.post_id);
		if (
			post.fields.rank_math_focus_keyword.after
			!== post.fields.rank_math_focus_keyword.after
				.split(',')
				.map((keyword) => keyword.trim())
				.join(',')
		) {
			fail(
				`post ${post.post_id} focus keywords must use canonical commas`
			);
		}

		if (post.featured_image_alt !== undefined) {
			const media = post.featured_image_alt;
			assertExactKeys(
				media,
				[
					'attachment_id',
					'edit_path',
					'expected_filename',
					'before',
					'after',
					'before_sha256',
					'after_sha256',
				],
				`post ${post.post_id} featured_image_alt`
			);
			if (
				!Number.isSafeInteger(media.attachment_id)
				|| media.attachment_id < 1
				|| attachmentIds.has(media.attachment_id)
				|| media.edit_path
					!== `/wp-admin/post.php?post=${media.attachment_id}&action=edit`
				|| typeof media.expected_filename !== 'string'
				|| !/^[^/\\]+\.[A-Za-z0-9]{2,5}$/u.test(media.expected_filename)
			) {
				fail(`post ${post.post_id} featured image identity is invalid`);
			}
			attachmentIds.add(media.attachment_id);
			validateValueContract(
				media,
				`attachment ${media.attachment_id} alt`,
				['attachment_id', 'edit_path', 'expected_filename']
			);
			if (!normalizeEditorialValue(media.after).trim()) {
				fail(`attachment ${media.attachment_id} alt cannot become blank`);
			}
		}
	}

	if (
		!/^[a-f0-9]{64}$/u.test(manifest.items_sha256 ?? '')
		|| manifest.items_sha256 !== calculateItemsSha256(manifest)
	) {
		fail('items_sha256 mismatch');
	}
	if (
		!/^[a-f0-9]{64}$/u.test(manifest.manifest_sha256 ?? '')
		|| manifest.manifest_sha256 !== calculateManifestSha256(manifest)
	) {
		fail('manifest_sha256 mismatch');
	}
	return manifest;
}

export function finalizeManifest(draft) {
	const fieldsWithHashes = (fields) => Object.fromEntries(
		REQUIRED_FIELDS.map((field) => {
			const before = normalizeEditorialValue(fields[field].before);
			const after = normalizeEditorialValue(fields[field].after);
			return [field, {
				before,
				after,
				before_sha256: sha256(before),
				after_sha256: sha256(after),
			}];
		})
	);
	const manifest = {
		...draft,
		posts: draft.posts.map((post) => ({
			...post,
			fields: fieldsWithHashes(post.fields),
			...(post.featured_image_alt ? {
				featured_image_alt: {
					...post.featured_image_alt,
					before: normalizeEditorialValue(post.featured_image_alt.before),
					after: normalizeEditorialValue(post.featured_image_alt.after),
					before_sha256: sha256(normalizeEditorialValue(
						post.featured_image_alt.before
					)),
					after_sha256: sha256(normalizeEditorialValue(
						post.featured_image_alt.after
					)),
				},
			} : {}),
		})),
	};
	manifest.items_sha256 = calculateItemsSha256(manifest);
	manifest.manifest_sha256 = calculateManifestSha256(manifest);
	return validateManifest(manifest);
}

export { REQUIRED_FIELDS };
