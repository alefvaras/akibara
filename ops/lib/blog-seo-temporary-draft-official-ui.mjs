import crypto from 'node:crypto';

export const TEMPORARY_DRAFT_CONTRACT = Object.freeze({
	productionPostId: 24977,
	forbiddenProductionAttachmentId: 24990,
	slug: 'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile',
	targetOrigin: 'http://localhost:8080',
	expectedAuthorId: 1,
	expectedAuthorDisplayName: 'Alejandro Fernández',
	expectedImageFilename: 'preventa-manga-chile-akibara.webp',
	expectedImageSha256:
		'dc5315184ac62eec79cd0383cf9e533034ace4809c89886b2e1be9ac528af453',
	expectedImageWidth: 1280,
	expectedImageHeight: 720,
	expectedFeaturedImageAlt:
		'Preventa de manga en Chile con un tomo, un calendario y un paquete de entrega',
	minimumRankMathScore: 82,
});

function fail(message) {
	throw new Error(`Temporary blog draft plan rejected: ${message}`);
}

export function normalizeEditorialValue(value) {
	return String(value).replace(/\r\n?/gu, '\n');
}

export function sha256(value) {
	return crypto
		.createHash('sha256')
		.update(value)
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
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		fail(`${label} must be an object`);
	}
	const unexpected = Object.keys(value)
		.filter((key) => !allowed.includes(key));
	if (unexpected.length > 0) {
		fail(`${label} has unexpected keys: ${unexpected.join(', ')}`);
	}
	const missing = allowed.filter((key) => !(key in value));
	if (missing.length > 0) {
		fail(`${label} is missing keys: ${missing.join(', ')}`);
	}
}

function exactSinglePost(posts, postId, label) {
	if (!Array.isArray(posts)) {
		fail(`${label}.posts must be an array`);
	}
	const matches = posts.filter((post) => Number(post?.id) === postId);
	if (matches.length !== 1) {
		fail(`${label} must contain production post ${postId} exactly once`);
	}
	return matches[0];
}

