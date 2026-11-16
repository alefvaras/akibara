import crypto from 'node:crypto';

import {
	normalizeEditorialValue,
	validateManifest as validateSeoManifest,
} from './blog-seo-official-ui-manifest.mjs';
import {
	validateInlineVisualManifest,
} from './blog-inline-visuals-official-ui-manifest.mjs';

const TRANSACTION_TYPE =
	'akibara_blog_seo_media_official_ui_transaction';
const TRANSACTION_POLICY = Object.freeze({
	official_wordpress_ui_only: true,
	official_rank_math_ui_only: true,
	official_media_ui_only: true,
	content_before_media: true,
	score_gate_after_media: true,
	global_rollback_on_failure: true,
	historical_urls_immutable: true,
	slug_writes: 0,
	direct_rest_writes: 0,
	direct_sql_writes: 0,
	direct_wp_cli_content_writes: 0,
	remote_artifacts: 0,
});

function fail(message) {
	throw new Error(`Blog SEO/media transaction rejected: ${message}`);
}

function canonicalJson(value) {
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

function sha256(value) {
	return crypto
		.createHash('sha256')
		.update(String(value), 'utf8')
		.digest('hex');
}

function exactKeys(value, expected, label) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		fail(`${label} must be an object`);
	}
	const actual = Object.keys(value).sort();
	const wanted = [...expected].sort();
	if (JSON.stringify(actual) !== JSON.stringify(wanted)) {
		fail(`${label} keys differ`);
	}
}

function transactionPayload(transaction) {
	const { transaction_sha256: ignored, ...payload } = transaction;
	return payload;
}

export function calculateBlogSeoMediaTransactionSha256(transaction) {
	return sha256(canonicalJson(transactionPayload(transaction)));
}

function validatePolicy(policy) {
	exactKeys(
		policy,
		Object.keys(TRANSACTION_POLICY),
		'application_policy'
	);
	for (const [key, expected] of Object.entries(TRANSACTION_POLICY)) {
		if (policy[key] !== expected) {
			fail(`application_policy.${key} is invalid`);
		}
	}
}

function expectedOrigin(environment) {
	return environment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
}

