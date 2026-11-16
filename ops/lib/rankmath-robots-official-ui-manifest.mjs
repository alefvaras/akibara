import {
	canonicalJson,
	normalizeEditorialValue,
	sha256,
} from './blog-seo-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Rank Math robots UI manifest rejected: ${message}`);
}

const GOOGLE_EXTENDED_TRADEOFF_COMMENT =
	'# Google-Extended se permite conscientemente: aporta grounding y también puede contribuir al entrenamiento futuro de Gemini.';

const SELECTIVE_CONTENT_SIGNAL =
	'Content-Signal: search=yes, ai-input=yes, ai-train=no';
const OPEN_TRAINING_CONTENT_SIGNAL =
	'Content-Signal: search=yes, ai-input=yes, ai-train=yes';
const GOOGLE_EXTENDED_CONTENT_SIGNAL =
	OPEN_TRAINING_CONTENT_SIGNAL;
const TRAINING_BLOCK_CONTENT_SIGNAL =
	'Content-Signal: search=no, ai-input=no, ai-train=no';

const SEARCH_HYGIENE_DIRECTIVES = [
	'Disallow: /wp-admin/',
	'Allow: /wp-admin/admin-ajax.php',
	'Disallow: /xmlrpc.php',
	'Disallow: /wp-cron.php',
	'Disallow: /wp-json/',
	'Disallow: /trackback/',
	'Disallow: /carrito/',
	'Disallow: /checkout/',
	'Disallow: /mi-cuenta/',
	'Disallow: /?s=',
	'Disallow: /search/',
	'Disallow: /*/feed/',
	'Disallow: /feed/',
	'Disallow: /comments/feed/',
];

const STOREBOT_HYGIENE_DIRECTIVES = [
	'Disallow: /wp-admin/',
	'Allow: /wp-admin/admin-ajax.php',
	'Disallow: /xmlrpc.php',
	'Disallow: /wp-cron.php',
	'Disallow: /wp-json/',
	'Allow: /wp-json/wc/store/',
	'Disallow: /trackback/',
	'Disallow: /mi-cuenta/',
	'Disallow: /?s=',
	'Disallow: /search/',
	'Disallow: /*/feed/',
	'Disallow: /feed/',
	'Disallow: /comments/feed/',
];

const REQUIRED_AGENT_POLICIES = new Map([
	['GPTBot', 'Disallow: /'],
	['CCBot', 'Disallow: /'],
	['Bytespider', 'Disallow: /'],
	['Applebot-Extended', 'Disallow: /'],
	['meta-externalagent', 'Disallow: /'],
	['Amazonbot', 'Disallow: /'],
	['ClaudeBot', 'Disallow: /'],
	['Google-Extended', 'Allow: /'],
	['OAI-SearchBot', 'Allow: /'],
	['ChatGPT-User', 'Allow: /'],
	['Claude-SearchBot', 'Allow: /'],
	['Claude-User', 'Allow: /'],
	['PerplexityBot', 'Allow: /'],
	['Storebot-Google', 'Allow: /'],
]);

const TRAINING_BLOCKED_AGENTS = new Set([
	'GPTBot',
	'CCBot',
	'Bytespider',
	'Applebot-Extended',
	'meta-externalagent',
	'Amazonbot',
	'ClaudeBot',
]);

const SEARCH_ALLOWED_AGENTS = new Set([
	'OAI-SearchBot',
	'ChatGPT-User',
	'Claude-SearchBot',
	'Claude-User',
	'PerplexityBot',
]);

export function normalizeRobotsValue(value) {
	return normalizeEditorialValue(value).replace(/\n+$/u, '');
}

function parseRobotsGroups(lines) {
	const groups = [];
	let current = null;
	const finish = () => {
		if (current) {
			groups.push(current);
			current = null;
		}
	};

	for (const line of lines) {
		const agent = /^User-agent:\s*(\S+)\s*$/iu.exec(line);
		if (agent) {
			if (current?.directives.length) {
				finish();
			}
			current ??= { agents: [], directives: [] };
			current.agents.push(agent[1]);
			continue;
		}
		if (
			current
			&& !line.startsWith('#')
			&& !line.startsWith('Sitemap:')
		) {
			current.directives.push(line);
		}
	}
	finish();

	return groups;
}

