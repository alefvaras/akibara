#!/usr/bin/env node

import assert from 'node:assert/strict';
import test from 'node:test';

import {
	finalizeFeaturedImageManifest,
	validateFeaturedImageManifest,
} from './lib/blog-featured-image-official-ui-manifest.mjs';
import {
	featuredImageBrowserFunction,
} from './run-blog-featured-images-official-ui-playwright.mjs';

const replacements = [
	['golden-kamuy-hokkaido-akibara.webp', 'd75e5c0b23a973e472245a8040e8f444a365a893d5b6d415940adfc28abc26f9'],
	['preventas-manga-guia-akibara.webp', '95e4da849dcd2e9cff7f8ea8290259d56abb3c36d6b89ebcce943b6c2c2ccadd'],
	['panini-vs-ivrea-ediciones-akibara.webp', '499e66d74d1409615c200c94128e428eb32907c881c9707f4ae6b43f0d206be6'],
	['top-10-manga-seinen-akibara.webp', '8432977ce023f4a975de32f276bb020c132c98e1ea104c944357c269127bba55'],
	['preventa-manga-chile-akibara.webp', 'dc5315184ac62eec79cd0383cf9e533034ace4809c89886b2e1be9ac528af453'],
	['manga-romance-recomendados-akibara.webp', '310a497b2ed0c5c4f7449cf1a97abb4d4a3255d817204d9b9e1a79d705ff1265'],
	['tomos-hikaru-murio-guia-akibara.webp', 'ee339b1dc83f0632b0a915c03e97495605dc565ed46c83205e25f40a38681fcc'],
];

function draft() {
	return {
		schema_version: 1,
		manifest_type: 'akibara_blog_featured_image_official_ui',
		environment: 'docker',
		target_origin: 'http://localhost:8080',
		application_policy: {
			official_wordpress_media_ui_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			remote_temporary_files: 0,
		},
		posts: replacements.map(([filename, sha256], index) => ({
			post_id: 23000 + index,
			edit_path: `/wp-admin/post.php?post=${23000 + index}&action=edit`,
			public_url: `http://localhost:8080/entrada-${index}/`,
			current: {
				attachment_id: 33000 + index,
				filename: `anterior-${index}.jpg`,
			},
			replacement: {
				local_file: `output/imagegen/blog-seo-20260726/${filename}`,
				sha256,
				expected_filename: filename,
				width: 1280,
				height: 720,
				alt: `Descripción visual honesta ${index}`,
			},
		})),
	};
}

test('featured image manifest is deterministic and tamper-evident', () => {
	const first = finalizeFeaturedImageManifest(draft());
	const second = finalizeFeaturedImageManifest(draft());
	assert.equal(first.manifest_sha256, second.manifest_sha256);
	const tampered = structuredClone(first);
	tampered.posts[0].replacement.sha256 = '0'.repeat(64);
	assert.throws(
		() => validateFeaturedImageManifest(tampered),
		/manifest hash is invalid/u
	);
});

test('featured image browser uses only official Media and post UI', () => {
	const manifest = finalizeFeaturedImageManifest(draft());
	const source = featuredImageBrowserFunction({
		baseUrl: manifest.target_origin,
		post: {
			...manifest.posts[0],
			replacement: {
				...manifest.posts[0].replacement,
				absolute_local_file:
					'/local/akibara/output/imagegen/blog-seo-20260726/'
					+ manifest.posts[0].replacement.expected_filename,
			},
		},
		phase: 'apply',
	});
	assert.doesNotThrow(() => Function(`return (${source});`));
	assert.doesNotMatch(
		source,
		/fetch\s*\(|wp-json|admin-ajax|update_post_meta|update_option|\$wpdb/iu
	);
	assert.match(source, /setInputFiles/u);
	assert.match(source, /media-button-select/u);
	assert.match(source, /FEATURED_IMAGE_REOPEN_VERIFY_FAILED/u);
	assert.match(source, /Guardar\|Save\|Actualizar\|Update/u);
	assert.doesNotMatch(source, /Publicar\|Publish/u);
	assert.match(source, /UPLOADED_FILENAME_MISMATCH/u);
});
