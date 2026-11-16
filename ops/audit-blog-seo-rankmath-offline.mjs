#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	createRankMathOfflineAnalyzer,
} from './lib/rankmath-analyzer-offline.mjs';

const moduleDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(moduleDir, '..');
const reportDir = path.join(
	projectRoot,
	'.local-reports/seo-blog-score-audit'
);
const desiredPath = path.join(reportDir, 'all-posts-desired.json');
const outputPath = path.join(
	reportDir,
	'rankmath-1.0.274.1-offline-analysis.json'
);
const mediaGapPath = path.join(
	reportDir,
	'rankmath-1.0.274.1-media-gaps.md'
);
const targetScore = 82;
const maximumMediaSimulation = 12;

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function writeAtomic(file, value) {
	if (fs.existsSync(file)) {
		throw new Error(`Refusing to overwrite existing report: ${file}`);
	}
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, value, { flag: 'wx', mode: 0o600 });
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function failedScoredTests(analysis) {
	return Object.entries(analysis.tests)
		.filter(([, result]) => (
			result.max_score > 0 && result.earned_score < result.max_score
		))
		.map(([name, result]) => (
			`${name} ${result.earned_score}/${result.max_score}`
		));
}

const desiredBuffer = fs.readFileSync(desiredPath);
const desired = JSON.parse(desiredBuffer);
if (
	desired?.schema_version !== 1
	|| desired?.desired_type !== 'akibara_blog_seo_desired'
	|| !Array.isArray(desired?.posts)
	|| desired.posts.length !== 25
) {
	throw new Error('Expected the consolidated 25-post desired manifest');
}

const runtime = createRankMathOfflineAnalyzer({ projectRoot });
const postReports = [];
for (
	const post of [...desired.posts].sort(
		(left, right) => (
			left.production_post_id - right.production_post_id
		)
	)
) {
	const base = await runtime.analyzePost(post);
	let minimumAdditionalInlineImages = 0;
	let simulatedScore = base.predicted_score;
	if (base.predicted_score < targetScore) {
		minimumAdditionalInlineImages = null;
		for (let count = 1; count <= maximumMediaSimulation; count += 1) {
			const simulated = await runtime.analyzePost(post, {
				additionalInlineImageCount: count,
			});
			if (simulated.predicted_score >= targetScore) {
				minimumAdditionalInlineImages = count;
				simulatedScore = simulated.predicted_score;
				break;
			}
			simulatedScore = simulated.predicted_score;
		}
	}
	postReports.push({
		...base,
		meets_target_score: base.predicted_score >= targetScore,
		media_simulation: {
			changes_authored_manifest: false,
			maximum_images_tested: (
				base.predicted_score >= targetScore
					? 0
					: maximumMediaSimulation
			),
			minimum_additional_inline_images_for_target:
				minimumAdditionalInlineImages,
			predicted_score_after_simulation: simulatedScore,
			target_achievable_by_media_alone: (
				minimumAdditionalInlineImages !== null
			),
		},
	});
}

const belowTarget = postReports.filter((post) => !post.meets_target_score);
const report = {
	result: 'PASS',
	report_type: 'akibara_rank_math_offline_analysis',
	generated_at_gmt: new Date().toISOString(),
	target_score: targetScore,
	post_count: postReports.length,
	desired_manifest: {
		path: desiredPath,
		sha256: sha256(desiredBuffer),
	},
	authority: {
		offline_prediction_only: true,
		final_authority: 'Official Rank Math UI after Gutenberg save',
		keyword_uniqueness_deferred_to_ui: true,
		reason:
			'keywordNotUsed requires the WordPress database AJAX response.',
	},
	runtime: runtime.evidence,
	summary: {
		minimum_predicted_score: Math.min(
			...postReports.map((post) => post.predicted_score)
		),
		maximum_predicted_score: Math.max(
			...postReports.map((post) => post.predicted_score)
		),
		posts_at_or_above_target: (
			postReports.length - belowTarget.length
		),
		posts_below_target: belowTarget.length,
		post_ids_below_target: belowTarget.map(
			(post) => post.production_post_id
		),
		post_ids_reachable_by_media_alone: belowTarget
			.filter(
				(post) => (
					post.media_simulation
						.target_achievable_by_media_alone
				)
			)
			.map((post) => post.production_post_id),
		post_ids_not_reachable_by_media_alone: belowTarget
			.filter(
				(post) => (
					!post.media_simulation
						.target_achievable_by_media_alone
				)
			)
			.map((post) => post.production_post_id),
	},
	inline_media_contract: {
		integrated_by_this_audit: false,
		simulation_only: true,
		expected_separate_layer:
			'akibara_blog_seo_inline_media_overrides',
	},
	posts: postReports,
};
writeAtomic(outputPath, `${JSON.stringify(report, null, 2)}\n`);

const markdownRows = belowTarget.map((post) => {
	const count = (
		post.media_simulation.minimum_additional_inline_images_for_target
		?? 'no alcanza'
	);
	return (
		`| ${post.production_post_id} | ${post.predicted_score} | `
		+ `${count} | ${post.media_simulation.predicted_score_after_simulation} `
		+ `| ${failedScoredTests(post).join(', ')} |`
	);
});
const markdown = [
	'# Brechas de medios para Rank Math 1.0.274.1',
	'',
	`Objetivo offline: ${targetScore}/100. La interfaz oficial de Rank Math `
		+ 'después de guardar en Gutenberg conserva la autoridad final.',
	'',
	'Esta simulación no inserta medios ni modifica el manifiesto editorial. '
		+ 'Las imágenes inline pertenecen a una capa separada con guardas SHA.',
	'',
	'| Post | Puntaje actual | Imágenes inline mínimas simuladas | '
		+ 'Puntaje simulado | Pruebas aún parciales o fallidas |',
	'| ---: | ---: | ---: | ---: | --- |',
	...markdownRows,
	'',
	`Fuente: \`${path.relative(projectRoot, outputPath)}\`.`,
	'',
].join('\n');
writeAtomic(mediaGapPath, markdown);

process.stdout.write(`${JSON.stringify({
	result: 'PASS',
	posts: postReports.length,
	score_range: [
		report.summary.minimum_predicted_score,
		report.summary.maximum_predicted_score,
	],
	posts_below_target: report.summary.post_ids_below_target,
	reachable_by_media_alone:
		report.summary.post_ids_reachable_by_media_alone,
	not_reachable_by_media_alone:
		report.summary.post_ids_not_reachable_by_media_alone,
	report: outputPath,
	media_gaps: mediaGapPath,
}, null, 2)}\n`);
