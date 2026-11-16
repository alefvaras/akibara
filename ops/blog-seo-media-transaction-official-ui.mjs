#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	finalizeBlogSeoMediaTransaction,
	validateBlogSeoMediaTransaction,
} from './lib/blog-seo-media-transaction.mjs';
import {
	validateManifest as validateSeoManifest,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	validateInlineVisualManifest,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const SEO_RUNNER = path.join(
	HERE,
	'run-blog-seo-official-ui-playwright.mjs'
);
const INLINE_RUNNER = path.join(
	HERE,
	'run-blog-inline-visuals-official-ui-playwright.mjs'
);

function fail(message) {
	throw new Error(`Blog SEO/media coordinator failed: ${message}`);
}

function option(name) {
	const indexes = process.argv
		.map((value, index) => value === name ? index : -1)
		.filter((index) => index !== -1);
	if (indexes.length > 1) fail(`duplicate option ${name}`);
	if (indexes.length === 0) return undefined;
	const value = process.argv[indexes[0] + 1];
	if (!value || value.startsWith('--')) fail(`${name} requires a value`);
	return value;
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function assertNoSymlinks(file, root, label) {
	let current = file;
	while (current !== root && current !== path.dirname(current)) {
		if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) {
			fail(`${label} may not traverse symlinks`);
		}
		current = path.dirname(current);
	}
}

function localPath(
	value,
	roots,
	label,
	{ mustExist = false, mode0600 = false } = {}
) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const root = roots.find((candidate) => inside(file, candidate));
	if (!root) fail(`${label} must remain in an approved local directory`);
	assertNoSymlinks(file, root, label);
	if (mustExist) {
		if (
			!fs.existsSync(file)
			|| !fs.lstatSync(file).isFile()
			|| fs.lstatSync(file).isSymbolicLink()
		) {
			fail(`${label} must be a regular local file`);
		}
		if (mode0600 && (fs.lstatSync(file).mode & 0o777) !== 0o600) {
			fail(`${label} must have mode 0600`);
		}
	}
	return file;
}

function writeAtomic0600(file, value, { replace = false } = {}) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	if (!replace && fs.existsSync(file)) fail(`refusing to overwrite ${file}`);
	const temporary = `${file}.tmp-${process.pid}-${Date.now()}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function readJson(file, validator, label) {
	let value;
	try {
		value = JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	try {
		return validator(value);
	} catch (error) {
		fail(`${label} failed validation: ${error.message}`);
	}
}

function reportSidecar(report, suffix) {
	const extension = path.extname(report);
	const stem = extension ? report.slice(0, -extension.length) : report;
	return `${stem}.${suffix}.json`;
}

function runNode(step, script, args) {
	const result = spawnSync(process.execPath, [script, ...args], {
		cwd: ROOT,
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
		timeout: 900_000,
	});
	if (result.error || result.status !== 0) {
		const detail = String(
			result.stderr || result.stdout || result.error?.message || ''
		).trim().slice(0, 2000);
		throw new Error(`${step} failed: ${detail}`);
	}
	let output = null;
	try {
		output = JSON.parse(result.stdout);
	} catch {
		throw new Error(`${step} returned non-JSON output`);
	}
	if (output.result !== 'PASS') {
		throw new Error(`${step} did not return PASS`);
	}
	return output;
}

export function transactionForwardSteps() {
	return [
		'validate_seo',
		'validate_inline',
		'preflight_seo',
		'preflight_media_filenames',
		'stage_content',
		'apply_media',
		'verify_final_score',
	];
}

export function transactionRecoverySteps(phase) {
	const contentMayHaveChanged = [
		'stage_content',
		'apply_media',
		'verify_final_score',
		'rolling_back',
	].includes(phase);
	const mediaMayHaveChanged = [
		'apply_media',
		'verify_final_score',
		'rolling_back',
	].includes(phase);
	return [
		...(mediaMayHaveChanged ? ['rollback_media'] : []),
		...(contentMayHaveChanged ? ['rollback_content'] : []),
	];
}

export function assertProductionTransactionApply(transaction) {
	if (transaction?.environment !== 'production') {
		fail(
			'transaction application is production-only; '
			+ 'Docker local permits validation and read-only preflight only'
		);
	}
	return transaction;
}

export async function executeTransactionStateMachine({
	runStep,
	onPhase = async () => {},
}) {
	let contentMayHaveChanged = false;
	let mediaMayHaveChanged = false;
	try {
		for (const step of transactionForwardSteps()) {
			if (step === 'stage_content') contentMayHaveChanged = true;
			if (step === 'apply_media') mediaMayHaveChanged = true;
			await onPhase(step);
			await runStep(step);
		}
		await onPhase('complete');
		return { status: 'complete' };
	} catch (primaryError) {
		await onPhase('rolling_back');
		const rollbackErrors = [];
		const rollbackSteps = [
			...(mediaMayHaveChanged ? ['rollback_media'] : []),
			...(contentMayHaveChanged ? ['rollback_content'] : []),
		];
		for (const step of rollbackSteps) {
			try {
				await runStep(step);
			} catch (error) {
				rollbackErrors.push(`${step}:${error.message}`);
			}
		}
		await onPhase(
			rollbackErrors.length === 0 ? 'rolled_back' : 'rollback_failed'
		);
		const suffix = rollbackErrors.length === 0
			? 'rollback PASS'
			: `rollback FAILED (${rollbackErrors.join(' | ')})`;
		throw new Error(`${primaryError.message}; ${suffix}`);
	}
}

function usage() {
	process.stdout.write(`Usage:
  node ops/blog-seo-media-transaction-official-ui.mjs \\
    --build --seo-manifest FILE --inline-manifest FILE --output FILE

  node ops/blog-seo-media-transaction-official-ui.mjs \\
    --validate-only --transaction FILE \\
    --seo-manifest FILE --inline-manifest FILE

  node ops/blog-seo-media-transaction-official-ui.mjs \\
    --apply --transaction FILE --seo-manifest FILE --inline-manifest FILE \\
    --confirm-transaction-sha256 SHA256 --base-url ORIGIN --session SESSION \\
    --seo-checkpoint FILE --inline-checkpoint FILE --journal FILE --report FILE
