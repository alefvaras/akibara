import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/d7ba4691-692a-4aed-96f1-081f9c1f8fd4/screenshots_qa/pricing-matrix';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

function getLocalAdminCookies() {
	const php = `<?php
		$u = get_user_by('id', 1);
		if (!$u) {
			$users = get_users(['role' => 'administrator', 'number' => 1]);
			$u = $users[0] ?? null;
		}
		$exp = time() + 7200;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		$auth = wp_generate_auth_cookie($u->ID, $exp, 'auth', $tok);
		$logged = wp_generate_auth_cookie($u->ID, $exp, 'logged_in', $tok);
		echo json_encode([
			['name' => AUTH_COOKIE, 'value' => $auth, 'domain' => 'localhost', 'path' => '/'],
			['name' => LOGGED_IN_COOKIE, 'value' => $logged, 'domain' => 'localhost', 'path' => '/']
		]);
	`;
	const p = spawnSync(
		'docker',
		['exec', '-i', 'akibara-cron-1', 'wp', 'eval-file', '-'],
		{ input: php, encoding: 'utf8' }
	);
	if (p.status !== 0) {
		console.warn('Warning: Could not get local admin cookies:', p.stderr);
		return [];
	}
	try {
		const out = p.stdout.trim();
		const jsonStart = out.indexOf('[');
		if (jsonStart !== -1) {
			return JSON.parse(out.slice(jsonStart));
		}
		return JSON.parse(out);
	} catch (e) {
		console.warn('Warning: parsing admin cookies failed:', p.stdout);
		return [];
	}
}

async function capture() {
	const cookies = getLocalAdminCookies();
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 }
	});
	if (cookies.length > 0) {
		await context.addCookies(cookies);
	}

	const page = await context.newPage();

	const tabs = ['scales', 'series', 'rescue', 'orders', 'rules'];
	for (const tab of tabs) {
		const url = `http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix&tab=${tab}`;
		console.log(`Navigating to ${url}...`);
		await page.goto(url, { waitUntil: 'networkidle' });
		const file = path.join(SCREENSHOT_DIR, `baseline-${tab}.png`);
		await page.screenshot({ path: file, fullPage: true });
		console.log(`Saved screenshot: ${file}`);
	}

	await browser.close();
}

capture().catch(console.error);