function validateSelectiveAiCrawlerPolicy(lines, groups) {
	if (!lines.includes(GOOGLE_EXTENDED_TRADEOFF_COMMENT)) {
		fail(
			'robots policy must explain the Google-Extended grounding and future Gemini training tradeoff'
		);
	}

	for (const [agent, expectedDirective] of REQUIRED_AGENT_POLICIES) {
		const matches = groups.filter((group) => group.agents.some(
			(value) => value.toLowerCase() === agent.toLowerCase()
		));
		if (matches.length !== 1) {
			fail(`robots policy must contain exactly one ${agent} group`);
		}
		const rootDirectives = matches[0].directives.filter(
			(directive) => directive === 'Allow: /'
				|| directive === 'Disallow: /'
		);
		if (
			rootDirectives.length !== 1
			|| rootDirectives[0] !== expectedDirective
		) {
			fail(
				`robots ${agent} policy must contain exactly ${expectedDirective}`
			);
		}

		const signals = matches[0].directives.filter(
			(directive) => directive.startsWith('Content-Signal:')
		);
		const expectedSignal = TRAINING_BLOCKED_AGENTS.has(agent)
			? TRAINING_BLOCK_CONTENT_SIGNAL
			: (agent === 'Google-Extended'
				? GOOGLE_EXTENDED_CONTENT_SIGNAL
				: SELECTIVE_CONTENT_SIGNAL);
		if (signals.length !== 1 || signals[0] !== expectedSignal) {
			fail(`robots ${agent} policy must contain exactly ${expectedSignal}`);
		}

		if (SEARCH_ALLOWED_AGENTS.has(agent)) {
			for (const directive of SEARCH_HYGIENE_DIRECTIVES) {
				if (!matches[0].directives.includes(directive)) {
					fail(`robots ${agent} policy is missing ${directive}`);
				}
			}
		}

		if (agent === 'Storebot-Google') {
			for (const directive of STOREBOT_HYGIENE_DIRECTIVES) {
				if (!matches[0].directives.includes(directive)) {
					fail(`robots Storebot-Google policy is missing ${directive}`);
				}
			}
			for (const commercePath of [
				'Disallow: /carrito/',
				'Disallow: /checkout/',
				'Disallow: /finalizar-compra/',
			]) {
				if (matches[0].directives.includes(commercePath)) {
					fail(`robots Storebot-Google policy must not contain ${commercePath}`);
				}
			}
		}
	}
}

function validateStorebotOnlyAiCrawlerPolicy(lines, groups) {
	// La declaración humana de la política de entrenamiento debe existir como
	// comentario, pero sin pinear el literal de una versión (el sello exacto
	// "Política v14" dejó inválidos a los candidatos v15+ del mismo modo).
	const declaresTrainingPolicy = lines.some(
		(line) => line.startsWith('#') && /entrenamiento/iu.test(line)
	);
	if (!declaresTrainingPolicy) {
		fail('Storebot-only policy must declare that training remains unchanged');
	}

	const declaredAgents = groups.flatMap((group) => group.agents);
	if (
		declaredAgents.length !== 2
		|| !declaredAgents.some(
			(agent) => agent.toLowerCase() === 'storebot-google'
		)
		|| !declaredAgents.includes('*')
	) {
		fail('Storebot-only policy may contain only Storebot-Google and wildcard groups');
	}

	const matches = groups.filter((group) => group.agents.some(
		(agent) => agent.toLowerCase() === 'storebot-google'
	));
	if (matches.length !== 1 || matches[0].agents.length !== 1) {
		fail('Storebot-only policy must contain exactly one dedicated Storebot-Google group');
	}

	const directives = matches[0].directives;
	const rootDirectives = directives.filter(
		(directive) => directive === 'Allow: /'
			|| directive === 'Disallow: /'
	);
	if (rootDirectives.length !== 1 || rootDirectives[0] !== 'Allow: /') {
		fail('robots Storebot-Google policy must contain exactly Allow: /');
	}

	const signals = directives.filter(
		(directive) => directive.startsWith('Content-Signal:')
	);
	if (
		signals.length !== 1
		|| signals[0] !== OPEN_TRAINING_CONTENT_SIGNAL
	) {
		fail(
			`robots Storebot-Google policy must contain exactly ${OPEN_TRAINING_CONTENT_SIGNAL}`
		);
	}

	for (const directive of STOREBOT_HYGIENE_DIRECTIVES) {
		if (!directives.includes(directive)) {
			fail(`robots Storebot-Google policy is missing ${directive}`);
		}
	}
	for (const commercePath of [
		'Disallow: /carrito/',
		'Disallow: /checkout/',
		'Disallow: /finalizar-compra/',
	]) {
		if (directives.includes(commercePath)) {
			fail(`robots Storebot-Google policy must not contain ${commercePath}`);
		}
	}
}

