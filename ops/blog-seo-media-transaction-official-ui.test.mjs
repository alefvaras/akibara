#!/usr/bin/env node

import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import test from 'node:test';

import {
	assertProductionTransactionApply,
	executeTransactionStateMachine,
	transactionForwardSteps,
	transactionRecoverySteps,
} from './blog-seo-media-transaction-official-ui.mjs';
import {
	finalizeBlogSeoMediaTransaction,
	validateBlogSeoMediaTransaction,
} from './lib/blog-seo-media-transaction.mjs';
import {
	finalizeManifest,
	sha256,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	finalizeInlineVisualManifest,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';

function digest(value) {
	return crypto.createHash('sha256').update(String(value)).digest('hex');
}

function fixtureManifests() {
	const origin = 'http://localhost:8080';
	const seoPosts = [];
	const inlinePosts = [];
	const assets = [];
	let assetNumber = 0;
	for (let index = 0; index < 25; index += 1) {
		const postId = 40001 + index;
		const contentKey = `entrada-editorial-${index + 1}`;
		const title = `Guía editorial ${index + 1}`;
		const content = [
			'<!-- wp:paragraph -->',
			`<p>Ancla editorial única ${index + 1} para lectores.</p>`,
			'<!-- /wp:paragraph -->',
		].join('\n');
		const publicUrl = `${origin}/${contentKey}/`;
		seoPosts.push({
			post_id: postId,
			post_type: 'post',
			edit_path: `/wp-admin/post.php?post=${postId}&action=edit`,
			public_url: publicUrl,
			minimum_rank_math_score: 82,
			fields: {
				post_title: {
					before: `Título anterior ${index + 1}`,
					after: title,
				},
				post_content: {
					before: `<!-- wp:paragraph --><p>Anterior ${index + 1}</p><!-- /wp:paragraph -->`,
					after: content,
				},
				post_excerpt: {
					before: `Extracto anterior ${index + 1}.`,
					after: `Resumen útil y honesto de la guía editorial ${index + 1}.`,
				},
				rank_math_focus_keyword: {
					before: `clave anterior ${index + 1}`,
					after: `guía editorial ${index + 1}`,
				},
				rank_math_title: {
					before: `Título SEO anterior ${index + 1}`,
					after: `Guía editorial ${index + 1} para lectores | Akibara`,
				},
				rank_math_description: {
					before:
						`Descripción anterior suficientemente extensa ${index + 1} para conservar un contrato editorial verificable y seguro.`,
					after:
						`Descubre la guía editorial ${index + 1} con información clara, recomendaciones útiles y contexto verificable para lectores de manga en Chile.`,
				},
			},
		});
		const assetSha256s = [];
		const assetCount = index < 14 ? 2 : index < 18 ? 1 : 0;
		for (let sequence = 1; sequence <= assetCount; sequence += 1) {
			assetNumber += 1;
			const hash = digest(`asset-${assetNumber}`);
			const filename = `${postId}-visual-${sequence}.webp`;
			assetSha256s.push(hash);
			assets.push({
				content_key: contentKey,
				post_id: postId,
				role: 'context',
				local_file:
					`output/blog-seo-inline-visuals-20260726/${filename}`,
				expected_filename: filename,
				sha256: hash,
				bytes: 2048 + assetNumber,
				width: 1200,
				height: 675,
				mime: 'image/webp',
				alt:
					`Descripción contextual completa del recurso ${assetNumber}`,
				caption:
					`Pie editorial verificable para el recurso visual ${assetNumber}`,
				marker_class: `akb-inline-visual--${hash.slice(0, 12)}`,
				sequence,
				anchor: {
					kind: 'gutenberg_block_text',
					value: `Ancla editorial única ${index + 1} para lectores.`,
					occurrence: 1,
					relation: 'after',
				},
			});
		}
		inlinePosts.push({
			content_key: contentKey,
			post_id: postId,
			edit_path: `/wp-admin/post.php?post=${postId}&action=edit`,
			public_url: publicUrl,
			post_title: title,
			before_content_sha256: sha256(content),
			asset_sha256s: assetSha256s,
		});
	}
	const seo = finalizeManifest({
		schema_version: 1,
		manifest_type: 'akibara_blog_seo_official_ui',
		environment: 'docker',
		target_origin: origin,
		application_policy: {
			official_wordpress_ui_only: true,
			official_rank_math_ui_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_meta_writes: 0,
			remote_artifacts: 0,
		},
		posts: seoPosts,
	});
	const inline = finalizeInlineVisualManifest({
		schema_version: 1,
		manifest_type:
			'akibara_blog_inline_visuals_official_ui',
		environment: 'docker',
		target_origin: origin,
		corpus: {
			post_count: 25,
			asset_count: 32,
			asset_post_count: 18,
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
			assets_manifest_sha256: digest('assets'),
			placements_sha256: digest('placements'),
			production_capture_sha256: digest('production-capture'),
			environment_capture_sha256: digest('environment-capture'),
			seo_manifest_sha256: seo.manifest_sha256,
			target_content_policy:
				'reviewed_seo_manifest_after_values',
		},
		posts: inlinePosts,
		assets,
	});
	return { seo, inline };
}

test('transaction is deterministic and binds all 25 content/media targets', () => {
	const { seo, inline } = fixtureManifests();
	const first = finalizeBlogSeoMediaTransaction({
		seoManifest: seo,
		inlineManifest: inline,
	});
	const second = finalizeBlogSeoMediaTransaction({
		seoManifest: structuredClone(seo),
		inlineManifest: structuredClone(inline),
	});
	assert.equal(first.transaction_sha256, second.transaction_sha256);
	assert.equal(first.posts.length, 25);
	assert.equal(
		first.posts.reduce(
			(total, post) => total + post.asset_sha256s.length,
			0
		),
		32
	);
	assert.equal(first.application_policy.historical_urls_immutable, true);
	assert.equal(first.application_policy.slug_writes, 0);
	assert.doesNotThrow(() => validateBlogSeoMediaTransaction(first, {
		seoManifest: seo,
		inlineManifest: inline,
	}));
});

test('transaction rejects target content, title and URL drift', () => {
	const { seo, inline } = fixtureManifests();
	const contentDrift = structuredClone(inline);
	contentDrift.posts[0].before_content_sha256 = digest('other content');
	delete contentDrift.manifest_sha256;
	assert.throws(
		() => finalizeBlogSeoMediaTransaction({
			seoManifest: seo,
			inlineManifest: finalizeInlineVisualManifest(contentDrift),
		}),
		/SEO\/inline target drift/u
	);

	const titleDrift = structuredClone(inline);
	titleDrift.posts[0].post_title = 'Otro título';
	delete titleDrift.manifest_sha256;
	assert.throws(
		() => finalizeBlogSeoMediaTransaction({
			seoManifest: seo,
			inlineManifest: finalizeInlineVisualManifest(titleDrift),
		}),
		/SEO\/inline target drift/u
	);

	const urlDrift = structuredClone(inline);
	urlDrift.posts[0].public_url += 'cambio/';
	delete urlDrift.manifest_sha256;
	assert.throws(
		() => finalizeBlogSeoMediaTransaction({
			seoManifest: seo,
			inlineManifest: finalizeInlineVisualManifest(urlDrift),
		}),
		/SEO\/inline target drift/u
	);
});

test('transaction rejects legacy or falsely bound inline manifests', () => {
	const { seo, inline } = fixtureManifests();
	const legacy = structuredClone(inline);
	delete legacy.manifest_sha256;
	delete legacy.sources.seo_manifest_sha256;
	delete legacy.sources.target_content_policy;
	assert.throws(
		() => finalizeBlogSeoMediaTransaction({
			seoManifest: seo,
			inlineManifest: finalizeInlineVisualManifest(legacy),
		}),
		/not bound to the reviewed SEO after values/u
	);

	const otherSeo = structuredClone(seo);
	delete otherSeo.items_sha256;
	delete otherSeo.manifest_sha256;
	otherSeo.posts[0].fields.post_excerpt.after += ' Otro.';
	const resignedOtherSeo = finalizeManifest(otherSeo);
	assert.throws(
		() => finalizeBlogSeoMediaTransaction({
			seoManifest: resignedOtherSeo,
			inlineManifest: inline,
		}),
		/not bound to the reviewed SEO after values/u
	);
});

test('transaction mutation is production-only', () => {
	assert.throws(
		() => assertProductionTransactionApply({ environment: 'docker' }),
		/production-only/u
	);
	assert.doesNotThrow(
		() => assertProductionTransactionApply({ environment: 'production' })
	);
});

test('score gate runs only after content and media', async () => {
	const calls = [];
	const phases = [];
	const result = await executeTransactionStateMachine({
		runStep: async (step) => calls.push(step),
		onPhase: async (phase) => phases.push(phase),
	});
	assert.equal(result.status, 'complete');
	assert.deepEqual(calls, transactionForwardSteps());
	assert.ok(
		calls.indexOf('verify_final_score') > calls.indexOf('apply_media')
	);
	assert.ok(calls.indexOf('apply_media') > calls.indexOf('stage_content'));
	assert.equal(phases.at(-1), 'complete');
});

test('a final-score failure rolls media back before content', async () => {
	const calls = [];
	await assert.rejects(
		() => executeTransactionStateMachine({
			runStep: async (step) => {
				calls.push(step);
				if (step === 'verify_final_score') {
					throw new Error('score 81');
				}
			},
		}),
		/score 81; rollback PASS/u
	);
	assert.deepEqual(calls.slice(-2), [
		'rollback_media',
		'rollback_content',
	]);
});

test('pre-write failures do not invoke rollback and stage failures do', async () => {
	const beforeWrites = [];
	await assert.rejects(
		() => executeTransactionStateMachine({
			runStep: async (step) => {
				beforeWrites.push(step);
				if (step === 'preflight_media_filenames') {
					throw new Error('filename collision');
				}
			},
		}),
		/filename collision; rollback PASS/u
	);
	assert.doesNotMatch(beforeWrites.join(','), /rollback/u);

	const duringStage = [];
	await assert.rejects(
		() => executeTransactionStateMachine({
			runStep: async (step) => {
				duringStage.push(step);
				if (step === 'stage_content') throw new Error('browser exit');
			},
		}),
		/browser exit; rollback PASS/u
	);
	assert.equal(duringStage.at(-1), 'rollback_content');
});

test('recovery plan is conservative and ordered', () => {
	assert.deepEqual(transactionRecoverySteps('validate_inline'), []);
	assert.deepEqual(transactionRecoverySteps('stage_content'), [
		'rollback_content',
	]);
	assert.deepEqual(transactionRecoverySteps('apply_media'), [
		'rollback_media',
		'rollback_content',
	]);
	assert.deepEqual(transactionRecoverySteps('verify_final_score'), [
		'rollback_media',
		'rollback_content',
	]);
});
