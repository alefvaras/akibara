import crypto from 'node:crypto';

export const RANKING_PUBLISH_CONTRACT = Object.freeze({
	postId: 27460,
	slug: 'mangas-mas-buscados-en-chile-2026',
	targetOrigin: 'https://akibara.cl',
	beforeStatus: 'draft',
	afterStatus: 'publish',
});

const APPLICATION_POLICY = Object.freeze({
	official_wordpress_ui_only: true,
	production_writes_only: true,
	post_status_transitions: 1,
	post_title_writes: 0,
	post_content_writes: 0,
	post_excerpt_writes: 0,
	rank_math_writes: 0,
	media_writes: 0,
	taxonomy_writes: 0,
	direct_rest_writes: 0,
	direct_sql_writes: 0,
	direct_wp_cli_writes: 0,
	remote_temporary_files: 0,
});

function fail(message) {
	throw new Error(`Ranking publish manifest rejected: ${message}`);
}

function canonical(value) {
	if (Array.isArray(value)) {
		return `[${value.map((item) => canonical(item)).join(',')}]`;
	}
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => (
			`${JSON.stringify(key)}:${canonical(value[key])}`
		)).join(',')}}`;
	}
	return JSON.stringify(value);
}

export function rankingPublishSha256(value) {
	return crypto.createHash('sha256')
		.update(Buffer.isBuffer(value) ? value : String(value))
		.digest('hex');
}

function withoutManifestHash(manifest) {
	const { manifest_sha256: ignored, ...payload } = manifest;
	return payload;
}

function assertExactKeys(value, keys, label) {
	if (
		!value
		|| typeof value !== 'object'
		|| Array.isArray(value)
		|| Object.keys(value).sort().join(',') !== [...keys].sort().join(',')
	) {
		fail(`${label} has unexpected keys`);
	}
}

function assertCapture(capture, label) {
	assertExactKeys(
		capture,
		['captured_at_gmt', 'file_sha256', 'payload_sha256'],
		label,
	);
	if (
		!/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.\d+)?(?:Z|\+00:00)$/u
			.test(capture.captured_at_gmt ?? '')
		|| !/^[a-f0-9]{64}$/u.test(capture.file_sha256 ?? '')
		|| !/^[a-f0-9]{64}$/u.test(capture.payload_sha256 ?? '')
	) {
		fail(`${label} is invalid`);
	}
}

function findUniquePost(posts, label) {
	if (!Array.isArray(posts)) fail(`${label}.posts must be an array`);
	const matches = posts.filter(
		(post) => post?.post_id === RANKING_PUBLISH_CONTRACT.postId,
	);
	if (matches.length !== 1) {
		fail(`${label} must contain post 27460 exactly once`);
	}
	return matches[0];
}

export function validateRankingPublishManifest(manifest) {
	assertExactKeys(
		manifest,
		[
			'application_policy',
			'environment',
			'manifest_sha256',
			'manifest_type',
			'post',
			'schema_version',
			'source_captures',
			'target_origin',
		],
		'manifest',
	);
	if (
		manifest.schema_version !== 1
		|| manifest.manifest_type
			!== 'akibara_ranking_publish_official_ui'
		|| manifest.environment !== 'production'
		|| manifest.target_origin !== RANKING_PUBLISH_CONTRACT.targetOrigin
		|| !/^[a-f0-9]{64}$/u.test(manifest.manifest_sha256 ?? '')
		|| rankingPublishSha256(canonical(withoutManifestHash(manifest)))
			!== manifest.manifest_sha256
	) {
		fail('schema, environment, origin or manifest hash is invalid');
	}
	if (canonical(manifest.application_policy) !== canonical(APPLICATION_POLICY)) {
		fail('application_policy differs from the no-content/no-media contract');
	}
	assertExactKeys(
		manifest.source_captures,
		['production_snapshot', 'production_structure'],
		'source_captures',
	);
	assertCapture(
		manifest.source_captures.production_snapshot,
		'source_captures.production_snapshot',
	);
	assertCapture(
		manifest.source_captures.production_structure,
		'source_captures.production_structure',
	);
	assertExactKeys(
		manifest.post,
		[
			'after_status',
			'before_status',
			'edit_path',
			'post_id',
			'public_url',
			'slug',
		],
		'post',
	);
	const expectedPost = {
		post_id: RANKING_PUBLISH_CONTRACT.postId,
		slug: RANKING_PUBLISH_CONTRACT.slug,
		edit_path: '/wp-admin/post.php?post=27460&action=edit',
		public_url:
			`${RANKING_PUBLISH_CONTRACT.targetOrigin}/`
			+ `${RANKING_PUBLISH_CONTRACT.slug}/`,
		before_status: RANKING_PUBLISH_CONTRACT.beforeStatus,
		after_status: RANKING_PUBLISH_CONTRACT.afterStatus,
	};
	if (canonical(manifest.post) !== canonical(expectedPost)) {
		fail('post transition differs from the reviewed 27460 contract');
	}
	return manifest;
}

export function finalizeRankingPublishManifest(draft) {
	const manifest = structuredClone(draft);
	manifest.manifest_sha256 = rankingPublishSha256(
		canonical(withoutManifestHash(manifest)),
	);
	return validateRankingPublishManifest(manifest);
}

export function buildRankingPublishManifest({
	productionSnapshot,
	productionSnapshotSha256,
	productionStructure,
	productionStructureSha256,
}) {
	if (
		productionSnapshot?.schema_version !== 1
		|| productionSnapshot.snapshot_type
			!== 'akibara_blog_production_read_only'
		|| productionSnapshot.environment !== 'production'
		|| productionSnapshot.target_origin
			!== RANKING_PUBLISH_CONTRACT.targetOrigin
		|| productionSnapshot.direct_writes !== 0
		|| !/^[a-f0-9]{64}$/u.test(productionSnapshot.payload_sha256 ?? '')
	) {
		fail('production snapshot is not the reviewed read-only capture type');
	}
	if (
		productionStructure?.schema_version !== 1
		|| productionStructure.capture_type
			!== 'akibara_production_blog_structure_read_only'
		|| productionStructure.environment !== 'production'
		|| productionStructure.direct_writes !== 0
		|| !/^[a-f0-9]{64}$/u.test(productionStructure.payload_sha256 ?? '')
	) {
		fail('production structure is not the reviewed read-only capture type');
	}
	if (
		!/^[a-f0-9]{64}$/u.test(productionSnapshotSha256 ?? '')
		|| !/^[a-f0-9]{64}$/u.test(productionStructureSha256 ?? '')
	) {
		fail('source file hashes are invalid');
	}
	const snapshotPost = findUniquePost(productionSnapshot.posts, 'snapshot');
	const structurePost = findUniquePost(
		productionStructure.posts,
		'structure',
	);
	if (
		snapshotPost.post_status !== RANKING_PUBLISH_CONTRACT.beforeStatus
		|| snapshotPost.post_slug !== RANKING_PUBLISH_CONTRACT.slug
		|| structurePost.post_status !== RANKING_PUBLISH_CONTRACT.beforeStatus
	) {
		fail('source captures do not agree on the reviewed draft identity');
	}
	return finalizeRankingPublishManifest({
		schema_version: 1,
		manifest_type: 'akibara_ranking_publish_official_ui',
		environment: 'production',
		target_origin: RANKING_PUBLISH_CONTRACT.targetOrigin,
		source_captures: {
			production_snapshot: {
				captured_at_gmt: productionSnapshot.captured_at_gmt,
				payload_sha256: productionSnapshot.payload_sha256,
				file_sha256: productionSnapshotSha256,
			},
			production_structure: {
				captured_at_gmt: productionStructure.captured_at_gmt,
				payload_sha256: productionStructure.payload_sha256,
				file_sha256: productionStructureSha256,
			},
		},
		application_policy: structuredClone(APPLICATION_POLICY),
		post: {
			post_id: RANKING_PUBLISH_CONTRACT.postId,
			slug: RANKING_PUBLISH_CONTRACT.slug,
			edit_path: '/wp-admin/post.php?post=27460&action=edit',
			public_url:
				`${RANKING_PUBLISH_CONTRACT.targetOrigin}/`
				+ `${RANKING_PUBLISH_CONTRACT.slug}/`,
			before_status: RANKING_PUBLISH_CONTRACT.beforeStatus,
			after_status: RANKING_PUBLISH_CONTRACT.afterStatus,
		},
	});
}

