#!/usr/bin/env node

import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const toolsDir = path.dirname(fileURLToPath(import.meta.url));
const engine = path.join(toolsDir, 'akibara-ai-discoverability-audit.mjs');
const wrapper = path.join(toolsDir, 'akibara-ai-discoverability-audit.sh');
const workDir = await fs.mkdtemp(path.join(os.tmpdir(), 'akibara-ai-audit-test-'));
const productionCanonicalOrigin = 'https://akibara.cl';

let origin = '';
let externalOrigin = '';
let canonicalOrigin = productionCanonicalOrigin;
let unsafeBusinessFixture = false;
let iriLocationFixture = false;
let contradictoryAboutFixture = false;
let leakingContactFixture = false;
let shellPhysicalLeakFixture = null;
let aboutSignalsInFooterOnlyFixture = false;
let jsonLdStringLeakFixture = false;
let physicalSubtypeFixture = false;
let competingOrganizationPageFixture = null;
let omitOrganizationPageFixture = null;
let organizationOnlyPageFixture = null;
let negatedStorefrontFixture = false;
let locationIconFixture = false;
let alternativeMapFixture = null;
let unsafeTermsFixture = false;
let redirectMangaFixture = false;
const observedRequestPaths = [];
const externalRequestPaths = [];
const externalServer = http.createServer((request, response) => {
	externalRequestPaths.push(request.url ?? '/');
	response.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
	response.end('<!doctype html><title>External canonical fixture</title>');
});
const server = http.createServer((request, response) => {
	const userAgent = String(request.headers['user-agent'] ?? '');
	const url = new URL(request.url ?? '/', origin);
	observedRequestPaths.push(url.pathname);
	if (redirectMangaFixture && url.pathname === '/manga/') {
		response.writeHead(302, {
			'Content-Type': 'text/plain',
			Location: `${canonicalOrigin}/manga/`,
		});
		response.end('redirect fixture');
		return;
	}

	if (url.pathname === '/sitemap_index.xml' && userAgent.includes('GPTBot')) {
		response.writeHead(429, { 'Content-Type': 'text/plain' });
		response.end('rate limited fixture');
		return;
	}

	if (url.pathname === '/robots.txt') {
		response.writeHead(200, { 'Content-Type': 'text/plain' });
		response.end([
			'User-agent: *',
			'Content-Signal: search=yes, ai-input=yes, ai-train=yes',
			'Allow: /',
			`Sitemap: ${canonicalOrigin}/sitemap_index.xml`,
		].join('\n'));
		return;
	}

	if (url.pathname === '/llms.txt') {
		response.writeHead(200, { 'Content-Type': 'text/plain' });
		response.end(`# Fixture\n\n- [Manga](${canonicalOrigin}/manga/)`);
		return;
	}

	if (url.pathname === '/sitemap_index.xml') {
		response.writeHead(200, { 'Content-Type': 'application/xml' });
		response.end(
			`<?xml version="1.0"?><sitemapindex><sitemap><loc>${canonicalOrigin}/fixture-sitemap.xml</loc></sitemap></sitemapindex>`
		);
		return;
	}

	if (url.pathname === '/fixture-sitemap.xml') {
		response.writeHead(200, { 'Content-Type': 'application/xml' });
		response.end(
			`<?xml version="1.0"?><urlset><url><loc>${canonicalOrigin}/manga/</loc></url></urlset>`
		);
		return;
	}

	const isManga = url.pathname === '/manga/';
	const isAbout = url.pathname === '/nosotros/';
	const isContact = url.pathname === '/contacto/';
	const isTerms = url.pathname === '/terminos-y-condiciones/';
	const pageLabel = url.pathname === '/'
		? 'home'
		: (isManga
			? 'manga'
			: (isAbout
				? 'about'
				: (isContact
					? 'contact'
					: (url.pathname === '/faq/' ? 'faq' : (isTerms ? 'terms' : 'other')))));
	const merchant = {
		'@context': iriLocationFixture ? {
			s: 'https://schema.org/',
			kind: '@type',
			shop: 's:OnlineStore',
			org: 's:Organization',
			privateLocation: 's:legalAddress',
			postal: 's:PostalAddress',
			street: 's:streetAddress',
		} : 'https://schema.org',
		...(iriLocationFixture ? {
			kind: ['shop', 'org'],
		} : {
			'@type': [
				'OnlineStore',
				'Organization',
				...(unsafeBusinessFixture ? ['LocalBusiness'] : []),
				...(physicalSubtypeFixture ? ['Dentist'] : []),
			],
		}),
		'@id': `${canonicalOrigin}/#organization`,
		hasMerchantReturnPolicy: {
			'@type': 'MerchantReturnPolicy',
			merchantReturnDays: 10,
			applicableCountry: 'CL',
			returnPolicyCountry: 'CL',
			returnPolicyCategory: 'https://schema.org/MerchantReturnFiniteReturnWindow',
			merchantReturnLink: jsonLdStringLeakFixture
				? 'https://maps.google.com/?q=policy-confidential-fixture'
				: `${canonicalOrigin}/devoluciones/`,
			returnMethod: 'https://schema.org/ReturnByMail',
			refundType: 'https://schema.org/FullRefund',
			returnFees: 'https://schema.org/ReturnFeesCustomerResponsibility',
			customerRemorseReturnFees: 'https://schema.org/ReturnFeesCustomerResponsibility',
			itemDefectReturnFees: 'https://schema.org/FreeReturn',
			itemCondition: [
				'https://schema.org/NewCondition',
				'https://schema.org/DamagedCondition',
			],
		},
		...(unsafeBusinessFixture ? {
			address: {
				'@type': 'PostalAddress',
				streetAddress: 'Private fixture street 123',
				addressLocality: 'Fixture locality',
				postalCode: '0000000',
				addressCountry: 'CL',
			},
			geo: {
				'@type': 'GeoCoordinates',
				latitude: -1,
				longitude: -1,
			},
			location: {
				'@type': 'Place',
				name: 'Private fixture pickup',
			},
			hasMap: 'https://example.invalid/private-fixture',
			openingHours: 'Mo-Fr 09:00-18:00',
			openingHoursSpecification: {
				'@type': 'OpeningHoursSpecification',
				dayOfWeek: 'https://schema.org/Monday',
			},
		} : {}),
		...(iriLocationFixture ? {
			privateLocation: {
				kind: 'postal',
				street: 'IRI private fixture 9876',
			},
		} : {}),
		...(jsonLdStringLeakFixture ? {
			description: 'Visítanos en nuestro local de Avenida Confidential 4242, San Miguel. Mapa: https://maps.google.com/?q=confidential-fixture',
		} : {}),
	};
	const safeTermsBody = 'Akibara SpA. RUT: 76.000.000-0. Representante legal: Persona Fixture. Domicilio legal: Avenida Legal Fixture 123, San Miguel. Este domicilio se publica sólo para identificar legalmente al vendedor; no es un local físico, sucursal ni punto de retiro. Contacto: contacto@example.test.';
	const unsafeTermsBody = 'Akibara SpA. RUT: 76.000.000-0. Representante legal: Persona Fixture. Domicilio tributario ante el SII: Avenida Terms Confidential 9191, San Miguel. Visítanos en nuestro local. Mapa: https://maps.apple.com/?q=terms-confidential. Contacto: contacto@example.test.';
	const visibleBody = isTerms
		? (unsafeTermsFixture ? unsafeTermsBody : safeTermsBody)
		: (isManga
		? 'Manga fixture'
		: (isContact && leakingContactFixture
			? 'Visítanos en nuestro local: Avenida Fixture 555, San Miguel. Retiro presencial.'
			: (isAbout && contradictoryAboutFixture
			? 'Akibara es una tienda 100 % online. No contamos con local físico ni atención espontánea. Ofrecemos retiro coordinado en Providencia. El día y la hora se acuerdan por correo al confirmar el pedido. Visítanos en nuestro local: Fixture Private 1234, San Miguel.'
			: (isAbout && aboutSignalsInFooterOnlyFixture
				? 'Historia de Akibara sin información sobre el modelo de atención.'
				: (isAbout && negatedStorefrontFixture
				? 'Akibara es una tienda 100 % online, sin local abierto al público ni atención espontánea. Ofrecemos retiro coordinado en San Miguel. El día y la hora se acuerdan por correo al confirmar el pedido.'
			: (isAbout && !unsafeBusinessFixture
				? 'Akibara es una tienda 100 % online. No contamos con local físico ni atención espontánea. Ofrecemos retiro coordinado en San Miguel. El día y la hora se acuerdan por correo al confirmar el pedido.'
				: (isAbout ? 'Visítanos en nuestro local sin coordinación.' : 'Home fixture')))))));
	const competingOrganization = {
		'@type': 'Organization',
		'@id': `${canonicalOrigin}/#competing-organization`,
		name: 'Competing fixture organization',
	};
	const hasCompetingOrganization = unsafeBusinessFixture
		|| competingOrganizationPageFixture === pageLabel;
	const pageOrganization = organizationOnlyPageFixture === pageLabel
		? { ...merchant, '@type': 'Organization' }
		: merchant;
	const schemaDocument = omitOrganizationPageFixture === pageLabel
		? {
			'@context': 'https://schema.org',
			'@type': 'WebPage',
			'@id': `${canonicalOrigin}${url.pathname}#webpage`,
		}
		: (hasCompetingOrganization ? {
			'@context': 'https://schema.org',
			'@graph': [
				pageOrganization,
				unsafeBusinessFixture
					? {
						'@type': 'Corporation',
						'@id': `${canonicalOrigin}/#extra-organization`,
						name: 'Extra fixture corporation',
					}
					: competingOrganization,
			],
		} : pageOrganization);
	const physicalShellCopy = 'Visítanos en nuestro local de Avenida Shell Confidential 7373, San Miguel. Retiro presencial. Mapa: https://maps.google.com/?q=shell-confidential';
	const safeFooterCopy = 'Akibara es una tienda 100 % online. No contamos con local físico ni atención espontánea. Ofrecemos retiro coordinado en San Miguel. El día y la hora se acuerdan por correo al confirmar el pedido.';
	const locationIconMarkup = locationIconFixture
		? '<svg data-akibara-icon="map-pin" aria-hidden="true"><path d="M0 0"/></svg><span>San Miguel</span>'
		: '';
	const alternativeMapMarkup = alternativeMapFixture
		? `<a href="${alternativeMapFixture}">Mapa de retiro</a>`
		: '';
	response.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
	response.end(`<!doctype html>
<html><head>
<title>${isManga ? 'Fixture Manga' : 'Fixture Home'}</title>
${isManga ? '<meta name="description" content="Fixture manga description">' : ''}
${isManga ? `<link rel="canonical" href="${canonicalOrigin}/manga/">` : ''}
<script type="application/ld+json">${JSON.stringify(schemaDocument)}</script>
</head><body><header>${shellPhysicalLeakFixture === 'header' ? physicalShellCopy : ''}</header><main>${visibleBody}</main><footer>${safeFooterCopy}${shellPhysicalLeakFixture === 'footer' ? ` ${physicalShellCopy}` : ''}${locationIconMarkup}${alternativeMapMarkup}</footer></body></html>`);
});