`);
}

function sourcePaths() {
	const reportRoot = path.join(ROOT, '.local-reports');
	const checkpointRoot = path.join(ROOT, '.local-checkpoints');
	const seoFile = localPath(
		option('--seo-manifest'),
		[reportRoot, checkpointRoot],
		'--seo-manifest',
		{ mustExist: true }
	);
	const inlineFile = localPath(
		option('--inline-manifest'),
		[reportRoot, checkpointRoot],
		'--inline-manifest',
		{ mustExist: true }
	);
	const transactionFile = localPath(
		option('--transaction'),
		[reportRoot, checkpointRoot],
		'--transaction',
		{ mustExist: true, mode0600: true }
	);
	const seo = readJson(seoFile, validateSeoManifest, 'SEO manifest');
	const inline = readJson(
		inlineFile,
		validateInlineVisualManifest,
		'inline manifest'
	);
	const transaction = readJson(
		transactionFile,
		(value) => validateBlogSeoMediaTransaction(value, {
			seoManifest: seo,
			inlineManifest: inline,
		}),
		'transaction'
	);
	return {
		reportRoot,
		checkpointRoot,
		seoFile,
		inlineFile,
		transactionFile,
		seo,
		inline,
		transaction,
	};
}

async function main() {
	if (process.argv.includes('--help') || process.argv.includes('-h')) {
		usage();
		return;
	}
	if (process.argv.includes('--build')) {
		const reportRoot = path.join(ROOT, '.local-reports');
		const checkpointRoot = path.join(ROOT, '.local-checkpoints');
		const seoFile = localPath(
			option('--seo-manifest'),
			[reportRoot, checkpointRoot],
			'--seo-manifest',
			{ mustExist: true }
		);
		const inlineFile = localPath(
			option('--inline-manifest'),
			[reportRoot, checkpointRoot],
			'--inline-manifest',
			{ mustExist: true }
		);
		const output = localPath(
			option('--output'),
			[reportRoot],
			'--output'
		);
		const transaction = finalizeBlogSeoMediaTransaction({
			seoManifest: readJson(
				seoFile,
				validateSeoManifest,
				'SEO manifest'
			),
			inlineManifest: readJson(
				inlineFile,
				validateInlineVisualManifest,
				'inline manifest'
			),
		});
		writeAtomic0600(output, transaction);
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'build',
			environment: transaction.environment,
			posts: transaction.posts.length,
			transaction_sha256: transaction.transaction_sha256,
			output,
		}, null, 2)}\n`);
		return;
	}

	const sources = sourcePaths();
	const validateOnly = process.argv.includes('--validate-only');
	const apply = process.argv.includes('--apply');
	if (validateOnly === apply) {
		fail('choose exactly one of --validate-only or --apply');
	}
	if (validateOnly) {
		runNode('validate_seo', SEO_RUNNER, [
			'--manifest', sources.seoFile,
			'--validate-only',
		]);
		runNode('validate_inline', INLINE_RUNNER, [
			'--manifest', sources.inlineFile,
			'--validate-only',
		]);
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: sources.transaction.environment,
			posts: sources.transaction.posts.length,
			assets: sources.inline.assets.length,
			transaction_sha256:
				sources.transaction.transaction_sha256,
		}, null, 2)}\n`);
		return;
	}
	assertProductionTransactionApply(sources.transaction);
	if (
		option('--confirm-transaction-sha256')
			!== sources.transaction.transaction_sha256
	) {
		fail('--apply requires exact --confirm-transaction-sha256');
	}
	const baseUrl = option('--base-url');
	const session = option('--session');
	if (
		baseUrl !== sources.transaction.target_origin
		|| !/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(session ?? '')
	) {
		fail('base URL or session does not match the transaction');
	}
	const seoCheckpoint = localPath(
		option('--seo-checkpoint'),
		[sources.checkpointRoot],
		'--seo-checkpoint'
	);
	const inlineCheckpoint = localPath(
		option('--inline-checkpoint'),
		[sources.checkpointRoot],
		'--inline-checkpoint'
	);
	const journalFile = localPath(
		option('--journal'),
		[sources.checkpointRoot],
		'--journal'
	);
	const reportFile = localPath(
		option('--report'),
		[sources.reportRoot],
		'--report'
	);
	const mediaPreflightReport = reportSidecar(
		reportFile,
		'media-preflight'
	);
	const inlineApplyReport = reportSidecar(reportFile, 'inline-apply');
	const inlineRollbackReportBase = reportSidecar(
		reportFile,
		'inline-rollback'
	);
	const nextInlineRollbackReport = () => {
		if (!fs.existsSync(inlineRollbackReportBase)) {
			return inlineRollbackReportBase;
		}
		for (let index = 1; index <= 100; index += 1) {
			const candidate = reportSidecar(
				reportFile,
				`inline-rollback-${index}`
			);
			if (!fs.existsSync(candidate)) return candidate;
		}
		fail('too many inline rollback evidence files');
	};
	for (const file of [
		reportFile,
		mediaPreflightReport,
		inlineApplyReport,
	]) {
		if (fs.existsSync(file)) fail(`refusing to overwrite ${file}`);
	}

	const commandFor = (step) => {
		const seoCommon = [
			'--manifest', sources.seoFile,
			'--transaction-manifest', sources.transactionFile,
			'--base-url', baseUrl,
			'--session', session,
			'--checkpoint', seoCheckpoint,
			'--confirm-transaction-sha256',
			sources.transaction.transaction_sha256,
		];
		const inlineCommon = [
			'--manifest', sources.inlineFile,
			'--base-url', baseUrl,
			'--session', session,
		];
		const commands = {
			validate_seo: [
				SEO_RUNNER,
				['--manifest', sources.seoFile, '--validate-only'],
			],
			validate_inline: [
				INLINE_RUNNER,
				['--manifest', sources.inlineFile, '--validate-only'],
			],
			preflight_seo: [
				SEO_RUNNER,
				[
					'--manifest', sources.seoFile,
					'--base-url', baseUrl,
					'--session', session,
					'--checkpoint', seoCheckpoint,
				],
			],
			preflight_media_filenames: [
				INLINE_RUNNER,
				[
					...inlineCommon,
					'--preflight-media-only',
					'--report', mediaPreflightReport,
				],
			],
			stage_content: [
				SEO_RUNNER,
				[
					...seoCommon,
					'--apply-deferred-score',
					'--confirm-manifest-sha256',
					sources.seo.manifest_sha256,
				],
			],
			apply_media: [
				INLINE_RUNNER,
				[
					...inlineCommon,
					'--checkpoint', inlineCheckpoint,
					'--report', inlineApplyReport,
					'--apply',
					'--confirm-manifest-sha256',
					sources.inline.manifest_sha256,
				],
			],
			verify_final_score: [
				SEO_RUNNER,
				[...seoCommon, '--verify-score'],
			],
			rollback_media: [
				INLINE_RUNNER,
				[
					...inlineCommon,
					'--checkpoint', inlineCheckpoint,
					'--rollback-report', nextInlineRollbackReport(),
					'--rollback-transaction',
					'--confirm-manifest-sha256',
					sources.inline.manifest_sha256,
				],
			],
			rollback_content: [
				SEO_RUNNER,
				[
					...seoCommon,
					'--rollback',
					'--confirm-manifest-sha256',
					sources.seo.manifest_sha256,
				],
			],
		};
		return commands[step];
	};
	const runStep = async (step) => {
		const command = commandFor(step);
		if (!command) fail(`unknown transaction step ${step}`);
		return runNode(step, command[0], command[1]);
	};

	if (fs.existsSync(journalFile)) {
		const journal = readJson(
			journalFile,
			(value) => value,
			'transaction journal'
		);
		if (
			journal.transaction_sha256
				!== sources.transaction.transaction_sha256
			|| journal.session !== session
			|| ['complete', 'rolled_back', 'rollback_failed']
				.includes(journal.phase)
		) {
			fail('existing journal cannot be recovered automatically');
		}
		const errors = [];
		for (const step of transactionRecoverySteps(journal.phase)) {
			try {
				await runStep(step);
			} catch (error) {
				errors.push(`${step}:${error.message}`);
			}
		}
		journal.phase = errors.length === 0
			? 'rolled_back'
			: 'rollback_failed';
		journal.recovery_errors = errors;
		journal.updated_at = new Date().toISOString();
		writeAtomic0600(journalFile, journal, { replace: true });
		if (errors.length > 0) {
			fail(`journal recovery failed: ${errors.join(' | ')}`);
		}
		fail('previous interrupted transaction was rolled back; use a new journal');
	}
	const journal = {
		schema_version: 1,
		journal_type:
			'akibara_blog_seo_media_official_ui_transaction_journal',
		transaction_sha256: sources.transaction.transaction_sha256,
		environment: sources.transaction.environment,
		target_origin: sources.transaction.target_origin,
		session,
		phase: 'initialized',
		created_at: new Date().toISOString(),
		updated_at: new Date().toISOString(),
	};
	writeAtomic0600(journalFile, journal);
	try {
		await executeTransactionStateMachine({
			runStep,
			onPhase: async (phase) => {
				journal.phase = phase;
				journal.updated_at = new Date().toISOString();
				writeAtomic0600(journalFile, journal, { replace: true });
			},
		});
		writeAtomic0600(reportFile, {
			schema_version: 1,
			report_type:
				'akibara_blog_seo_media_official_ui_transaction_evidence',
			status: 'applied_media_then_score_verified',
			environment: sources.transaction.environment,
			target_origin: sources.transaction.target_origin,
			transaction_sha256: sources.transaction.transaction_sha256,
			seo_manifest_sha256: sources.seo.manifest_sha256,
			inline_visual_manifest_sha256: sources.inline.manifest_sha256,
			posts: 25,
			assets: 32,
			completed_at: new Date().toISOString(),
		});
	} catch (error) {
		writeAtomic0600(reportFile, {
			schema_version: 1,
			report_type:
				'akibara_blog_seo_media_official_ui_transaction_evidence',
			status: journal.phase,
			environment: sources.transaction.environment,
			target_origin: sources.transaction.target_origin,
			transaction_sha256: sources.transaction.transaction_sha256,
			failed_at: new Date().toISOString(),
			error: error.message,
		});
		throw error;
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		mode: 'apply',
		posts: 25,
		assets: 32,
		transaction_sha256: sources.transaction.transaction_sha256,
		journal: journalFile,
		report: reportFile,
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
