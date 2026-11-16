#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { REQUIRED_FIELDS } from './lib/blog-seo-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Blog SEO desired consolidation failed: ${message}`);
}

function options(name) {
	const values = [];
	for (let index = 0; index < process.argv.length; index += 1) {
		if (process.argv[index] === name) values.push(process.argv[index + 1]);
	}
	return values;
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function localOutput(value) {
	if (!value) fail('missing --output');
	const output = path.resolve(value);
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const allowed = ['.local-reports', '.local-checkpoints'].some((name) => {
		const relative = path.relative(path.join(repository, name), output);
		return relative !== '' && relative !== '..'
			&& !relative.startsWith(`..${path.sep}`)
			&& !path.isAbsolute(relative);
	});
	if (!allowed) fail('output must remain under a local report directory');
	if (fs.existsSync(output)) fail('refusing to overwrite existing output');
	return output;
}

function string(value, label) {
	if (typeof value !== 'string' || !value.trim()) fail(`missing ${label}`);
	return value.replace(/\r\n?/gu, '\n');
}

function main() {
	const batchPaths = options('--batch').map((value) => path.resolve(value));
	if (batchPaths.length !== 2) {
		fail('provide exactly two reviewed --batch files');
	}
	const posts = [];
	for (const batchPath of batchPaths) {
		if (!fs.existsSync(batchPath) || !fs.lstatSync(batchPath).isFile()) {
			fail(`missing batch ${batchPath}`);
		}
		const batch = JSON.parse(fs.readFileSync(batchPath, 'utf8'));
		if (
			!Array.isArray(batch.posts)
			|| !Number.isSafeInteger(batch.target_score_floor)
			|| batch.target_score_floor < 82
			|| batch.target_score_floor > 100
		) {
			fail(`invalid reviewed batch ${batchPath}`);
		}
		for (const source of batch.posts) {
			const fields = {
				post_title: string(source.post_title, 'post_title'),
				post_content: string(source.content, 'content'),
				post_excerpt: string(source.excerpt, 'excerpt'),
				rank_math_focus_keyword: string(
					source.focus_keyword,
					'focus_keyword'
				),
				rank_math_title: string(source.seo_title, 'seo_title'),
				rank_math_description: string(
					source.seo_description,
					'seo_description'
				),
			};
			if (
				!Number.isSafeInteger(source.id)
				|| source.id < 1
				|| typeof source.slug !== 'string'
				|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(source.slug)
				|| REQUIRED_FIELDS.some(
					(field) => typeof fields[field] !== 'string'
				)
			) {
				fail(`invalid desired row ${source?.id ?? '(missing id)'}`);
			}
			posts.push({
				content_key: source.slug,
				minimum_rank_math_score: batch.target_score_floor,
				fields,
				featured_image_alt: string(
					source.featured_image_alt,
					'featured_image_alt'
				),
			});
		}
	}
	const keys = posts.map((post) => post.content_key);
	if (posts.length !== 25 || new Set(keys).size !== 25) {
		fail('reviewed batches must consolidate to exactly 25 unique posts');
	}
	posts.sort((left, right) => left.content_key.localeCompare(right.content_key));
	const output = localOutput(option('--output'));
	fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
	const temporary = `${output}.tmp-${process.pid}`;
	fs.writeFileSync(
		temporary,
		`${JSON.stringify({
			schema_version: 1,
			desired_type: 'akibara_blog_seo_desired',
			posts,
		}, null, 2)}\n`,
		{ flag: 'wx', mode: 0o600 }
	);
	fs.renameSync(temporary, output);
	fs.chmodSync(output, 0o600);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: posts.length,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