export function validateBlogSeoMediaTransaction(
	transaction,
	{ seoManifest = null, inlineManifest = null } = {}
) {
	exactKeys(
		transaction,
		[
			'schema_version',
			'manifest_type',
			'environment',
			'target_origin',
			'application_policy',
			'sources',
			'posts',
			'transaction_sha256',
		],
		'transaction'
	);
	if (
		transaction.schema_version !== 1
		|| transaction.manifest_type !== TRANSACTION_TYPE
		|| !['docker', 'production'].includes(transaction.environment)
		|| !expectedOrigin(transaction.environment)
			.test(transaction.target_origin ?? '')
	) {
		fail('schema, type, environment or target origin is invalid');
	}
	validatePolicy(transaction.application_policy);
	exactKeys(
		transaction.sources,
		['seo_manifest_sha256', 'inline_visual_manifest_sha256'],
		'sources'
	);
	for (const [key, value] of Object.entries(transaction.sources)) {
		if (!/^[a-f0-9]{64}$/u.test(value)) {
			fail(`invalid source hash ${key}`);
		}
	}
	if (
		!Array.isArray(transaction.posts)
		|| transaction.posts.length !== 25
	) {
		fail('transaction requires exactly 25 posts');
	}
	const ids = new Set();
	const keys = new Set();
	for (const post of transaction.posts) {
		exactKeys(
			post,
			[
				'content_key',
				'post_id',
				'public_url',
				'minimum_rank_math_score',
				'target_title',
				'target_content_sha256',
				'asset_sha256s',
			],
			`post ${post?.post_id ?? '(missing)'}`
		);
		if (
			typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
			|| !Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| ids.has(post.post_id)
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${transaction.target_origin}/`)
			|| !Number.isSafeInteger(post.minimum_rank_math_score)
			|| post.minimum_rank_math_score < 82
			|| post.minimum_rank_math_score > 100
			|| typeof post.target_title !== 'string'
			|| !post.target_title.trim()
			|| !/^[a-f0-9]{64}$/u.test(post.target_content_sha256 ?? '')
			|| !Array.isArray(post.asset_sha256s)
			|| post.asset_sha256s.some(
				(hash) => !/^[a-f0-9]{64}$/u.test(hash)
			)
			|| new Set(post.asset_sha256s).size !== post.asset_sha256s.length
		) {
			fail(`invalid post contract for ${post?.content_key}`);
		}
		ids.add(post.post_id);
		keys.add(post.content_key);
	}
	if (
		!/^[a-f0-9]{64}$/u.test(transaction.transaction_sha256 ?? '')
		|| calculateBlogSeoMediaTransactionSha256(transaction)
			!== transaction.transaction_sha256
	) {
		fail('transaction_sha256 mismatch');
	}

	if ((seoManifest === null) !== (inlineManifest === null)) {
		fail('both source manifests are required for cross-validation');
	}
	if (seoManifest && inlineManifest) {
		const expected = finalizeBlogSeoMediaTransaction({
			seoManifest,
			inlineManifest,
		});
		if (
			canonicalJson(expected) !== canonicalJson(transaction)
		) {
			fail('transaction differs from its source manifests');
		}
	}
	return transaction;
}

export function finalizeBlogSeoMediaTransaction({
	seoManifest,
	inlineManifest,
}) {
	const seo = validateSeoManifest(structuredClone(seoManifest));
	const inline = validateInlineVisualManifest(
		structuredClone(inlineManifest)
	);
	if (
		seo.environment !== inline.environment
		|| seo.target_origin !== inline.target_origin
	) {
		fail('source environments or origins differ');
	}
	if (
		inline.sources.seo_manifest_sha256 !== seo.manifest_sha256
		|| inline.sources.target_content_policy
			!== 'reviewed_seo_manifest_after_values'
	) {
		fail(
			'inline manifest is not bound to the reviewed SEO after values'
		);
	}
	if (
		seo.posts.length !== 25
		|| (seo.sources?.deferred_content_keys ?? []).length !== 0
	) {
		fail('SEO manifest must contain the complete non-deferred 25-post set');
	}
	const seoById = new Map(seo.posts.map((post) => [post.post_id, post]));
	const posts = inline.posts.map((inlinePost) => {
		const seoPost = seoById.get(inlinePost.post_id);
		if (!seoPost) {
			fail(`inline post ${inlinePost.post_id} is absent from SEO manifest`);
		}
		const targetContentSha256 =
			seoPost.fields.post_content.after_sha256;
		const targetTitle = normalizeEditorialValue(
			seoPost.fields.post_title.after
		);
		if (
			inlinePost.public_url !== seoPost.public_url
			|| inlinePost.before_content_sha256 !== targetContentSha256
			|| normalizeEditorialValue(inlinePost.post_title) !== targetTitle
		) {
			fail(
				`SEO/inline target drift for ${inlinePost.content_key}`
			);
		}
		return {
			content_key: inlinePost.content_key,
			post_id: inlinePost.post_id,
			public_url: inlinePost.public_url,
			minimum_rank_math_score: seoPost.minimum_rank_math_score,
			target_title: targetTitle,
			target_content_sha256: targetContentSha256,
			asset_sha256s: [...inlinePost.asset_sha256s],
		};
	}).sort((left, right) => (
		left.content_key.localeCompare(right.content_key, 'en')
	));
	if (seoById.size !== posts.length) {
		fail('SEO and inline post sets differ');
	}
	const transaction = {
		schema_version: 1,
		manifest_type: TRANSACTION_TYPE,
		environment: seo.environment,
		target_origin: seo.target_origin,
		application_policy: { ...TRANSACTION_POLICY },
		sources: {
			seo_manifest_sha256: seo.manifest_sha256,
			inline_visual_manifest_sha256: inline.manifest_sha256,
		},
		posts,
	};
	transaction.transaction_sha256 =
		calculateBlogSeoMediaTransactionSha256(transaction);
	return validateBlogSeoMediaTransaction(transaction);
}

export { TRANSACTION_POLICY, TRANSACTION_TYPE };