async function runEngine(args) {
	const child = spawn(process.execPath, [engine, ...args], {
		stdio: ['ignore', 'pipe', 'pipe'],
	});

	let stdout = '';
	let stderr = '';
	child.stdout.setEncoding('utf8');
	child.stderr.setEncoding('utf8');
	child.stdout.on('data', (chunk) => { stdout += chunk; });
	child.stderr.on('data', (chunk) => { stderr += chunk; });
	const [exitCode] = await once(child, 'close');
	return { exitCode, report: JSON.parse(stdout), stderr, stdout };
}

async function runAudit({
	baseUrl = `${canonicalOrigin}/`,
	fetchBaseUrl = `${origin}/`,
	omitFetchBaseUrl = false,
} = {}) {
	const args = [
		'--work-dir', workDir,
		'--base-url', baseUrl,
	];
	if (!omitFetchBaseUrl) args.push('--fetch-base-url', fetchBaseUrl);
	args.push(
		'--expected-title', 'Fixture Manga',
		'--expected-meta', 'Fixture manga description',
		'--expected-pickup-locality', 'San Miguel',
		'--expected-ai-train', 'yes',
		'--require-return-policy',
		'--timeout-ms', '5000',
		'--concurrency', '1',
		'--max-sitemaps', '5',
		'--sitemap-ua-scope', 'primary',
	);
	return runEngine(args);
}

