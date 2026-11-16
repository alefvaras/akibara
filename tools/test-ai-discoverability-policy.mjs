#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
	parseContentSignal,
	parseRobots,
	robotsAllows,
	robotsContentSignal,
} from './lib/ai-discoverability-policy.mjs';

const good = parseContentSignal('search=yes, ai-input=yes, ai-train=no');
assert.deepEqual(good.values, { search: 'yes', 'ai-input': 'yes', 'ai-train': 'no' });
assert.deepEqual(good.errors, []);

const conflict = parseContentSignal('search=yes, search=no, ai-train=maybe');
assert.match(conflict.errors.join('\n'), /valor conflictivo para search/);
assert.match(conflict.errors.join('\n'), /valor invalido para ai-train/);

const liveLike = parseRobots(`
User-agent: *
Content-Signal: search=yes, ai-input=yes, ai-train=yes
Allow: /
Disallow: /checkout/
Sitemap: https://akibara.cl/sitemap_index.xml
`);
assert.deepEqual(robotsContentSignal(liveLike, 'OAI-SearchBot').values, {
	search: 'yes',
	'ai-input': 'yes',
	'ai-train': 'yes',
});
assert.equal(robotsAllows(liveLike, 'Storebot-Google', '/checkout/').allowed, false);
assert.deepEqual(liveLike.sitemaps, ['https://akibara.cl/sitemap_index.xml']);

const specificStorebot = parseRobots(`
User-agent: *
Disallow: /checkout/
Disallow: /wp-admin/

User-agent: Storebot-Google
Allow: /checkout/
Disallow: /wp-admin/
`);
assert.equal(robotsAllows(specificStorebot, 'Storebot-Google', '/checkout/').allowed, true);
assert.equal(robotsAllows(specificStorebot, 'Storebot-Google', '/wp-admin/').allowed, false);

const noInheritance = parseRobots(`
User-agent: *
Disallow: /wp-admin/

User-agent: OAI-SearchBot
Allow: /
`);
assert.equal(
	robotsAllows(noInheritance, 'OAI-SearchBot', '/wp-admin/').allowed,
	true,
	'a specific group must not inherit wildcard exclusions'
);

const conflictingGroups = parseRobots(`
User-agent: Claude-SearchBot
Content-Signal: search=yes, ai-input=yes, ai-train=no
Allow: /

User-agent: Claude-SearchBot
Content-Signal: search=yes, ai-input=yes, ai-train=yes
Allow: /
`);
assert.match(
	robotsContentSignal(conflictingGroups, 'Claude-SearchBot').errors.join('\n'),
	/directivas conflictivas para ai-train/
);

const selectiveCandidate = parseRobots(fs.readFileSync(
	new URL('../ops/contracts/rankmath-robots-v14-selective-candidate.txt', import.meta.url),
	'utf8'
));
for (const agent of [
	'OAI-SearchBot',
	'ChatGPT-User',
	'Claude-SearchBot',
	'Claude-User',
	'PerplexityBot',
]) {
	assert.equal(robotsAllows(selectiveCandidate, agent, '/manga/').allowed, true);
	assert.equal(robotsAllows(selectiveCandidate, agent, '/wp-admin/').allowed, false);
	assert.equal(robotsAllows(selectiveCandidate, agent, '/checkout/').allowed, false);
	assert.equal(robotsContentSignal(selectiveCandidate, agent).values['ai-train'], 'no');
}
for (const path of ['/manga/', '/carrito/', '/checkout/', '/finalizar-compra/']) {
	assert.equal(
		robotsAllows(selectiveCandidate, 'Storebot-Google', path).allowed,
		true,
		`Storebot-Google must reach ${path}`
	);
}
assert.equal(
	robotsAllows(selectiveCandidate, 'Storebot-Google', '/wp-admin/').allowed,
	false
);
assert.equal(
	robotsAllows(selectiveCandidate, 'Storebot-Google', '/wp-json/wc/store/v1/cart').allowed,
	true
);
assert.equal(robotsContentSignal(selectiveCandidate, 'Google-Extended').values['ai-train'], 'yes');
assert.equal(robotsContentSignal(selectiveCandidate, 'GPTBot').values['ai-train'], 'no');

const storebotOnlyCandidate = parseRobots(fs.readFileSync(
	new URL('../ops/contracts/rankmath-robots-v14-storebot-only-candidate.txt', import.meta.url),
	'utf8'
));
for (const path of ['/manga/', '/carrito/', '/checkout/', '/finalizar-compra/']) {
	assert.equal(
		robotsAllows(storebotOnlyCandidate, 'Storebot-Google', path).allowed,
		true,
		`Storebot-only policy must let Storebot-Google reach ${path}`
	);
}
assert.equal(
	robotsAllows(storebotOnlyCandidate, 'Storebot-Google', '/wp-json/wc/store/v1/cart').allowed,
	true
);
assert.equal(
	robotsAllows(storebotOnlyCandidate, 'Storebot-Google', '/wp-admin/').allowed,
	false
);
for (const agent of ['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot']) {
	assert.equal(robotsAllows(storebotOnlyCandidate, agent, '/manga/').allowed, true);
	assert.equal(robotsAllows(storebotOnlyCandidate, agent, '/checkout/').allowed, false);
	assert.equal(robotsContentSignal(storebotOnlyCandidate, agent).values['ai-train'], 'yes');
}

process.stdout.write('PASS: ai discoverability policy parser\n');
