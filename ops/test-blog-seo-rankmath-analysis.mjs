#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import {
	cleanForSlug,
	rankMathMediaAudit,
	rankMathPermalinkAudit,
	strongTagAudit,
} from './lib/blog-seo-rankmath-analysis.mjs';

test('cleanForSlug mirrors the local WordPress URL normalization', () => {
	assert.equal(
		cleanForSlug('https://akibara.cl/Qué-es-el-manga/'),
		'https-akibara-cl-que-es-el-manga'
	);
});

test('Rank Math permalink requires the exact cleaned focus sequence', () => {
	const audit = rankMathPermalinkAudit({
		contentKey:
			'berserk-guia-para-leer-la-obra-definitiva-del-manga-seinen',
		focusKeyword: 'berserk manga',
	});
	assert.equal(audit.focus_contiguous_in_permalink, false);
	assert.equal(audit.production_url_length, 78);
	assert.equal(audit.production_url_length_pass, false);
});

test('featured image alt uses an exact lowercase substring', () => {
	const audit = rankMathMediaAudit({
		content: '',
		featuredImageAlt:
			'Golden Kamuy: la aventura histórica ambientada en Hokkaido',
		focusKeyword: 'Golden Kamuy la aventura',
	});
	assert.equal(audit.featured_alt_focus_exact_substring, false);
	assert.equal(audit.featured_image_count, 1);
	assert.equal(audit.rank_math_media_count, 1);
});

test('balanced strong markup passes and an extra closing tag regresses', () => {
	const corrected = (
		'<p><strong>Atelier of Witch Hat, la obra ilustrada por Shirahama'
		+ '</strong>, justifica la compra en formato físico.</p>'
	);
	const malformed = corrected.replace(
		'en formato físico.',
		'en formato físico</strong>.'
	);
	assert.equal(strongTagAudit(corrected).strong_tags_balanced, true);
	assert.deepEqual(strongTagAudit(malformed), {
		strong_opening_count: 1,
		strong_closing_count: 2,
		strong_unmatched_opening_count: 0,
		strong_unmatched_closing_count: 1,
		strong_tags_balanced: false,
	});
});

test('generated post 22353 keeps the corrected deterministic fragment', () => {
	const desired = JSON.parse(fs.readFileSync(new URL(
		'../.local-reports/seo-blog-score-audit/all-posts-desired.json',
		import.meta.url
	)));
	const post = desired.posts.find(
		(candidate) => candidate.production_post_id === 22353
	);
	const fragment = (
		'<strong>Atelier of Witch Hat, la obra ilustrada por Shirahama'
		+ '</strong>, justifica la compra en formato físico.'
	);
	assert.equal(post.fields.post_content.split(fragment).length - 1, 1);
	assert.equal(
		post.fields.post_content.includes('formato físico</strong>.'),
		false
	);
	assert.equal(
		strongTagAudit(post.fields.post_content).strong_tags_balanced,
		true
	);
});
