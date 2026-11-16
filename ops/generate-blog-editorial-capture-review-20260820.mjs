#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function fail(message) {
	throw new Error(`Blog editorial capture review failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localPath(value, label, { mustExist = true } = {}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	if (![
		path.join(ROOT, '.local-reports'),
		path.join(ROOT, '.local-checkpoints'),
	].some((directory) => isInside(file, directory))) {
		fail(`${label} must remain below a local report directory`);
	}
	if (
		mustExist
		&& (!fs.existsSync(file) || !fs.lstatSync(file).isFile())
	) {
		fail(`${label} must be a regular file`);
	}
	if (!mustExist && fs.existsSync(file)) fail(`${label} already exists`);
	return file;
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function writeAtomic0600(file, content) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, content, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function main() {
	const desiredPath = localPath(option('--desired'), '--desired');
	const output = localPath(option('--output'), '--output', { mustExist: false });
	const environment = option('--environment');
	if (!['docker', 'production'].includes(environment)) {
		fail('--environment must be docker or production');
	}
	const desiredRaw = fs.readFileSync(desiredPath);
	const desired = JSON.parse(desiredRaw.toString('utf8'));
	const count = desired?.inventory_post_count;
	if (
		desired?.schema_version !== 1
		|| desired?.desired_type !== 'akibara_blog_seo_desired'
		|| desired?.mutation_scope !== 'content_and_seo_only'
		|| !Number.isSafeInteger(count)
		|| count !== 35
		|| !Array.isArray(desired.posts)
		|| desired.posts.length !== count
		|| !Array.isArray(desired.retained_drafts)
		|| desired.retained_drafts.map((row) => row.post_id).sort((a, b) => a - b).join(',')
			!== '24974,25039'
		|| !Array.isArray(desired.status_transitions)
		|| desired.status_transitions.length !== 1
		|| desired.status_transitions[0]?.post_id !== 24977
		|| desired.status_transitions[0]?.before !== 'publish'
		|| desired.status_transitions[0]?.after !== 'draft'
	) {
		fail('desired input is not the reviewed 35-post active contract');
	}

	const targetOrigin = environment === 'docker'
		? 'http://localhost:8080'
		: 'https://akibara.cl';
	let localMap = new Map();
	let receiptRaw = null;
	let receiptPath = null;
	if (environment === 'docker') {
		receiptPath = localPath(option('--local-apply-receipt'), '--local-apply-receipt');
		receiptRaw = fs.readFileSync(receiptPath);
		const receipt = JSON.parse(receiptRaw.toString('utf8'));
		if (
			receipt?.schema_version !== 1
			|| receipt?.receipt_type !== 'akibara_blog_editorial_local_apply_20260820'
			|| receipt?.environment !== 'local'
			|| receipt?.target_origin !== targetOrigin
			|| receipt?.desired_file_sha256 !== sha256(desiredRaw)
			|| receipt?.posts_processed !== count
			|| !Array.isArray(receipt.records)
			|| receipt.records.length !== count
			|| receipt?.image_mutations !== 0
			|| receipt?.taxonomy_mutations !== 0
			|| receipt?.slug_mutations !== 0
		) {
			fail('local apply receipt is incomplete or belongs to another desired input');
		}
		localMap = new Map(receipt.records.map((row) => [row.source_post_id, row.local_post_id]));
		if (localMap.size !== count) fail('local receipt source-to-local map is incomplete');
	} else if (option('--local-apply-receipt')) {
		fail('--local-apply-receipt is Docker-only');
	}

	const ids = new Set();
	const keys = new Set();
	const posts = desired.posts.map((post) => {
		if (
			!Number.isSafeInteger(post.source_post_id)
			|| ids.has(post.source_post_id)
			|| typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
		) {
			fail(`invalid desired identity ${post?.source_post_id ?? '(missing)'}`);
		}
		ids.add(post.source_post_id);
		keys.add(post.content_key);
		const retainedDraft = [24974, 25039].includes(post.source_post_id);
		const transitioning = post.source_post_id === 24977;
		const postStatus = retainedDraft || (environment === 'docker' && transitioning)
			? 'draft'
			: 'publish';
		return {
			content_key: post.content_key,
			post_id: environment === 'docker'
				? localMap.get(post.source_post_id)
				: post.source_post_id,
			post_status: postStatus,
			public_url: `${targetOrigin}/${post.content_key}/`,
		};
	});
	if (posts.some((post) => !Number.isSafeInteger(post.post_id) || post.post_id < 1)) {
		fail('one or more environment post IDs are missing');
	}
	const review = {
		schema_version: 1,
		review_type: 'akibara_blog_seo_ui_capture_review',
		environment,
		target_origin: targetOrigin,
		reviewed: true,
		inventory_post_count: count,
		capture_scope: 'content_and_seo',
		posts,
	};
	const contents = `${JSON.stringify(review, null, 2)}\n`;
	writeAtomic0600(output, contents);
	writeAtomic0600(`${output}.sha256`, `${sha256(contents)}  ${path.basename(output)}\n`);
	writeAtomic0600(
		`${output}.desired.sha256`,
		`${sha256(desiredRaw)}  ${path.basename(desiredPath)}\n`,
	);
	if (receiptRaw) {
		writeAtomic0600(
			`${output}.local-apply.sha256`,
			`${sha256(receiptRaw)}  ${path.basename(receiptPath)}\n`,
		);
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		posts: posts.length,
		published: posts.filter((post) => post.post_status === 'publish').length,
		drafts: posts.filter((post) => post.post_status === 'draft').length,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