function validateRobotsSeoPolicy(content, targetOrigin) {
	const lines = content
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);
	const groups = parseRobotsGroups(lines);
	const hasSelectiveAgents = groups.some((group) => group.agents.some(
		(agent) => !['storebot-google', '*'].includes(agent.toLowerCase())
	));
	const selective = lines.includes(GOOGLE_EXTENDED_TRADEOFF_COMMENT)
		|| hasSelectiveAgents;
	// Modo Storebot: detección estructural (grupo Storebot-Google presente sin
	// agentes selectivos), no por comentario sellado a una versión concreta.
	const storebotOnly = !selective && groups.some((group) => group.agents.some(
		(agent) => agent.toLowerCase() === 'storebot-google'
	));
	if (selective) {
		validateSelectiveAiCrawlerPolicy(lines, groups);
	} else if (storebotOnly) {
		validateStorebotOnlyAiCrawlerPolicy(lines, groups);
	} else {
		fail('robots policy must declare exactly one supported crawler policy mode');
	}
	const wildcardIndexes = lines
		.map((line, index) => line === 'User-agent: *' ? index : -1)
		.filter((index) => index !== -1);
	if (wildcardIndexes.length !== 1) {
		fail('robots after value must contain exactly one wildcard group');
	}
	const wildcardStart = wildcardIndexes[0];
	const wildcardEnd = lines.findIndex(
		(line, index) => index > wildcardStart && /^User-agent:/iu.test(line)
	);
	const wildcard = new Set(
		lines.slice(
			wildcardStart + 1,
			wildcardEnd === -1 ? lines.length : wildcardEnd
		)
	);
	const requiredWildcard = [
		selective ? SELECTIVE_CONTENT_SIGNAL : OPEN_TRAINING_CONTENT_SIGNAL,
		'Allow: /',
		'Disallow: /wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Disallow: /xmlrpc.php',
		'Disallow: /wp-cron.php',
		'Disallow: /wp-json/',
		'Disallow: /trackback/',
		'Disallow: /carrito/',
		'Disallow: /checkout/',
		'Disallow: /mi-cuenta/',
		'Disallow: /?s=',
		'Disallow: /search/',
		'Disallow: /*/feed/',
		'Disallow: /feed/',
		'Disallow: /comments/feed/',
	];
	for (const directive of requiredWildcard) {
		if (!wildcard.has(directive)) {
			fail(`robots wildcard policy is missing ${directive}`);
		}
	}
	for (const directive of [
		'Disallow: /',
		'Disallow: /wp-content/',
		'Disallow: /wp-content/litespeed/',
		'Disallow: /tag/',
		'Disallow: /author/',
		'Disallow: /?p=',
		'Disallow: /attachment/',
		'Disallow: /wp-content/uploads/*.pdf',
		'Disallow: /?mailpoet_page=',
		'Disallow: /*?_*=' ,
		'Disallow: /*?*&_*=',
		'Disallow: /*?add-to-cart=',
		'Disallow: /*?*add-to-cart=',
		'Disallow: /*?orderby=',
		'Disallow: /*?*orderby=',
		'Disallow: /*?filter_*',
		'Disallow: /*?*filter_*',
		'Disallow: /*?query_type_*',
		'Disallow: /*?*query_type_*',
		'Disallow: /*?min_price=',
		'Disallow: /*?*min_price=',
		'Disallow: /*?max_price=',
		'Disallow: /*?*max_price=',
		'Disallow: /*?stock=',
		'Disallow: /*?*stock=',
		'Disallow: /*?sale=',
		'Disallow: /*?*sale=',
		'Disallow: /*?add-to-wishlist=',
		'Disallow: /*?*add-to-wishlist=',
		'Disallow: /*?per_page=',
		'Disallow: /*?*per_page=',
		'Disallow: /*?rating=',
		'Disallow: /*?*rating=',
		'Disallow: /*?rating_filter=',
		'Disallow: /*?*rating_filter=',
		'Disallow: /*?layout=',
		'Disallow: /*?*layout=',
	]) {
		if (wildcard.has(directive)) {
			fail(`robots wildcard policy must not contain ${directive}`);
		}
	}
	const expectedSitemap = `Sitemap: ${targetOrigin}/sitemap_index.xml`;
	if (lines.filter((line) => line.startsWith('Sitemap:')).length !== 1
		|| !lines.includes(expectedSitemap)) {
		fail(`robots policy requires the exact sitemap ${expectedSitemap}`);
	}
}