try {
	server.listen(0, '127.0.0.1');
	await once(server, 'listening');
	const address = server.address();
	assert.ok(address && typeof address === 'object');
	origin = `http://127.0.0.1:${address.port}`;
	externalServer.listen(0, '127.0.0.1');
	await once(externalServer, 'listening');
	const externalAddress = externalServer.address();
	assert.ok(externalAddress && typeof externalAddress === 'object');
	externalOrigin = `http://127.0.0.1:${externalAddress.port}`;

	const safeRun = await runAudit();
	assert.equal(safeRun.exitCode, 1, safeRun.stderr);
	const { report } = safeRun;
	assert.equal(report.schema_version, 3);
	assert.equal(report.base_url, `${canonicalOrigin}/`);
	assert.equal(report.fetch_base_url, `${origin}/`);
	assert.ok(observedRequestPaths.includes('/fixture-sitemap.xml'));
	assert.equal(report.configuration.business_model_contract, 'online_store_without_public_storefront_v1');
	assert.equal(report.configuration.expected_pickup_locality, 'San Miguel');
	assert.ok(!Object.hasOwn(report.configuration, 'expected_address'));
	assert.equal(
		report.checks.find((candidate) => candidate.id === 'schema.canonical_online_store')?.status,
		'pass'
	);
	assert.equal(
		report.checks.find((candidate) => candidate.id === 'schema.online_store_without_physical_location')?.status,
		'pass'
	);
	for (const checkId of [
		'robots.sitemap_declared',
		'manga.canonical',
		'llms.manga_reference',
		'sitemap.children_same_origin',
		'sitemap.children_http_xml',
		'sitemap.manga_listed',
	]) {
		assert.equal(
			report.checks.find((candidate) => candidate.id === checkId)?.status,
			'pass',
			`${checkId} must use the canonical base while fetching locally`
		);
	}
	const aboutTruthCheck = report.checks.find(
		(candidate) => candidate.id === 'content.about_online_store_truth'
	);
	assert.equal(aboutTruthCheck?.status, 'pass');
	assert.ok(aboutTruthCheck.evidence.observed.every((result) =>
		aboutTruthCheck.evidence.required_signals.every((signal) => result[signal] === true)
	));
	assert.equal(
		report.checks.find((candidate) => candidate.id === 'schema.merchant_return_policy')?.status,
		'pass'
	);
	assert.equal(
		report.checks.find((candidate) => candidate.id === 'content.public_surfaces_without_physical_location')?.status,
		'pass'
	);
	const termsIdentityCheck = report.checks.find(
		(candidate) => candidate.id === 'content.terms_seller_legal_identity'
	);
	assert.equal(termsIdentityCheck?.status, 'pass');
	assert.ok(termsIdentityCheck.evidence.observed.every((result) =>
		termsIdentityCheck.evidence.required_signals.every((signal) => result[signal] === true)
	));
	assert.ok(!safeRun.stdout.includes('Avenida Legal Fixture 123'));
	const check = report.checks.find(
		(candidate) => candidate.id === 'http.critical_resources_all_user_agents'
	);
	assert.ok(check, 'missing critical resource check');
	assert.deepEqual(check.evidence.failures, [{
		label: 'sitemap_index',
		user_agent: 'gptbot',
		request_url: `${origin}/sitemap_index.xml`,
		final_url: `${origin}/sitemap_index.xml`,
		status: 429,
		redirected: false,
		content_type: 'text/plain',
		content_signal: null,
		request_accept: 'text/html,application/xhtml+xml,application/xml,text/plain;q=0.9,*/*;q=0.8',
		vary: null,
		x_robots_tag: null,
		cf_cache_status: null,
		body_sha256: crypto.createHash('sha256').update('rate limited fixture').digest('hex'),
		error: null,
	}]);

	canonicalOrigin = externalOrigin;
	redirectMangaFixture = true;
	externalRequestPaths.length = 0;
	const redirectRun = await runAudit({ baseUrl: `${externalOrigin}/` });
	assert.equal(redirectRun.exitCode, 1, redirectRun.stderr);
	assert.equal(externalRequestPaths.length, 0, 'the external canonical destination must never be fetched');
	const redirectedCritical = redirectRun.report.checks.find(
		(candidate) => candidate.id === 'http.critical_resources_direct'
	);
	assert.equal(redirectedCritical?.status, 'fail');
	assert.equal(redirectedCritical.evidence.redirected.length, 12);
	assert.ok(redirectedCritical.evidence.redirected.every((response) =>
		response.label === 'manga'
		&& response.status === 302
		&& response.redirected === true
		&& response.request_url === `${origin}/manga/`
		&& response.final_url === `${origin}/manga/`
	));
	const redirectFailures = redirectRun.report.checks.find(
		(candidate) => candidate.id === 'http.critical_resources_all_user_agents'
	)?.evidence.failures.filter((response) => response.label === 'manga');
	assert.equal(redirectFailures?.length, 12);
	assert.ok(redirectFailures.every((response) => response.status === 302 && response.redirected === true));
	const markdownRedirectFailures = redirectRun.report.checks.find(
		(candidate) => candidate.id === 'representation.markdown_for_agents'
	);
	assert.equal(markdownRedirectFailures?.status, 'fail');
	assert.equal(markdownRedirectFailures.evidence.failures.length, 1);
	assert.ok(markdownRedirectFailures.evidence.failures.every((response) =>
		response.label === 'manga_markdown'
		&& response.status === 302
		&& response.redirected === true
		&& response.request_url === `${origin}/manga/`
	));
	redirectMangaFixture = false;
	canonicalOrigin = productionCanonicalOrigin;

	aboutSignalsInFooterOnlyFixture = true;
	const footerOnlyTruthRun = await runAudit();
	assert.equal(footerOnlyTruthRun.exitCode, 1, footerOnlyTruthRun.stderr);
	assert.equal(
		footerOnlyTruthRun.report.checks.find(
			(candidate) => candidate.id === 'content.about_online_store_truth'
		)?.status,
		'fail'
	);
	aboutSignalsInFooterOnlyFixture = false;

	negatedStorefrontFixture = true;
	const negatedStorefrontRun = await runAudit();
	assert.equal(negatedStorefrontRun.exitCode, 1, negatedStorefrontRun.stderr);
	const negatedAboutCheck = negatedStorefrontRun.report.checks.find(
		(candidate) => candidate.id === 'content.about_online_store_truth'
	);
	assert.equal(negatedAboutCheck?.status, 'pass');
	assert.ok(negatedAboutCheck.evidence.observed.every((result) =>
		result.no_physical_storefront === true
		&& result.physical_storefront_claim_absent === true
	));
	assert.equal(
		negatedStorefrontRun.report.checks.find(
			(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
		)?.status,
		'pass'
	);
	negatedStorefrontFixture = false;

	for (const shellPart of ['header', 'footer']) {
		shellPhysicalLeakFixture = shellPart;
		const shellLeakRun = await runAudit();
		assert.equal(shellLeakRun.exitCode, 1, shellLeakRun.stderr);
		const shellLeakCheck = shellLeakRun.report.checks.find(
			(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
		);
		assert.equal(shellLeakCheck?.status, 'fail', `physical leak in ${shellPart} was not detected`);
		assert.ok(shellLeakCheck.evidence.observed.every((result) =>
			result.physical_storefront_claim_absent === false
			&& result.public_address_absent === false
			&& result.public_map_link_absent === false
		));
		assert.ok(!shellLeakRun.stdout.includes('Avenida Shell Confidential 7373'));
		assert.ok(!shellLeakRun.stdout.includes('shell-confidential'));
	}
	shellPhysicalLeakFixture = null;

	locationIconFixture = true;
	const locationIconRun = await runAudit();
	assert.equal(locationIconRun.exitCode, 1, locationIconRun.stderr);
	const locationIconCheck = locationIconRun.report.checks.find(
		(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
	);
	assert.equal(locationIconCheck?.status, 'fail');
	assert.ok(locationIconCheck.evidence.observed.every((result) =>
		result.public_location_icon_absent === false
		&& result.public_address_absent === true
		&& result.public_map_link_absent === true
	));
	locationIconFixture = false;

	for (const mapUrl of [
		'https://maps.apple.com/?q=fixture-map',
		'https://www.waze.com/live-map/directions?to=fixture-map',
		'https://www.bing.com/maps?q=fixture-map',
		'https://www.openstreetmap.org/?mlat=0&mlon=0',
	]) {
		alternativeMapFixture = mapUrl;
		const alternativeMapRun = await runAudit();
		assert.equal(alternativeMapRun.exitCode, 1, alternativeMapRun.stderr);
		const alternativeMapCheck = alternativeMapRun.report.checks.find(
			(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
		);
		assert.equal(alternativeMapCheck?.status, 'fail');
		assert.ok(alternativeMapCheck.evidence.observed.every((result) =>
			result.public_map_link_absent === false
		));
		assert.ok(!alternativeMapRun.stdout.includes(mapUrl));
	}
	alternativeMapFixture = null;

	jsonLdStringLeakFixture = true;
	const jsonLdStringLeakRun = await runAudit();
	assert.equal(jsonLdStringLeakRun.exitCode, 1, jsonLdStringLeakRun.stderr);
	const jsonLdStringLeakCheck = jsonLdStringLeakRun.report.checks.find(
		(candidate) => candidate.id === 'schema.online_store_without_physical_location'
	);
	assert.equal(jsonLdStringLeakCheck?.status, 'fail');
	const stringSignals = new Set(
		jsonLdStringLeakCheck.evidence.observed.flatMap(({ violations }) =>
			violations.flatMap(({ forbiddenStringSignals = [] }) =>
				forbiddenStringSignals.flatMap(({ signals }) => signals)
			)
		)
	);
	for (const signal of ['physical_storefront_claim', 'public_address', 'public_map_reference']) {
		assert.ok(stringSignals.has(signal), `missing JSON-LD string signal ${signal}`);
	}
	assert.ok(!jsonLdStringLeakRun.stdout.includes('Avenida Confidential 4242'));
	assert.ok(!jsonLdStringLeakRun.stdout.includes('confidential-fixture'));
	assert.ok(!jsonLdStringLeakRun.stdout.includes('policy-confidential-fixture'));
	jsonLdStringLeakFixture = false;

	physicalSubtypeFixture = true;
	const physicalSubtypeRun = await runAudit();
	assert.equal(physicalSubtypeRun.exitCode, 1, physicalSubtypeRun.stderr);
	const physicalSubtypeCheck = physicalSubtypeRun.report.checks.find(
		(candidate) => candidate.id === 'schema.online_store_without_physical_location'
	);
	assert.equal(physicalSubtypeCheck?.status, 'fail');
	assert.ok(physicalSubtypeCheck.evidence.observed.every(({ violations }) =>
		violations.some(({ forbiddenTypes }) => forbiddenTypes.includes('Dentist'))
	));
	assert.equal(
		physicalSubtypeRun.report.checks.find(
			(candidate) => candidate.id === 'schema.merchant_return_policy'
		)?.status,
		'pass'
	);
	physicalSubtypeFixture = false;

	competingOrganizationPageFixture = 'manga';
	const competingSurfaceOrganizationRun = await runAudit();
	assert.equal(competingSurfaceOrganizationRun.exitCode, 1, competingSurfaceOrganizationRun.stderr);
	const competingSurfaceCanonicalCheck = competingSurfaceOrganizationRun.report.checks.find(
		(candidate) => candidate.id === 'schema.canonical_online_store'
	);
	assert.equal(competingSurfaceCanonicalCheck?.status, 'fail');
	assert.ok(competingSurfaceCanonicalCheck.evidence.observed.some((result) =>
		result.page === 'manga'
		&& result.organization_count === 2
		&& result.canonical_count === 1
	));
	competingOrganizationPageFixture = null;

	omitOrganizationPageFixture = 'faq';
	const optionalSurfaceOrganizationRun = await runAudit();
	assert.equal(optionalSurfaceOrganizationRun.exitCode, 1, optionalSurfaceOrganizationRun.stderr);
	assert.equal(
		optionalSurfaceOrganizationRun.report.checks.find(
			(candidate) => candidate.id === 'schema.canonical_online_store'
		)?.status,
		'pass'
	);
	omitOrganizationPageFixture = null;

	organizationOnlyPageFixture = 'manga';
	const reusedOrganizationOnlyRun = await runAudit();
	assert.equal(reusedOrganizationOnlyRun.exitCode, 1, reusedOrganizationOnlyRun.stderr);
	const reusedOrganizationOnlyCheck = reusedOrganizationOnlyRun.report.checks.find(
		(candidate) => candidate.id === 'schema.canonical_online_store'
	);
	assert.equal(reusedOrganizationOnlyCheck?.status, 'pass');
	assert.ok(reusedOrganizationOnlyCheck.evidence.observed.some((result) =>
		result.page === 'manga'
		&& result.organization_count === 1
		&& result.reused_id_count === 1
		&& result.canonical_count === 0
	));
	organizationOnlyPageFixture = null;

	unsafeBusinessFixture = true;
	const unsafeRun = await runAudit();
	assert.equal(unsafeRun.exitCode, 1, unsafeRun.stderr);
	const locationCheck = unsafeRun.report.checks.find(
		(candidate) => candidate.id === 'schema.online_store_without_physical_location'
	);
	assert.equal(locationCheck?.status, 'fail');
	assert.ok(locationCheck.evidence.observed.every(({ violations }) => violations.length >= 3));
	const observedForbiddenProperties = new Set(
		locationCheck.evidence.observed.flatMap(({ violations }) =>
			violations.flatMap(({ forbiddenProperties }) => forbiddenProperties)
		)
	);
	for (const property of [
		'address',
		'location',
		'geo',
		'hasMap',
		'openingHours',
		'openingHoursSpecification',
	]) {
		assert.ok(observedForbiddenProperties.has(property), `missing forbidden property ${property}`);
	}
	const canonicalCheck = unsafeRun.report.checks.find(
		(candidate) => candidate.id === 'schema.canonical_online_store'
	);
	assert.equal(canonicalCheck?.status, 'fail');
	assert.ok(canonicalCheck.evidence.observed.every((result) =>
		result.organization_count === 2 && result.canonical_count === 1
	));
	assert.equal(
		unsafeRun.report.checks.find((candidate) => candidate.id === 'content.about_online_store_truth')?.status,
		'fail'
	);
	assert.equal(
		unsafeRun.report.checks.find((candidate) => candidate.id === 'schema.merchant_return_policy')?.status,
		'pass'
	);
	assert.ok(!unsafeRun.stdout.includes('Private fixture street 123'));
	assert.ok(!unsafeRun.stdout.includes('0000000'));

	unsafeBusinessFixture = false;
	iriLocationFixture = true;
	const iriRun = await runAudit();
	assert.equal(iriRun.exitCode, 1, iriRun.stderr);
	assert.equal(
		iriRun.report.checks.find((candidate) => candidate.id === 'schema.canonical_online_store')?.status,
		'pass'
	);
	const iriLocationCheck = iriRun.report.checks.find(
		(candidate) => candidate.id === 'schema.online_store_without_physical_location'
	);
	assert.equal(iriLocationCheck?.status, 'fail');
	const iriForbiddenProperties = new Set(
		iriLocationCheck.evidence.observed.flatMap(({ violations }) =>
			violations.flatMap(({ forbiddenProperties }) => forbiddenProperties)
		)
	);
	assert.ok(iriForbiddenProperties.has('legalAddress'));
	assert.ok(iriForbiddenProperties.has('streetAddress'));
	assert.ok(iriLocationCheck.evidence.observed.every(({ violations }) =>
		violations.some(({ forbiddenTypes }) => forbiddenTypes.includes('PostalAddress'))
	));
	assert.ok(!iriRun.stdout.includes('IRI private fixture 9876'));

	iriLocationFixture = false;
	contradictoryAboutFixture = true;
	const contradictoryRun = await runAudit();
	assert.equal(contradictoryRun.exitCode, 1, contradictoryRun.stderr);
	const contradictoryAboutCheck = contradictoryRun.report.checks.find(
		(candidate) => candidate.id === 'content.about_online_store_truth'
	);
	assert.equal(contradictoryAboutCheck?.status, 'fail');
	assert.ok(contradictoryAboutCheck.evidence.observed.every((result) =>
		result.pickup_locality === false
		&& result.physical_storefront_claim_absent === false
		&& result.public_address_absent === false
	));
	assert.equal(
		contradictoryRun.report.checks.find(
			(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
		)?.status,
		'fail'
	);
	assert.ok(!contradictoryRun.stdout.includes('Fixture Private 1234'));

	contradictoryAboutFixture = false;
	leakingContactFixture = true;
	const contactLeakRun = await runAudit();
	assert.equal(contactLeakRun.exitCode, 1, contactLeakRun.stderr);
	const contactLeakCheck = contactLeakRun.report.checks.find(
		(candidate) => candidate.id === 'content.public_surfaces_without_physical_location'
	);
	assert.equal(contactLeakCheck?.status, 'fail');
	assert.ok(contactLeakCheck.evidence.observed.every((result) =>
		result.page === 'contact'
		&& result.physical_storefront_claim_absent === false
		&& result.public_address_absent === false
	));
	assert.ok(!contactLeakRun.stdout.includes('Avenida Fixture 555'));
	leakingContactFixture = false;

	unsafeTermsFixture = true;
	const unsafeTermsRun = await runAudit();
	assert.equal(unsafeTermsRun.exitCode, 1, unsafeTermsRun.stderr);
	const unsafeTermsCheck = unsafeTermsRun.report.checks.find(
		(candidate) => candidate.id === 'content.terms_seller_legal_identity'
	);
	assert.equal(unsafeTermsCheck?.status, 'fail');
	assert.ok(unsafeTermsCheck.evidence.observed.every((result) =>
		result.legal_domicile_label_present === false
		&& result.tax_domicile_only_absent === false
		&& result.legal_domicile_not_storefront === false
		&& result.physical_storefront_claim_absent === false
		&& result.public_map_link_absent === false
	));
	assert.ok(!unsafeTermsRun.stdout.includes('Avenida Terms Confidential 9191'));
	assert.ok(!unsafeTermsRun.stdout.includes('terms-confidential'));
	unsafeTermsFixture = false;

	const backwardsCompatibleRun = await runAudit({
		baseUrl: `${origin}/`,
		omitFetchBaseUrl: true,
	});
	assert.equal(backwardsCompatibleRun.report.base_url, `${origin}/`);
	assert.equal(backwardsCompatibleRun.report.fetch_base_url, `${origin}/`);
	assert.equal(
		backwardsCompatibleRun.report.checks.find(
			(candidate) => candidate.id === 'http.critical_resources_all_user_agents'
		)?.evidence.failures[0]?.request_url,
		`${origin}/sitemap_index.xml`
	);

	for (const [flag, value, expectedMessage] of [
		['--base-url', 'ftp://example.test/', '--base-url debe usar http o https'],
		['--fetch-base-url', 'ftp://example.test/', '--fetch-base-url debe usar http o https'],
		['--base-url', 'https://user:secret@example.test/', '--base-url no puede incluir credenciales'],
		['--fetch-base-url', 'https://user:secret@example.test/', '--fetch-base-url no puede incluir credenciales'],
	]) {
		const invalidUrlRun = await runEngine([
			'--work-dir', workDir,
			flag, value,
		]);
		assert.equal(invalidUrlRun.exitCode, 1);
		assert.equal(invalidUrlRun.report.checks[0]?.id, 'tool.execution');
		assert.equal(invalidUrlRun.report.checks[0]?.message, expectedMessage);
		assert.ok(!invalidUrlRun.stdout.includes('user:secret'));
	}

	const engineSource = await fs.readFile(engine, 'utf8');
	assert.ok(!engineSource.includes('--expected-street'));
	assert.ok(!engineSource.includes('--expected-postal'));
	const wrapperSource = await fs.readFile(wrapper, 'utf8');
	assert.ok(!wrapperSource.includes('"schema_version":2'));
	assert.ok(wrapperSource.includes('"schema_version":3'));

	process.stdout.write('PASS: ai discoverability auditor enforces online-store truth and identifies failing user agent\n');
} finally {
	server.close();
	externalServer.close();
	await fs.rm(workDir, { recursive: true, force: true });
}
