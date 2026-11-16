import crypto from 'node:crypto';

export const BLOG_24977_ARCHIVE_CONTRACT = Object.freeze({
	sourcePostId: 24977,
	sourceSlug:
		'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile',
	destinationPostId: 23334,
	destinationSlug:
		'que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber',
	rankMathRedirectId: 588,
	targetOrigin: 'https://akibara.cl',
	postSitemapPath: '/post-sitemap.xml',
	beforeStatus: 'publish',
	afterStatus: 'draft',
});

const APPLICATION_POLICY = Object.freeze({
	official_wordpress_ui_only: true,
	production_writes_only: true,
	post_status_transitions: 1,
	post_title_writes: 0,
	post_content_writes: 0,
	post_excerpt_writes: 0,
	rank_math_post_meta_writes: 0,
	rank_math_redirect_writes: 0,
	media_writes: 0,
	taxonomy_writes: 0,
	direct_rest_writes: 0,
	direct_sql_writes: 0,
	direct_wp_cli_writes: 0,
	remote_temporary_files: 0,
});

function fail(message) {
	throw new Error(`Blog 24977 archive manifest rejected: ${message}`);
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

export function blog24977ArchiveSha256(value) {
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

function findUniquePost(posts, postId, label) {
	if (!Array.isArray(posts)) fail(`${label}.posts must be an array`);
	const matches = posts.filter((post) => post?.post_id === postId);
	if (matches.length !== 1) {
		fail(`${label} must contain post ${postId} exactly once`);
	}
	return matches[0];
}

function expectedPost() {
	const contract = BLOG_24977_ARCHIVE_CONTRACT;
	return {
		post_id: contract.sourcePostId,
		slug: contract.sourceSlug,
		edit_path: `/wp-admin/post.php?post=${contract.sourcePostId}&action=edit`,
		public_url: `${contract.targetOrigin}/${contract.sourceSlug}/`,
		before_status: contract.beforeStatus,
		after_status: contract.afterStatus,
	};
}

function expectedRedirect() {
	const contract = BLOG_24977_ARCHIVE_CONTRACT;
	return {
		provider: 'rank_math',
		rule_id: contract.rankMathRedirectId,
		active: true,
		http_status: 301,
		expected_x_redirect_by: 'Rank Math',
		source_url: `${contract.targetOrigin}/${contract.sourceSlug}/`,
		destination_post_id: contract.destinationPostId,
		destination_url:
			`${contract.targetOrigin}/${contract.destinationSlug}/`,
	};
}

function expectedVerification() {
	const contract = BLOG_24977_ARCHIVE_CONTRACT;
	const destinationUrl =
		`${contract.targetOrigin}/${contract.destinationSlug}/`;
	return {
		post_sitemap_url:
			`${contract.targetOrigin}${contract.postSitemapPath}`,
		source_must_be_present_before: true,
		source_must_be_absent_after: true,
		destination_must_be_present_before_and_after: true,
		destination_http_status: 200,
		destination_indexable: true,
		destination_canonical_url: destinationUrl,
	};
}

export function validateBlog24977ArchiveManifest(manifest) {
	assertExactKeys(
		manifest,
		[
			'application_policy',
			'environment',
			'manifest_sha256',
			'manifest_type',
			'post',
			'redirect',
			'schema_version',
			'source_captures',
			'target_origin',
			'verification',
		],
		'manifest',
	);
	if (
		manifest.schema_version !== 1
		|| manifest.manifest_type
			!== 'akibara_blog_24977_archive_official_ui'
		|| manifest.environment !== 'production'
		|| manifest.target_origin
			!== BLOG_24977_ARCHIVE_CONTRACT.targetOrigin
		|| !/^[a-f0-9]{64}$/u.test(manifest.manifest_sha256 ?? '')
		|| blog24977ArchiveSha256(canonical(withoutManifestHash(manifest)))
			!== manifest.manifest_sha256
	) {
		fail('schema, environment, origin or manifest hash is invalid');
	}
	if (canonical(manifest.application_policy) !== canonical(APPLICATION_POLICY)) {
		fail('application_policy differs from the status-only contract');
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
	assertExactKeys(
		manifest.redirect,
		[
			'active',
			'destination_post_id',
			'destination_url',
			'expected_x_redirect_by',
			'http_status',
			'provider',
			'rule_id',
			'source_url',
		],
		'redirect',
	);
	assertExactKeys(
		manifest.verification,
		[
			'destination_canonical_url',
			'destination_http_status',
			'destination_indexable',
			'destination_must_be_present_before_and_after',
			'post_sitemap_url',
			'source_must_be_absent_after',
			'source_must_be_present_before',
		],
		'verification',
	);
	if (canonical(manifest.post) !== canonical(expectedPost())) {
		fail('post transition differs from the reviewed 24977 contract');
	}
	if (canonical(manifest.redirect) !== canonical(expectedRedirect())) {
		fail('Rank Math redirect differs from the reviewed 588 contract');
	}
	if (
		canonical(manifest.verification)
		!== canonical(expectedVerification())
	) {
		fail('public verification differs from the reviewed consolidation');
	}
	return manifest;
}

export function finalizeBlog24977ArchiveManifest(draft) {
	const manifest = structuredClone(draft);
	manifest.manifest_sha256 = blog24977ArchiveSha256(
		canonical(withoutManifestHash(manifest)),
	);
	return validateBlog24977ArchiveManifest(manifest);
}

export function buildBlog24977ArchiveManifest({
	productionSnapshot,
	productionSnapshotSha256,
	productionStructure,
	productionStructureSha256,
}) {
	const contract = BLOG_24977_ARCHIVE_CONTRACT;
	if (
		productionSnapshot?.schema_version !== 1
		|| productionSnapshot.snapshot_type
			!== 'akibara_blog_production_read_only'
		|| productionSnapshot.environment !== 'production'
		|| productionSnapshot.target_origin !== contract.targetOrigin
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
	const snapshotSource = findUniquePost(
		productionSnapshot.posts,
		contract.sourcePostId,
		'snapshot',
	);
	const snapshotDestination = findUniquePost(
		productionSnapshot.posts,
		contract.destinationPostId,
		'snapshot',
	);
	const structureSource = findUniquePost(
		productionStructure.posts,
		contract.sourcePostId,
		'structure',
	);
	const structureDestination = findUniquePost(
		productionStructure.posts,
		contract.destinationPostId,
		'structure',
	);
	if (
		snapshotSource.post_status !== contract.beforeStatus
		|| snapshotSource.post_slug !== contract.sourceSlug
		|| structureSource.post_status !== contract.beforeStatus
		|| snapshotDestination.post_status !== 'publish'
		|| snapshotDestination.post_slug !== contract.destinationSlug
		|| structureDestination.post_status !== 'publish'
	) {
		fail('source captures do not agree on the reviewed consolidation');
	}
	return finalizeBlog24977ArchiveManifest({
		schema_version: 1,
		manifest_type: 'akibara_blog_24977_archive_official_ui',
		environment: 'production',
		target_origin: contract.targetOrigin,
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
		post: expectedPost(),
		redirect: expectedRedirect(),
		verification: expectedVerification(),
	});
}
