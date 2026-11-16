#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function fail(message) {
	throw new Error(`Blog editorial link probe failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative);
}

function fileOption(name, { output = false } = {}) {
	const value = option(name);
	if (!value) fail(`missing ${name}`);
	const file = path.resolve(value);
	if (!inside(file, path.join(ROOT, '.local-reports'))) {
		fail(`${name} must remain below .local-reports`);
	}
	if (output) {
		if (fs.existsSync(file)) fail(`${name} already exists`);
	} else if (!fs.existsSync(file) || !fs.lstatSync(file).isFile()) {
		fail(`${name} must be a regular file`);
	}
	return file;
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8', flag: 'wx', mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

async function request(url, method) {
	const controller = new AbortController();
	const timeout = setTimeout(() => controller.abort(), 30_000);
	try {
		const response = await fetch(url, {
			method,
			redirect: 'follow',
			signal: controller.signal,
			headers: {
				'user-agent': 'AkibaraEditorialLinkQA/20260820',
				accept: 'text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8',
				...(method === 'GET' ? { range: 'bytes=0-2047' } : {}),
			},
		});
		if (response.body) await response.body.cancel();
		return {
			status: response.status,
			final_url: response.url,
			redirected: response.redirected,
			method,
		};
	} finally {
		clearTimeout(timeout);
	}
}

async function probe(url) {
	const started = Date.now();
	try {
		let result = await request(url, 'HEAD');
		if ([400, 405, 501].includes(result.status)) result = await request(url, 'GET');
		return { url, ...result, elapsed_ms: Date.now() - started };
	} catch (error) {
		// Some publisher storefronts abort Node's TLS request while still
		// returning a valid Queue-it redirect to ordinary clients. A no-follow
		// curl probe distinguishes that reachable response from DNS/TLS failure.
		const fallback = spawnSync('curl', [
			'--silent', '--show-error', '--max-time', '30', '--connect-timeout', '10',
			'--output', '/dev/null', '--write-out', '%{http_code}\t%{redirect_url}',
			'--user-agent', 'AkibaraEditorialLinkQA/20260820', url,
		], { encoding: 'utf8', timeout: 40_000, maxBuffer: 128 * 1024 });
		const [statusText = '0', redirectUrl = ''] = fallback.stdout.split('\t');
		const fallbackStatus = Number(statusText);
		if (!fallback.error && fallback.status === 0 && fallbackStatus > 0) {
			return {
				url,
				status: fallbackStatus,
				final_url: url,
				redirected: fallbackStatus >= 300 && fallbackStatus < 400,
				redirect_url: redirectUrl,
				method: 'CURL_GET_NO_FOLLOW',
				elapsed_ms: Date.now() - started,
				fetch_error: error.message,
			};
		}
		return {
			url,
			status: 0,
			final_url: '',
			redirected: false,
			method: 'HEAD',
			elapsed_ms: Date.now() - started,
			error: error.message,
		};
	}
}

async function pooled(values, concurrency, worker) {
	const results = new Array(values.length);
	let cursor = 0;
	await Promise.all(Array.from({ length: concurrency }, async () => {
		while (cursor < values.length) {
			const index = cursor;
			cursor += 1;
			results[index] = await worker(values[index]);
		}
	}));
	return results;
}

async function main() {
	const auditPath = fileOption('--audit');
	const output = fileOption('--output', { output: true });
	const audit = JSON.parse(fs.readFileSync(auditPath, 'utf8'));
	const urls = audit?.url_inventory?.external_urls;
	if (audit?.result !== 'PASS' || !Array.isArray(urls) || urls.length < 1) {
		fail('audit is not a passing editorial report with external URLs');
	}
	const unique = [...new Set(urls)].sort();
	const rows = await pooled(unique, 10, probe);
	const broken = rows.filter((row) => row.status === 0 || row.status === 404 || row.status === 410 || row.status >= 500);
	const blocked = rows.filter((row) => [401, 403, 429].includes(row.status));
	const report = {
		schema_version: 1,
		report_type: 'akibara_blog_editorial_external_link_probe_20260820',
		generated_at_gmt: new Date().toISOString(),
		read_only: true,
		result: broken.length === 0 ? 'PASS' : 'FAIL',
		counts: {
			total: rows.length,
			reachable: rows.length - broken.length,
			blocked_but_responded: blocked.length,
			broken: broken.length,
		},
		broken,
		blocked,
		rows,
	};
	writeAtomic0600(output, report);
	process.stdout.write(`${JSON.stringify({
		result: report.result,
		...report.counts,
		output,
	}, null, 2)}\n`);
	if (broken.length > 0) process.exitCode = 1;
}

main().catch((error) => {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
});