function plainTextFromBlocks(content) {
	return normalizeEditorialValue(content)
		.replace(/<!--[\s\S]*?-->/gu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replace(/&(?:[a-z]+|#[0-9]+|#x[a-f0-9]+);/giu, ' ')
		.replace(/\s+/gu, ' ')
		.trim();
}

function countMatches(value, expression) {
	return [...String(value).matchAll(expression)].length;
}

function validateSource(source) {
	assertExactKeys(
		source,
		[
			'source',
			'site',
			'generated_at_gmt',
			'extraction',
			'query',
			'count',
			'posts',
		],
		'production source'
	);
	if (
		source.source !== 'production'
		|| source.site !== 'https://akibara.cl/'
		|| source.extraction?.remote_files_created !== false
		|| Number(source.count) !== source.posts.length
	) {
		fail('production source identity or read-only extraction policy is invalid');
	}
	const post = exactSinglePost(
		source.posts,
		TEMPORARY_DRAFT_CONTRACT.productionPostId,
		'production source'
	);
	assertExactKeys(
		post,
		[
			'id',
			'slug',
			'title',
			'content',
			'excerpt',
			'focus',
			'seo_title',
			'seo_description',
			'score',
			'thumbnail_id',
			'alt',
			'modified_gmt',
		],
		'production source post'
	);
	if (
		post.slug !== TEMPORARY_DRAFT_CONTRACT.slug
		|| Number(post.thumbnail_id)
			!== TEMPORARY_DRAFT_CONTRACT.forbiddenProductionAttachmentId
		|| !post.title
		|| !post.content
	) {
		fail('production post identity, thumbnail or source content is invalid');
	}
	for (const field of [
		'excerpt',
		'focus',
		'seo_title',
		'seo_description',
		'alt',
	]) {
		if (normalizeEditorialValue(post[field]).trim() !== '') {
			fail(`production source ${field} must match the reviewed blank state`);
		}
	}
	return post;
}

function validateDesired(desired) {
	assertExactKeys(
		desired,
		[
			'generated_at',
			'source_file',
			'target_score_floor',
			'application_policy',
			'posts',
		],
		'desired state'
	);
	if (
		Number(desired.target_score_floor)
			!== TEMPORARY_DRAFT_CONTRACT.minimumRankMathScore
		|| !/official WordPress and Rank Math UI/iu
			.test(String(desired.application_policy))
		|| !/Never write rank_math_seo_score/iu
			.test(String(desired.application_policy))
	) {
		fail('desired score floor or UI-only policy is invalid');
	}
	const post = exactSinglePost(
		desired.posts,
		TEMPORARY_DRAFT_CONTRACT.productionPostId,
		'desired state'
	);
	assertExactKeys(
		post,
		[
			'id',
			'slug',
			'post_title',
			'h1',
			'excerpt',
			'focus_keyword',
			'seo_title',
			'seo_description',
			'featured_image_alt',
			'featured_image_required_visual',
			'content',
		],
		'desired post'
	);
	const requiredStrings = [
		'slug',
		'post_title',
		'h1',
		'excerpt',
		'focus_keyword',
		'seo_title',
		'seo_description',
		'featured_image_alt',
		'featured_image_required_visual',
		'content',
	];
	for (const field of requiredStrings) {
		if (
			typeof post[field] !== 'string'
			|| normalizeEditorialValue(post[field]).trim() === ''
		) {
			fail(`desired ${field} must be a non-blank string`);
		}
	}
	if (
		post.slug !== TEMPORARY_DRAFT_CONTRACT.slug
		|| post.h1 !== post.post_title
		|| post.focus_keyword !== 'preventa de manga'
		|| post.featured_image_alt
			!== TEMPORARY_DRAFT_CONTRACT.expectedFeaturedImageAlt
	) {
		fail('desired identity, H1, keyword or image alt drifted');
	}
	if (
		post.seo_title.length > 60
		|| post.seo_description.length < 120
		|| post.seo_description.length > 160
		|| post.excerpt.length < 100
		|| post.excerpt.length > 160
	) {
		fail('desired SEO title, description or excerpt length is outside contract');
	}
	const content = normalizeEditorialValue(post.content);
	const plainText = plainTextFromBlocks(content);
	const wordCount = plainText.split(/\s+/u).filter(Boolean).length;
	if (
		content.length < 8000
		|| wordCount < 650
		|| countMatches(content, /<!--\s+wp:rank-math\/toc-block\b/gu) !== 1
		|| countMatches(content, /<!--\s+\/wp:rank-math\/toc-block\s+-->/gu) !== 1
		|| /<h1\b/iu.test(content)
		|| countMatches(
			plainText.toLocaleLowerCase('es-CL'),
			/preventa de manga/gu
		) < 4
	) {
		fail('desired content quality, native TOC or heading contract is invalid');
	}
	return post;
}

function validateImage(image) {
	assertExactKeys(
		image,
		[
			'relative_local_file',
			'expected_filename',
			'sha256',
			'bytes',
			'width',
			'height',
		],
		'image'
	);
	if (
		image.expected_filename
			!== TEMPORARY_DRAFT_CONTRACT.expectedImageFilename
		|| image.sha256 !== TEMPORARY_DRAFT_CONTRACT.expectedImageSha256
		|| !Number.isSafeInteger(image.bytes)
		|| image.bytes < 1024
		|| image.width !== TEMPORARY_DRAFT_CONTRACT.expectedImageWidth
		|| image.height !== TEMPORARY_DRAFT_CONTRACT.expectedImageHeight
		|| !String(image.relative_local_file)
			.endsWith(`/${TEMPORARY_DRAFT_CONTRACT.expectedImageFilename}`)
	) {
		fail('replacement image filename, hash, dimensions or size is invalid');
	}
}

export function temporaryDraftPlanPayload(plan) {
	const { plan_sha256: ignored, ...payload } = plan;
	return payload;
}

export function calculateTemporaryDraftPlanSha256(plan) {
	return sha256(canonicalJson(temporaryDraftPlanPayload(plan)));
}

export function validateTemporaryDraftPlan(plan) {
	assertExactKeys(
		plan,
		[
			'schema_version',
			'plan_type',
			'environment',
			'target_origin',
			'application_policy',
			'source',
			'author',
			'desired',
			'image',
			'plan_sha256',
		],
		'plan'
	);
	if (
		plan.schema_version !== 1
		|| plan.plan_type
			!== 'akibara_blog_seo_temporary_docker_draft_official_ui'
		|| plan.environment !== 'docker'
		|| plan.target_origin !== TEMPORARY_DRAFT_CONTRACT.targetOrigin
	) {
		fail('plan type, environment or exact Docker origin is invalid');
	}
	assertExactKeys(
		plan.application_policy,
		[
			'wordpress_and_rank_math_ui_only',
			'draft_only',
			'publish_requests',
			'direct_rest_writes',
			'direct_sql_writes',
			'direct_wp_cli_writes',
			'direct_meta_writes',
			'rank_math_score_writes',
			'production_writes',
			'cleanup_by_official_ui',
			'cleanup_required_even_after_failure',
			'remote_artifacts',
		],
		'application policy'
	);
	if (
		plan.application_policy.wordpress_and_rank_math_ui_only !== true
		|| plan.application_policy.draft_only !== true
		|| plan.application_policy.publish_requests !== 0
		|| plan.application_policy.direct_rest_writes !== 0
		|| plan.application_policy.direct_sql_writes !== 0
		|| plan.application_policy.direct_wp_cli_writes !== 0
		|| plan.application_policy.direct_meta_writes !== 0
		|| plan.application_policy.rank_math_score_writes !== 0
		|| plan.application_policy.production_writes !== 0
		|| plan.application_policy.cleanup_by_official_ui !== true
		|| plan.application_policy.cleanup_required_even_after_failure !== true
		|| plan.application_policy.remote_artifacts !== 0
	) {
		fail('application policy assertions failed');
	}
	assertExactKeys(
		plan.source,
		[
			'production_post_id',
			'forbidden_production_attachment_id',
			'production_source_sha256',
			'slug',
			'post_title',
			'post_content',
			'post_excerpt',
			'rank_math_focus_keyword',
			'rank_math_title',
			'rank_math_description',
			'featured_image_alt',
		],
		'source'
	);
	if (
		plan.source.production_post_id
			!== TEMPORARY_DRAFT_CONTRACT.productionPostId
		|| plan.source.forbidden_production_attachment_id
			!== TEMPORARY_DRAFT_CONTRACT.forbiddenProductionAttachmentId
		|| plan.source.slug !== TEMPORARY_DRAFT_CONTRACT.slug
		|| !/^[a-f0-9]{64}$/u.test(plan.source.production_source_sha256)
	) {
		fail('source identity or source file hash is invalid');
	}
	assertExactKeys(
		plan.author,
		['expected_user_id', 'expected_display_name'],
		'author'
	);
	if (
		plan.author.expected_user_id
			!== TEMPORARY_DRAFT_CONTRACT.expectedAuthorId
		|| plan.author.expected_display_name
			!== TEMPORARY_DRAFT_CONTRACT.expectedAuthorDisplayName
	) {
		fail('author contract is invalid');
	}
	assertExactKeys(
		plan.desired,
		[
			'desired_source_sha256',
			'slug',
			'post_title',
			'post_content',
			'post_excerpt',
			'rank_math_focus_keyword',
			'rank_math_title',
			'rank_math_description',
			'featured_image_alt',
			'minimum_rank_math_score',
		],
		'desired'
	);
	if (
		plan.desired.slug !== TEMPORARY_DRAFT_CONTRACT.slug
		|| plan.desired.minimum_rank_math_score
			!== TEMPORARY_DRAFT_CONTRACT.minimumRankMathScore
		|| !/^[a-f0-9]{64}$/u.test(plan.desired.desired_source_sha256)
	) {
		fail('desired identity, score or source file hash is invalid');
	}
	validateImage(plan.image);
	if (
		!Object.values(plan.source)
			.filter((value) => typeof value === 'string')
			.every((value) => !value.includes('\r'))
		|| !Object.values(plan.desired)
			.filter((value) => typeof value === 'string')
			.every((value) => !value.includes('\r'))
	) {
		fail('editorial fields must use normalized LF line endings');
	}
	const expectedHash = calculateTemporaryDraftPlanSha256(plan);
	if (
		!/^[a-f0-9]{64}$/u.test(plan.plan_sha256)
		|| plan.plan_sha256 !== expectedHash
	) {
		fail('plan hash mismatch');
	}
	return plan;
}

export function buildTemporaryDraftPlan({
	productionSource,
	productionSourceSha256,
	desiredState,
	desiredStateSha256,
	image,
}) {
	const sourcePost = validateSource(productionSource);
	const desiredPost = validateDesired(desiredState);
	if (
		![
			productionSourceSha256,
			desiredStateSha256,
		].every((hash) => /^[a-f0-9]{64}$/u.test(hash ?? ''))
	) {
		fail('input file hashes are invalid');
	}
	validateImage(image);
	const plan = {
		schema_version: 1,
		plan_type: 'akibara_blog_seo_temporary_docker_draft_official_ui',
		environment: 'docker',
		target_origin: TEMPORARY_DRAFT_CONTRACT.targetOrigin,
		application_policy: {
			wordpress_and_rank_math_ui_only: true,
			draft_only: true,
			publish_requests: 0,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_meta_writes: 0,
			rank_math_score_writes: 0,
			production_writes: 0,
			cleanup_by_official_ui: true,
			cleanup_required_even_after_failure: true,
			remote_artifacts: 0,
		},
		source: {
			production_post_id: sourcePost.id,
			forbidden_production_attachment_id: sourcePost.thumbnail_id,
			production_source_sha256: productionSourceSha256,
			slug: sourcePost.slug,
			post_title: normalizeEditorialValue(sourcePost.title),
			post_content: normalizeEditorialValue(sourcePost.content),
			post_excerpt: normalizeEditorialValue(sourcePost.excerpt),
			rank_math_focus_keyword: normalizeEditorialValue(sourcePost.focus),
			rank_math_title: normalizeEditorialValue(sourcePost.seo_title),
			rank_math_description: normalizeEditorialValue(
				sourcePost.seo_description
			),
			featured_image_alt: normalizeEditorialValue(sourcePost.alt),
		},
		author: {
			expected_user_id: TEMPORARY_DRAFT_CONTRACT.expectedAuthorId,
			expected_display_name:
				TEMPORARY_DRAFT_CONTRACT.expectedAuthorDisplayName,
		},
		desired: {
			desired_source_sha256: desiredStateSha256,
			slug: desiredPost.slug,
			post_title: normalizeEditorialValue(desiredPost.post_title),
			post_content: normalizeEditorialValue(desiredPost.content),
			post_excerpt: normalizeEditorialValue(desiredPost.excerpt),
			rank_math_focus_keyword: normalizeEditorialValue(
				desiredPost.focus_keyword
			),
			rank_math_title: normalizeEditorialValue(desiredPost.seo_title),
			rank_math_description: normalizeEditorialValue(
				desiredPost.seo_description
			),
			featured_image_alt: normalizeEditorialValue(
				desiredPost.featured_image_alt
			),
			minimum_rank_math_score:
				TEMPORARY_DRAFT_CONTRACT.minimumRankMathScore,
		},
		image: structuredClone(image),
		plan_sha256: '',
	};
	plan.plan_sha256 = calculateTemporaryDraftPlanSha256(plan);
	return validateTemporaryDraftPlan(plan);
}
