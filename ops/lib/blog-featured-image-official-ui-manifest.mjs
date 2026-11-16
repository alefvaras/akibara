import crypto from 'node:crypto';

function fail(message) {
	throw new Error(`Featured image official UI manifest rejected: ${message}`);
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

function hash(value) {
	return crypto.createHash('sha256')
		.update(Buffer.isBuffer(value) ? value : String(value))
		.digest('hex');
}

function payload(manifest) {
	const { manifest_sha256: ignored, ...value } = manifest;
	return value;
}

export function finalizeFeaturedImageManifest(draft) {
	const manifest = structuredClone(draft);
	manifest.manifest_sha256 = hash(canonical(payload(manifest)));
	return validateFeaturedImageManifest(manifest);
}

export function validateFeaturedImageManifest(manifest) {
	const allowed = [
		'schema_version',
		'manifest_type',
		'environment',
		'target_origin',
		'application_policy',
		'posts',
		'manifest_sha256',
	];
	const unexpected = Object.keys(manifest ?? {})
		.filter((key) => !allowed.includes(key));
	if (unexpected.length > 0) fail(`unexpected keys: ${unexpected.join(', ')}`);
	if (
		manifest?.schema_version !== 1
		|| manifest?.manifest_type
			!== 'akibara_blog_featured_image_official_ui'
		|| !['docker', 'production'].includes(manifest?.environment)
		|| !/^[a-f0-9]{64}$/u.test(manifest?.manifest_sha256 ?? '')
		|| hash(canonical(payload(manifest))) !== manifest.manifest_sha256
	) {
		fail('schema, environment or manifest hash is invalid');
	}
	const expectedOrigin = manifest.environment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
	if (!expectedOrigin.test(manifest.target_origin ?? '')) {
		fail('target origin does not match environment');
	}
	const policy = manifest.application_policy;
	if (
		policy?.official_wordpress_media_ui_only !== true
		|| policy.direct_rest_writes !== 0
		|| policy.direct_sql_writes !== 0
		|| policy.direct_wp_cli_writes !== 0
		|| policy.remote_temporary_files !== 0
	) {
		fail('official Media UI policy is incomplete');
	}
	if (!Array.isArray(manifest.posts) || manifest.posts.length !== 7) {
		fail('manifest must contain exactly seven reviewed replacements');
	}
	const postIds = new Set();
	const localFiles = new Set();
	for (const post of manifest.posts) {
		if (
			!Number.isSafeInteger(post?.post_id)
			|| post.post_id < 1
			|| postIds.has(post.post_id)
			|| post.edit_path
				!== `/wp-admin/post.php?post=${post.post_id}&action=edit`
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${manifest.target_origin}/`)
			|| !Number.isSafeInteger(post.current?.attachment_id)
			|| post.current.attachment_id < 1
			|| typeof post.current.filename !== 'string'
			|| !/^[^/\\]+$/u.test(post.current.filename)
			|| typeof post.replacement?.local_file !== 'string'
			|| !/^output\/imagegen\/blog-seo-20260726\/[^/\\]+\.webp$/u
				.test(post.replacement.local_file)
			|| localFiles.has(post.replacement.local_file)
			|| !/^[a-f0-9]{64}$/u.test(post.replacement.sha256 ?? '')
			|| typeof post.replacement.expected_filename !== 'string'
			|| post.replacement.expected_filename
				!== post.replacement.local_file.split('/').at(-1)
			|| post.replacement.width !== 1280
			|| post.replacement.height !== 720
			|| typeof post.replacement.alt !== 'string'
			|| !post.replacement.alt.trim()
		) {
			fail(`invalid replacement contract for post ${post?.post_id}`);
		}
		postIds.add(post.post_id);
		localFiles.add(post.replacement.local_file);
	}
	return manifest;
}

export { hash as featuredImageSha256 };