export function calculateRobotsManifestSha256(manifest) {
	return sha256(canonicalJson({
		schema_version: manifest.schema_version,
		manifest_type: manifest.manifest_type,
		environment: manifest.environment,
		target_origin: manifest.target_origin,
		edit_path: manifest.edit_path,
		application_policy: manifest.application_policy,
		robots_txt_content: manifest.robots_txt_content,
	}));
}

export function validateRobotsManifest(manifest) {
	const expectedKeys = [
		'schema_version',
		'manifest_type',
		'environment',
		'target_origin',
		'edit_path',
		'application_policy',
		'robots_txt_content',
		'manifest_sha256',
	];
	if (
		Object.keys(manifest ?? {}).some((key) => !expectedKeys.includes(key))
		|| manifest?.schema_version !== 1
		|| manifest?.manifest_type !== 'akibara_rankmath_robots_official_ui'
		|| !['docker', 'production'].includes(manifest?.environment)
		|| manifest?.edit_path
			!== '/wp-admin/admin.php?page=rank-math-options-general&view=robots'
	) {
		fail('schema, type, environment or edit path is invalid');
	}
	const originPattern = manifest.environment === 'docker'
		? /^http:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$/u
		: /^https:\/\/(?:www\.)?akibara\.cl$/u;
	if (!originPattern.test(manifest.target_origin ?? '')) {
		fail('target origin does not match environment');
	}
	const policy = manifest.application_policy ?? {};
	if (
		policy.official_rank_math_ui_only !== true
		|| policy.production_writes_only !== true
		|| policy.direct_rest_writes !== 0
		|| policy.direct_sql_writes !== 0
		|| policy.direct_wp_cli_writes !== 0
		|| policy.direct_option_writes !== 0
		|| policy.remote_artifacts !== 0
		|| Object.keys(policy).length !== 7
	) {
		fail('official Rank Math UI policy assertions failed');
	}
	const contract = manifest.robots_txt_content ?? {};
	if (
		Object.keys(contract).some(
			(key) => ![
				'before',
				'after',
				'before_sha256',
				'after_sha256',
			].includes(key)
		)
		|| Object.keys(contract).length !== 4
		|| typeof contract.before !== 'string'
		|| typeof contract.after !== 'string'
	) {
		fail('robots before/after contract is invalid');
	}
	const before = normalizeRobotsValue(contract.before);
	const after = normalizeRobotsValue(contract.after);
	if (
		contract.before_sha256 !== sha256(before)
		|| contract.after_sha256 !== sha256(after)
		|| contract.before_sha256 === contract.after_sha256
		|| !after.trim()
	) {
		fail('robots before/after hashes or values are invalid');
	}
	validateRobotsSeoPolicy(after, manifest.target_origin);
	if (
		manifest.manifest_sha256 !== calculateRobotsManifestSha256(manifest)
	) {
		fail('manifest_sha256 mismatch');
	}
	return manifest;
}

export function finalizeRobotsManifest(draft) {
	const before = normalizeRobotsValue(draft.robots_txt_content.before);
	const after = normalizeRobotsValue(draft.robots_txt_content.after);
	const manifest = {
		...draft,
		robots_txt_content: {
			before,
			after,
			before_sha256: sha256(before),
			after_sha256: sha256(after),
		},
	};
	manifest.manifest_sha256 = calculateRobotsManifestSha256(manifest);
	return validateRobotsManifest(manifest);
}
