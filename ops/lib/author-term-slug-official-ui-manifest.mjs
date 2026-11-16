import crypto from 'node:crypto';

const expectedEnvironmentPolicy = {
	docker: {
		base_url: 'http://localhost:8080',
		allowed_preflight_states: ['after'],
		public_archives_indexable: true,
	},
	production: {
		base_url: 'https://akibara.cl',
		allowed_preflight_states: ['before', 'after'],
		public_archives_indexable: true,
	},
};

const expectedTerms = [
	{
		term_id: 236,
		term_taxonomy_id: 236,
		expected_relationship_count: 23,
		ui_path: '/wp-admin/term.php?taxonomy=pa_autor&tag_ID=236&post_type=product',
		before: {
			name: 'Tatsukia Fujimoto',
			slug: 'tatsukia-fujimoto',
		},
		after: {
			name: 'Tatsuki Fujimoto',
			slug: 'tatsuki-fujimoto',
		},
	},
	{
		term_id: 275,
		term_taxonomy_id: 275,
		expected_relationship_count: 24,
		ui_path: '/wp-admin/term.php?taxonomy=pa_autor&tag_ID=275&post_type=product',
		before: {
			name: 'Tappei Nagatsuk',
			slug: 'tappei-nagatsuk',
		},
		after: {
			name: 'Tappei Nagatsuki',
			slug: 'tappei-nagatsuki',
		},
	},
	{
		term_id: 456,
		term_taxonomy_id: 456,
		expected_relationship_count: 3,
		ui_path: '/wp-admin/term.php?taxonomy=pa_autor&tag_ID=456&post_type=product',
		before: {
			name: 'Masamure Shirow',
			slug: 'masamure-shirow',
		},
		after: {
			name: 'Masamune Shirow',
			slug: 'masamune-shirow',
		},
	},
];

function fail(message) {
	throw new Error(`Author term manifest failed: ${message}`);
}

export function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function canonical(value) {
	return JSON.stringify(value);
}

function assertExactObject(actual, expected, label) {
	if (canonical(actual) !== canonical(expected)) {
		fail(`${label} differs from the reviewed contract`);
	}
}

function assertPair(pair, label) {
	if (
		!pair
		|| typeof pair !== 'object'
		|| Array.isArray(pair)
		|| Object.keys(pair).sort().join(',') !== 'name,slug'
		|| typeof pair.name !== 'string'
		|| pair.name.trim() !== pair.name
		|| pair.name.length < 2
		|| typeof pair.slug !== 'string'
		|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(pair.slug)
	) {
		fail(`${label} is not a safe exact name/slug pair`);
	}
}

export function validateAuthorTermManifest(manifest) {
	if (
		!manifest
		|| typeof manifest !== 'object'
		|| Array.isArray(manifest)
		|| manifest.schema_version !== 1
		|| manifest.plan_type !== 'akibara_author_term_slug_official_ui'
	) {
		fail('unsupported schema or plan_type');
	}
	assertExactObject(
		manifest.application_policy,
		{
			official_administration_ui_only: true,
			production_writes_only: true,
			direct_api_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			remote_import_files: 0,
			taxonomy: 'pa_autor',
			term_count: 3,
		},
		'application_policy',
	);
	assertExactObject(
		manifest.environments,
		expectedEnvironmentPolicy,
		'environments',
	);
	if (!Array.isArray(manifest.terms) || manifest.terms.length !== 3) {
		fail('terms must contain exactly three reviewed rows');
	}
	for (const [index, term] of manifest.terms.entries()) {
		assertPair(term.before, `terms[${index}].before`);
		assertPair(term.after, `terms[${index}].after`);
		if (
			term.before.name === term.after.name
			|| term.before.slug === term.after.slug
		) {
			fail(`terms[${index}] does not correct both name and slug`);
		}
	}
	assertExactObject(manifest.terms, expectedTerms, 'terms');
	return manifest;
}

export function classifyPair(term, pair) {
	if (
		pair?.name === term.before.name
		&& pair?.slug === term.before.slug
	) {
		return 'before';
	}
	if (
		pair?.name === term.after.name
		&& pair?.slug === term.after.slug
	) {
		return 'after';
	}
	return 'drift';
}

export function expectedAuthorTerms() {
	return structuredClone(expectedTerms);
}

export function expectedAuthorTermEnvironments() {
	return structuredClone(expectedEnvironmentPolicy);
}
