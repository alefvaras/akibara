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

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function localPath(value, label, { mustExist = true } = {}) {
	if (!value) fail(`missing ${label}`);
	const resolved = path.resolve(value);
	const allowed = ['.local-reports', '.local-checkpoints'].some((directory) => {
		const relative = path.relative(path.join(ROOT, directory), resolved);
		return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
	});
	if (!allowed) fail(`${label} must remain below a local report directory`);
	if (
		mustExist
		&& (!fs.existsSync(resolved) || !fs.lstatSync(resolved).isFile())
	) {
		fail(`${label} must be a regular file`);
	}
	return resolved;
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, value, {
		encoding: 'utf8',
		mode: 0o600,
		flag: 'wx',
	});
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
	const targetOrigin = environment === 'docker'
		? 'http://localhost:8080'
		: 'https://akibara.cl';
	const desiredRaw = fs.readFileSync(desiredPath);
	const desired = JSON.parse(desiredRaw.toString('utf8'));
	if (
		desired?.schema_version !== 1
		|| desired?.desired_type !== 'akibara_blog_seo_desired'
		|| desired?.inventory_post_count !== 36
		|| desired?.mutation_scope !== 'content_and_seo_only'
		|| !Array.isArray(desired.posts)
		|| desired.posts.length !== 36
	) {
		fail('desired input is not the reviewed 36-post content-only contract');
	}
	const receiptValue = option('--local-apply-receipt');
	if (environment === 'production' && receiptValue) {
		fail('--local-apply-receipt is Docker-only');
	}
	if (environment === 'docker' && !receiptValue) {
		fail('Docker review requires --local-apply-receipt');
	}
	let localReceiptPath = null;
	let localReceiptRaw = null;
	let localBySourceId = new Map();
	if (environment === 'docker') {
		localReceiptPath = localPath(receiptValue, '--local-apply-receipt');
		localReceiptRaw = fs.readFileSync(localReceiptPath);
		const receipt = JSON.parse(localReceiptRaw.toString('utf8'));
		if (
			receipt?.schema_version !== 1
			|| receipt?.receipt_type !== 'akibara_blog_editorial_local_apply'
			|| receipt?.environment !== 'local'
			|| receipt?.target_origin !== targetOrigin
			|| receipt?.desired_file_sha256 !== sha256(desiredRaw)
			|| receipt?.posts_processed !== 36
			|| receipt?.image_mutations !== 0
			|| receipt?.media_mutations !== 0
			|| receipt?.caption_mutations !== 0
			|| receipt?.image_alt_mutations !== 0
			|| !Array.isArray(receipt.records)
			|| receipt.records.length !== 36
		) {
			fail('local apply receipt is incomplete or not bound to desired input');
		}
		localBySourceId = new Map(receipt.records.map((record) => [
			record.source_post_id,
			record.local_post_id,
		]));
		if (
			localBySourceId.size !== 36
			|| [...localBySourceId.entries()].some(([sourceId, localId]) => (
				!Number.isSafeInteger(sourceId)
				|| sourceId < 1
				|| !Number.isSafeInteger(localId)
				|| localId < 1
			))
		) {
			fail('local apply receipt contains an invalid source-to-local map');
		}
	}
	const ids = new Set();
	const keys = new Set();
	const draftIds = new Set([
		...(desired.retained_drafts ?? []).map((post) => post.post_id),
		...(desired.status_transitions ?? [])
			.filter((transition) => transition.before === 'draft')
			.map((transition) => transition.post_id),
	]);
	if (
		draftIds.size !== 3
		|| ![24974, 25039, 27460].every((postId) => draftIds.has(postId))
	) {
		fail('desired input does not preserve the three reviewed source drafts');
	}
	const posts = desired.posts.map((post) => {
		if (
			!Number.isSafeInteger(post.source_post_id)
			|| post.source_post_id < 1
			|| ids.has(post.source_post_id)
			|| typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
			|| Object.hasOwn(post, 'featured_image_alt')
		) {
			fail('desired input contains an invalid post identity or image key');
		}
		ids.add(post.source_post_id);
		keys.add(post.content_key);
		return {
			content_key: post.content_key,
			post_id: environment === 'docker'
				? localBySourceId.get(post.source_post_id)
				: post.source_post_id,
			post_status: draftIds.has(post.source_post_id) ? 'draft' : 'publish',
			public_url: `${targetOrigin}/${post.content_key}/`,
		};
	});
	const review = {
		schema_version: 1,
		review_type: 'akibara_blog_seo_ui_capture_review',
		environment,
		target_origin: targetOrigin,
		reviewed: true,
		inventory_post_count: 36,
		capture_scope: 'content_and_seo',
		desired_sha256: sha256(desiredRaw),
		posts,
	};
	// The capture tool intentionally rejects extra root keys, so bind the
	// desired hash in a local sidecar instead of weakening its review schema.
	const desiredHash = review.desired_sha256;
	delete review.desired_sha256;
	const contents = `${JSON.stringify(review, null, 2)}\n`;
	writeAtomic(output, contents);
	writeAtomic(`${output}.sha256`, `${sha256(contents)}  ${path.basename(output)}\n`);
	writeAtomic(
		`${output}.desired.sha256`,
		`${desiredHash}  ${path.basename(desiredPath)}\n`
	);
	if (localReceiptRaw) {
		writeAtomic(
			`${output}.local-apply.sha256`,
			`${sha256(localReceiptRaw)}  ${path.basename(localReceiptPath)}\n`
		);
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		posts: posts.length,
		capture_scope: review.capture_scope,
		featured_image_entries: 0,
		output,
	}, null, 2)}\n`);
}

main();
