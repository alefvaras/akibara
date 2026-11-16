#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	finalizeManifest,
	REQUIRED_FIELDS,
	sha256,
} from './lib/blog-seo-official-ui-manifest.mjs';

const DEFERRED_DOCKER_CONTENT_KEY =
	'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';

function fail(message) {
	throw new Error(`Blog SEO manifest generation failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function flag(name) {
	return process.argv.includes(name);
}

function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function fileSha256(file) {
	return sha256(fs.readFileSync(file));
}

function assertLocalOutput(file) {
	const output = path.resolve(file);
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const allowed = ['.local-reports', '.local-checkpoints'].some((name) => {
		const relative = path.relative(path.join(repository, name), output);
		return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
	});
	if (!allowed) fail('output must remain under .local-reports or .local-checkpoints');
	if (fs.existsSync(output)) fail('refusing to overwrite an existing manifest');
	return output;
}

function validateDesired(
	desired,
	{ requireFeaturedImageAlt = true } = {}
) {
	const inventoryPostCount = desired?.inventory_post_count ?? 25;
	if (
		desired?.schema_version !== 1
		|| desired?.desired_type !== 'akibara_blog_seo_desired'
		|| !Number.isSafeInteger(inventoryPostCount)
		|| inventoryPostCount < 1
		|| inventoryPostCount > 500
		|| !Array.isArray(desired.posts)
		|| desired.posts.length !== inventoryPostCount
	) {
		fail(
			'desired must contain the complete reviewed inventory declared '
			+ 'by inventory_post_count'
		);
	}
	const keys = new Set();
	for (const post of desired.posts) {
		if (
			typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
			|| !Number.isSafeInteger(post.minimum_rank_math_score)
			|| post.minimum_rank_math_score < 1
			|| post.minimum_rank_math_score > 100
			|| !post.fields
			|| REQUIRED_FIELDS.some(
				(field) => typeof post.fields[field] !== 'string'
			)
			|| (
				requireFeaturedImageAlt
				&& (
					typeof post.featured_image_alt !== 'string'
					|| !post.featured_image_alt.trim()
				)
			)
		) {
			fail(`invalid desired post ${post?.content_key ?? '(missing key)'}`);
		}
		keys.add(post.content_key);
	}
	return { keys, inventoryPostCount };
}

function validateSource(
	source,
	expectedEnvironment,
	label,
	{ requireFeaturedImage = true } = {}
) {
	const inventoryPostCount = source?.inventory_post_count ?? 25;
	const captureScope = source?.capture_scope
		?? 'content_seo_and_featured_image_alt';
	const expectedOrigin = expectedEnvironment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
	if (
		source?.schema_version !== 1
		|| source?.capture_type !== 'akibara_blog_seo_official_ui_read_only'
		|| source?.capture_tool !== 'akibara-playwright-official-ui-v1'
		|| source?.environment !== expectedEnvironment
		|| !expectedOrigin.test(source?.target_origin ?? '')
		|| source?.capture_policy !== 'official_wordpress_rankmath_ui_read_only'
		|| ![
			'content_and_seo',
			'content_seo_and_featured_image_alt',
		].includes(captureScope)
		|| (
			requireFeaturedImage
			&& captureScope !== 'content_seo_and_featured_image_alt'
		)
		|| source?.direct_writes !== 0
		|| !Number.isSafeInteger(inventoryPostCount)
		|| inventoryPostCount < 1
		|| inventoryPostCount > 500
		|| !/^[a-f0-9]{64}$/u.test(source?.review_sha256 ?? '')
		|| !Number.isFinite(Date.parse(source?.captured_at ?? ''))
		|| !Array.isArray(source.posts)
	) {
		fail(
			`${label} is not a capture produced by the official-UI Playwright tool`
		);
	}
	const byKey = new Map();
	for (const post of source.posts) {
		if (
			typeof post.content_key !== 'string'
			|| byKey.has(post.content_key)
			|| !Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| !['publish', 'draft'].includes(post.post_status ?? 'publish')
			|| typeof post.public_url !== 'string'
			|| !post.public_url.startsWith(`${source.target_origin}/`)
			|| !post.fields
			|| REQUIRED_FIELDS.some(
				(field) => typeof post.fields[field] !== 'string'
			)
			|| (
				requireFeaturedImage
				&& (
					!Number.isSafeInteger(post.featured_image?.attachment_id)
					|| post.featured_image.attachment_id < 1
					|| typeof post.featured_image.filename !== 'string'
					|| !/^[^/\\]+$/u.test(post.featured_image.filename)
					|| typeof post.featured_image.alt !== 'string'
				)
			)
		) {
			fail(`${label} has an invalid source row`);
		}
		byKey.set(post.content_key, post);
	}
	const hasDeferredDeclaration = Object.prototype.hasOwnProperty.call(
		source,
		'deferred_content_keys'
	);
	const hasArtifactDeclaration = Object.prototype.hasOwnProperty.call(
		source,
		'validation_artifact'
	);
	if (
		hasDeferredDeclaration
		&& !Array.isArray(source.deferred_content_keys)
	) {
		fail(`${label} deferred_content_keys must be an array`);
	}
	const deferredContentKeys = source.deferred_content_keys ?? [];
	const artifact = source.validation_artifact;
	if (
		expectedEnvironment === 'production'
		&& (hasDeferredDeclaration || hasArtifactDeclaration)
	) {
		fail('production source may not contain deferred validation');
	}
	if (
		expectedEnvironment === 'docker'
		&& deferredContentKeys.length > 0
		&& (
			deferredContentKeys.length !== 1
			|| deferredContentKeys[0] !== DEFERRED_DOCKER_CONTENT_KEY
			|| artifact?.content_key !== DEFERRED_DOCKER_CONTENT_KEY
			|| !/^[a-f0-9]{64}$/u.test(artifact?.artifact_sha256 ?? '')
			|| artifact?.report_type
				!== 'akibara_blog_seo_temporary_docker_draft_official_ui_evidence'
			|| !/^[a-f0-9]{64}$/u.test(artifact?.plan_sha256 ?? '')
			|| !Number.isFinite(artifact?.stable_rank_math_score)
			|| artifact.stable_rank_math_score < 82
			|| artifact?.cleanup_result !== 'PASS'
		)
	) {
		fail('Docker deferred validation is incomplete or unreviewed');
	}
	if (
		expectedEnvironment === 'docker'
		&& deferredContentKeys.length === 0
		&& (hasDeferredDeclaration || hasArtifactDeclaration)
	) {
		fail('Docker deferred declaration must contain its single reviewed key');
	}
	if (
		byKey.size !== inventoryPostCount - deferredContentKeys.length
		|| (
			deferredContentKeys.length === 1
			&& byKey.has(DEFERRED_DOCKER_CONTENT_KEY)
		)
	) {
		fail(`${label} post count does not match its deferred contract`);
	}
	return {
		byKey,
		deferredContentKeys,
		artifact,
		inventoryPostCount,
	};
}

function writeAtomic(file, manifest) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(manifest, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function main() {
	const desiredValue = option('--desired');
	const productionSourceValue = option('--production-source');
	const environmentSourceValue = option('--environment-source');
	if (!desiredValue || !productionSourceValue || !environmentSourceValue) {
		fail('missing --desired, --production-source or --environment-source');
	}
	const desiredPath = path.resolve(desiredValue);
	const productionSourcePath = path.resolve(productionSourceValue);
	const environmentSourcePath = path.resolve(environmentSourceValue);
	const environment = option('--environment');
	const skipFeaturedImageAlt = flag('--skip-featured-image-alt');
	const outputValue = option('--output');
	if (!outputValue) fail('missing --output');
	const output = assertLocalOutput(outputValue);
	if (!['docker', 'production'].includes(environment)) {
		fail('--environment must be docker or production');
	}
	for (const file of [
		desiredPath,
		productionSourcePath,
		environmentSourcePath,
	]) {
		if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
			fail(`missing input file ${file}`);
		}
	}
	const desired = readJson(desiredPath);
	const productionSource = readJson(productionSourcePath);
	const environmentSource = readJson(environmentSourcePath);
	const desiredContract = validateDesired(desired, {
		requireFeaturedImageAlt: !skipFeaturedImageAlt,
	});
	const desiredKeys = desiredContract.keys;
	const productionCapture = validateSource(
		productionSource,
		'production',
		'production source',
		{ requireFeaturedImage: !skipFeaturedImageAlt }
	);
	const environmentCapture = validateSource(
		environmentSource,
		environment,
		`${environment} source`,
		{ requireFeaturedImage: !skipFeaturedImageAlt }
	);
	if (
		productionCapture.inventoryPostCount
			!== desiredContract.inventoryPostCount
		|| environmentCapture.inventoryPostCount
			!== desiredContract.inventoryPostCount
	) {
		fail('desired and source inventory_post_count values do not match');
	}
	const productionByKey = productionCapture.byKey;
	const environmentByKey = environmentCapture.byKey;
	const missingProduction = [...desiredKeys]
		.filter((key) => !productionByKey.has(key));
	const missingEnvironment = [...desiredKeys]
		.filter((key) => !environmentByKey.has(key));
	const unexpectedEnvironment = [...environmentByKey.keys()]
		.filter((key) => !desiredKeys.has(key));
	if (missingProduction.length > 0) {
		fail(`production source posts missing: ${missingProduction.join(', ')}`);
	}
	const allowedDeferredMissing = environment === 'docker'
		&& missingEnvironment.length === 1
		&& missingEnvironment[0] === DEFERRED_DOCKER_CONTENT_KEY
		&& environmentCapture.deferredContentKeys.length === 1
		&& environmentCapture.artifact;
	if (missingEnvironment.length > 0 && !allowedDeferredMissing) {
		fail(
			`${environment} source posts missing: ${missingEnvironment.join(', ')}; `
			+ 'restore clone parity through a separate reviewed UI-first workflow '
			+ 'and capture again'
		);
	}
	if (unexpectedEnvironment.length > 0) {
		fail(
			`${environment} source contains unreviewed posts: ${
				unexpectedEnvironment.join(', ')
			}`
		);
	}
	for (const contentKey of desiredKeys) {
		const productionPost = productionByKey.get(contentKey);
		const environmentPost = environmentByKey.get(contentKey);
		if (!environmentPost) continue;
		if (
			(productionPost.post_status ?? 'publish')
				!== (environmentPost.post_status ?? 'publish')
		) {
			fail(`${environment} source status drift for ${contentKey}`);
		}
	}
	const targetOrigin = environmentSource.target_origin;
	const draft = {
		schema_version: 1,
		manifest_type: 'akibara_blog_seo_official_ui',
		environment,
		target_origin: targetOrigin,
		application_policy: {
			official_wordpress_ui_only: true,
			official_rank_math_ui_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_meta_writes: 0,
			remote_artifacts: 0,
		},
		sources: {
			desired_sha256: fileSha256(desiredPath),
			production_source_sha256: fileSha256(productionSourcePath),
			environment_source_sha256: fileSha256(environmentSourcePath),
			production_capture_policy:
				'official_wordpress_rankmath_ui_read_only',
			environment_capture_policy:
				'official_wordpress_rankmath_ui_read_only',
			...(allowedDeferredMissing ? {
				deferred_content_keys: [DEFERRED_DOCKER_CONTENT_KEY],
				deferred_validation_artifact_sha256:
					environmentCapture.artifact.artifact_sha256,
				deferred_validation_plan_sha256:
					environmentCapture.artifact.plan_sha256,
				deferred_validation_score:
					environmentCapture.artifact.stable_rank_math_score,
				deferred_cleanup_result:
					environmentCapture.artifact.cleanup_result,
			} : {}),
		},
		posts: desired.posts.filter(
			(wanted) => environmentByKey.has(wanted.content_key)
		).map((wanted) => {
			const source = environmentByKey.get(wanted.content_key);
			return {
				post_id: source.post_id,
				post_type: 'post',
				post_status: source.post_status ?? 'publish',
				edit_path: `/wp-admin/post.php?post=${source.post_id}&action=edit`,
				public_url: source.public_url,
				minimum_rank_math_score: wanted.minimum_rank_math_score,
				fields: Object.fromEntries(REQUIRED_FIELDS.map((field) => [
					field,
					{
						before: source.fields[field],
						after: wanted.fields[field],
					},
				])),
				...(!skipFeaturedImageAlt ? { featured_image_alt: {
					attachment_id: source.featured_image.attachment_id,
					edit_path: `/wp-admin/post.php?post=${
						source.featured_image.attachment_id
					}&action=edit`,
					expected_filename: source.featured_image.filename,
					before: source.featured_image.alt,
					after: wanted.featured_image_alt,
				} } : {}),
			};
		}),
	};
	const manifest = finalizeManifest(draft);
	writeAtomic(output, manifest);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		posts: manifest.posts.length,
		items: manifest.posts.length * (skipFeaturedImageAlt ? 6 : 7),
		featured_image_alt_mutations: skipFeaturedImageAlt ? 0 : manifest.posts.length,
		manifest_sha256: manifest.manifest_sha256,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
