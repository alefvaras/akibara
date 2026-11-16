#!/usr/bin/env node

/**
 * Auditor HTTP estrictamente read-only para la descubribilidad de Akibara en
 * asistentes IA. No lee credenciales, no usa cookies y todas las solicitudes
 * declaran explicitamente method: GET.
 */

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import {
	parseContentSignal,
	parseRobots,
	robotsAllows,
	robotsContentSignal,
} from './lib/ai-discoverability-policy.mjs';

const TOOL = 'akibara-ai-discoverability-audit';
const SCHEMA_VERSION = 3;

const FORBIDDEN_LOCATION_PROPERTIES = new Set([
	'address',
	'legalAddress',
	'streetAddress',
	'postOfficeBoxNumber',
	'addressLocality',
	'addressRegion',
	'postalCode',
	'addressCountry',
	'location',
	'geo',
	'hasMap',
	'hasPOS',
	'openingHours',
	'openingHoursSpecification',
]);

const FORBIDDEN_LOCATION_TYPES = new Set([
	'Place',
	'PostalAddress',
	'GeoCoordinates',
	'GeoShape',
	'OpeningHoursSpecification',
	'LocalBusiness',
	'Store',
	'BookStore',
	'Accommodation',
	'Apartment',
	'ApartmentComplex',
	'House',
	'Residence',
	'SingleFamilyResidence',
	'AdministrativeArea',
	'City',
	'State',
	'CivicStructure',
	'Landform',
	'LandmarksOrHistoricalBuildings',
	'TouristAttraction',
	'TouristDestination',
	// Descendientes de LocalBusiness en el vocabulario oficial de Schema.org.
	// La lista estatica evita depender de red durante una auditoria y el fallback
	// por sufijo de isForbiddenLocationType cubre nombres futuros inequívocos.
	'AccountingService',
	'AdultEntertainment',
	'AmusementPark',
	'AnimalShelter',
	'ArchiveOrganization',
	'ArtGallery',
	'Attorney',
	'AutoBodyShop',
	'AutoDealer',
	'AutoPartsStore',
	'AutoRental',
	'AutoRepair',
	'AutoWash',
	'AutomatedTeller',
	'AutomotiveBusiness',
	'Bakery',
	'BankOrCreditUnion',
	'BarOrPub',
	'BeautySalon',
	'BedAndBreakfast',
	'BikeStore',
	'BowlingAlley',
	'Brewery',
	'CafeOrCoffeeShop',
	'Campground',
	'Casino',
	'ChildCare',
	'ClothingStore',
	'ComedyClub',
	'CommunityHealth',
	'ComputerStore',
	'ConvenienceStore',
	'CovidTestingFacility',
	'DaySpa',
	'Dentist',
	'DepartmentStore',
	'Dermatology',
	'DietNutrition',
	'Distillery',
	'DryCleaningOrLaundry',
	'Electrician',
	'ElectronicsStore',
	'Emergency',
	'EmergencyService',
	'EmploymentAgency',
	'EntertainmentBusiness',
	'ExerciseGym',
	'FastFoodRestaurant',
	'FinancialService',
	'FireStation',
	'Florist',
	'FoodEstablishment',
	'FurnitureStore',
	'GardenStore',
	'GasStation',
	'GeneralContractor',
	'Geriatric',
	'GolfCourse',
	'GovernmentOffice',
	'GroceryStore',
	'Gynecologic',
	'HVACBusiness',
	'HairSalon',
	'HardwareStore',
	'HealthAndBeautyBusiness',
	'HealthClub',
	'HobbyShop',
	'HomeAndConstructionBusiness',
	'HomeGoodsStore',
	'Hospital',
	'Hostel',
	'Hotel',
	'HousePainter',
	'IceCreamShop',
	'IndividualPhysician',
	'InsuranceAgency',
	'InternetCafe',
	'JewelryStore',
	'LegalService',
	'Library',
	'LiquorStore',
	'Locksmith',
	'LodgingBusiness',
	'MedicalBusiness',
	'MedicalClinic',
	'MensClothingStore',
	'Midwifery',
	'MobilePhoneStore',
	'Motel',
	'MotorcycleDealer',
	'MotorcycleRepair',
	'MovieRentalStore',
	'MovieTheater',
	'MovingCompany',
	'MusicStore',
	'NailSalon',
	'NightClub',
	'Notary',
	'Nursing',
	'Obstetric',
	'OfficeEquipmentStore',
	'Oncologic',
	'Optician',
	'Optometric',
	'Otolaryngologic',
	'OutletStore',
	'PawnShop',
	'Pediatric',
	'PetStore',
	'Pharmacy',
	'Physician',
	'PhysiciansOffice',
	'Physiotherapy',
	'PlasticSurgery',
	'Plumber',
	'Podiatric',
	'PoliceStation',
	'PostOffice',
	'PrimaryCare',
	'ProfessionalService',
	'Psychiatric',
	'PublicHealth',
	'PublicSwimmingPool',
	'RadioStation',
	'RealEstateAgent',
	'RecyclingCenter',
	'Resort',
	'Restaurant',
	'RoofingContractor',
	'SelfStorage',
	'ShoeStore',
	'ShoppingCenter',
	'SkiResort',
	'SportingGoodsStore',
	'SportsActivityLocation',
	'SportsClub',
	'StadiumOrArena',
	'TattooParlor',
	'TelevisionStation',
	'TennisComplex',
	'TireShop',
	'TouristInformationCenter',
	'ToyStore',
	'TravelAgency',
	'VacationRental',
	'WholesaleStore',
	'Winery',
]);

const FORBIDDEN_LOCATION_TYPE_SUFFIXES = [
	'LocalBusiness',
	'Store',
	'Restaurant',
	'Salon',
	'Station',
	'Clinic',
	'Hospital',
	'Hotel',
	'Motel',
	'Hostel',
	'Resort',
];

const NON_ORGANIZATION_LOCATION_TYPES = new Set([
	'Place',
	'PostalAddress',
	'GeoCoordinates',
	'GeoShape',
	'OpeningHoursSpecification',
	'Accommodation',
	'Apartment',
	'ApartmentComplex',
	'House',
	'Residence',
	'SingleFamilyResidence',
	'AdministrativeArea',
	'City',
	'State',
	'CivicStructure',
	'Landform',
	'LandmarksOrHistoricalBuildings',
	'TouristAttraction',
	'TouristDestination',
]);

const PUBLIC_BUSINESS_PAGE_LABELS = ['home', 'manga', 'about', 'contact', 'faq'];

const ORGANIZATION_TYPES = new Set([
	'Organization',
	'OnlineBusiness',
	'OnlineStore',
	'LocalBusiness',
	'Airline',
	'Consortium',
	'Corporation',
	'NGO',
	'LibrarySystem',
	'PerformingGroup',
	'Project',
	'School',
	'SportsTeam',
	'WorkersUnion',
]);

const USER_AGENTS = [
	{
		id: 'oai_searchbot',
		robotToken: 'OAI-SearchBot',
		purpose: 'search',
		respectsRobots: true,
		value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36; compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot',
		officialReference: 'https://developers.openai.com/api/docs/bots',
	},
	{
		id: 'chatgpt_user',
		robotToken: 'ChatGPT-User',
		purpose: 'user_initiated_retrieval',
		respectsRobots: false,
		value: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
		officialReference: 'https://developers.openai.com/api/docs/bots',
	},
	{
		id: 'gptbot',
		robotToken: 'GPTBot',
		purpose: 'training',
		respectsRobots: true,
		value: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
		officialReference: 'https://developers.openai.com/api/docs/bots',
	},
	{
		id: 'perplexitybot',
		robotToken: 'PerplexityBot',
		purpose: 'search',
		respectsRobots: true,
		value: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
		officialReference: 'https://docs.perplexity.ai/docs/resources/perplexity-crawlers',
	},
	{
		id: 'perplexity_user',
		robotToken: 'Perplexity-User',
		purpose: 'user_initiated_retrieval',
		respectsRobots: false,
		value: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
		officialReference: 'https://docs.perplexity.ai/docs/resources/perplexity-crawlers',
	},
	{
		id: 'claudebot',
		robotToken: 'ClaudeBot',
		purpose: 'training',
		respectsRobots: true,
		value: 'ClaudeBot',
		officialReference: 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
	},
	{
		id: 'claude_searchbot',
		robotToken: 'Claude-SearchBot',
		purpose: 'search',
		respectsRobots: true,
		value: 'Claude-SearchBot',
		officialReference: 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
	},
	{
		id: 'claude_user',
		robotToken: 'Claude-User',
		purpose: 'user_initiated_retrieval',
		respectsRobots: true,
		value: 'Claude-User',
		officialReference: 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
	},
	{
		id: 'googlebot',
		robotToken: 'Googlebot',
		purpose: 'google_search',
		respectsRobots: true,
		value: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		officialReference: 'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers',
	},
	{
		id: 'googlebot_image',
		robotToken: 'Googlebot-Image',
		purpose: 'google_images',
		respectsRobots: true,
		value: 'Googlebot-Image/1.0',
		officialReference: 'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers',
	},
	{
		id: 'storebot_google',
		robotToken: 'Storebot-Google',
		purpose: 'merchant_checkout_verification',
		respectsRobots: true,
		value: 'Mozilla/5.0 (compatible; Storebot-Google/1.0; +http://www.google.com/bot.html)',
		officialReference: 'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers',
	},
	{
		id: 'bingbot',
		robotToken: 'bingbot',
		purpose: 'bing_search_and_copilot',
		respectsRobots: true,
		value: 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
		officialReference: 'https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0',
	},
];

const ROBOTS_POLICY_TOKENS = [
	...USER_AGENTS.filter(({ respectsRobots }) => respectsRobots),
	{
		id: 'google_extended',
		robotToken: 'Google-Extended',
		purpose: 'gemini_grounding_and_training_control',
		respectsRobots: true,
		policyOnly: true,
		officialReference: 'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers',
	},
];

const DEFAULTS = {
	baseUrl: 'https://akibara.cl/',
	fetchBaseUrl: null,
	expectedTitle: 'Manga en Chile | Comprar Online | Akibara',
	expectedMeta: 'Compra manga en Chile por serie, editorial y país de edición. Revisa stock, preventas, despacho a regiones y retiro coordinado en San Miguel.',
	expectedPickupLocality: 'San Miguel',
	expectedReturnDays: 10,
	requireContentSignal: false,
	expectedAiTrain: null,
	requireReturnPolicy: false,
	requireLlmsManga: false,
	strict: false,
	timeoutMs: 20_000,
	concurrency: 5,
	maxSitemaps: 200,
	sitemapUaScope: 'all',
};

function usage() {
	return `Uso:
  tools/akibara-ai-discoverability-audit.sh [opciones]

Opciones:
  --base-url URL                 Base canonica esperada (default: https://akibara.cl/)
  --fetch-base-url URL           Destino HTTP (default: igual a --base-url)
  --expected-title TEXTO         Title exacto esperado para /manga/
  --expected-meta TEXTO          Meta description exacta esperada
  --expected-pickup-locality X   Comuna esperada para retiro coordinado
  --expected-return-days N|any   Dias esperados si existe MerchantReturnPolicy
  --require-content-signal       Exige el header HTTP Content-Signal
  --expected-ai-train yes|no     Contrato explicito para ai-train en robots.txt
  --require-return-policy        La ausencia de MerchantReturnPolicy es fallo
  --require-llms-manga           La ausencia de /manga/ en llms.txt es fallo
  --strict                       Convierte cualquier advertencia en fallo
  --timeout-ms N                 Timeout por GET (1000..60000)
  --concurrency N                GET simultaneos (1..10)
  --max-sitemaps N               Limite defensivo de sitemaps hijos (1..500)
  --sitemap-ua-scope all|primary Prueba hijos con ${USER_AGENTS.length} UAs o solo OAI-SearchBot
  --help                         Muestra esta ayuda

Salida: un unico JSON por stdout. Exit codes: 0=pass, 1=fail, 2=warn.`;
}

function parseArgs(argv) {
	const args = { ...DEFAULTS, workDir: null, help: false };
	const booleanFlags = new Set([
		'--require-content-signal',
		'--require-return-policy',
		'--require-llms-manga',
		'--strict',
		'--help',
	]);
	const valueFlags = new Map([
		['--work-dir', 'workDir'],
		['--base-url', 'baseUrl'],
		['--fetch-base-url', 'fetchBaseUrl'],
		['--expected-title', 'expectedTitle'],
		['--expected-meta', 'expectedMeta'],
		['--expected-pickup-locality', 'expectedPickupLocality'],
		['--expected-return-days', 'expectedReturnDays'],
		['--expected-ai-train', 'expectedAiTrain'],
		['--timeout-ms', 'timeoutMs'],
		['--concurrency', 'concurrency'],
		['--max-sitemaps', 'maxSitemaps'],
		['--sitemap-ua-scope', 'sitemapUaScope'],
	]);

	for (let index = 2; index < argv.length; index += 1) {
		const raw = argv[index];
		const equal = raw.indexOf('=');
		const flag = equal === -1 ? raw : raw.slice(0, equal);
		const inlineValue = equal === -1 ? undefined : raw.slice(equal + 1);

		if (booleanFlags.has(flag)) {
			if (inlineValue !== undefined) throw new Error(`${flag} no acepta valor`);
			if (flag === '--require-content-signal') args.requireContentSignal = true;
			else if (flag === '--require-return-policy') args.requireReturnPolicy = true;
			else if (flag === '--require-llms-manga') args.requireLlmsManga = true;
			else if (flag === '--strict') args.strict = true;
			else args.help = true;
			continue;
		}

		const key = valueFlags.get(flag);
		if (!key) throw new Error(`Argumento no reconocido: ${flag}`);
		const value = inlineValue ?? argv[index + 1];
		if (value === undefined || value === '') throw new Error(`Falta el valor de ${flag}`);
		if (inlineValue === undefined) index += 1;
		args[key] = value;
	}

	for (const key of ['timeoutMs', 'concurrency', 'maxSitemaps']) {
		args[key] = Number(args[key]);
	}
	if (args.expectedReturnDays !== 'any') args.expectedReturnDays = Number(args.expectedReturnDays);

	if (!Number.isInteger(args.timeoutMs) || args.timeoutMs < 1_000 || args.timeoutMs > 60_000) {
		throw new Error('--timeout-ms debe ser un entero entre 1000 y 60000');
	}
	if (!Number.isInteger(args.concurrency) || args.concurrency < 1 || args.concurrency > 10) {
		throw new Error('--concurrency debe ser un entero entre 1 y 10');
	}
	if (!Number.isInteger(args.maxSitemaps) || args.maxSitemaps < 1 || args.maxSitemaps > 500) {
		throw new Error('--max-sitemaps debe ser un entero entre 1 y 500');
	}
	if (
		args.expectedReturnDays !== 'any'
		&& (!Number.isInteger(args.expectedReturnDays) || args.expectedReturnDays < 0 || args.expectedReturnDays > 365)
	) {
		throw new Error('--expected-return-days debe ser any o un entero entre 0 y 365');
	}
	if (!['all', 'primary'].includes(args.sitemapUaScope)) {
		throw new Error('--sitemap-ua-scope debe ser all o primary');
	}
	if (args.expectedAiTrain !== null && !['yes', 'no'].includes(args.expectedAiTrain)) {
		throw new Error('--expected-ai-train debe ser yes o no');
	}
	if (!args.help && !args.workDir) throw new Error('El wrapper no entrego --work-dir');

	return args;
}

function normalizedText(value) {
	return String(value ?? '').normalize('NFC').replace(/\s+/g, ' ').trim();
}

function comparableText(value) {
	return normalizedText(value).toLocaleLowerCase('es-CL');
}

function escapeRegExp(value) {
	return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function decodeEntities(value) {
	return String(value ?? '')
		.replace(/&#(\d+);/g, (_, number) => String.fromCodePoint(Number(number)))
		.replace(/&#x([\da-f]+);/gi, (_, number) => String.fromCodePoint(Number.parseInt(number, 16)))
		.replaceAll('&quot;', '"')
		.replaceAll('&#39;', "'")
		.replaceAll('&apos;', "'")
		.replaceAll('&lt;', '<')
		.replaceAll('&gt;', '>')
		.replaceAll('&amp;', '&');
}

function parseAttributes(tag) {
	const attributes = {};
	const pattern = /([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;
	for (const match of tag.matchAll(pattern)) {
		const key = match[1].toLowerCase();
		if (key === 'meta' || key === 'link' || key === 'script') continue;
		attributes[key] = decodeEntities(match[2] ?? match[3] ?? match[4] ?? '');
	}
	return attributes;
}

function parseHtmlMetadata(html) {
	const titleMatch = html.match(/<title(?:\s[^>]*)?>([\s\S]*?)<\/title>/i);
	const title = titleMatch ? normalizedText(decodeEntities(titleMatch[1].replace(/<[^>]*>/g, ''))) : null;
	const metas = Array.from(html.matchAll(/<meta\b[^>]*>/gi), (match) => parseAttributes(match[0]));
	const links = Array.from(html.matchAll(/<link\b[^>]*>/gi), (match) => parseAttributes(match[0]));
	const description = metas.find((meta) => comparableText(meta.name) === 'description')?.content ?? null;
	const canonical = links.find((link) => {
		const rels = comparableText(link.rel).split(/\s+/);
		return rels.includes('canonical');
	})?.href ?? null;
	const robotDirectives = metas
		.filter((meta) => ['robots', 'googlebot'].includes(comparableText(meta.name)))
		.flatMap((meta) => comparableText(meta.content).split(/[\s,]+/).filter(Boolean));
	return { title, description: description ? normalizedText(description) : null, canonical, robotDirectives };
}

function jsonLdDocuments(html) {
	const documents = [];
	const parseErrors = [];
	const scripts = html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi);
	for (const [index, match] of Array.from(scripts).entries()) {
		const attributes = parseAttributes(`<script ${match[1]}>`);
		if (comparableText(attributes.type) !== 'application/ld+json') continue;
		try {
			documents.push(JSON.parse(match[2].trim()));
		} catch (error) {
			const position = String(error.message ?? '').match(/\bposition\s+\d+\b/i)?.[0] ?? null;
			parseErrors.push({ script: index + 1, error: 'invalid_json', position });
		}
	}
	return { documents, parseErrors };
}

function collectJsonLdNodes(documents) {
	const nodes = [];
	const seen = new Set();
	const visit = (value) => {
		if (!value || typeof value !== 'object' || seen.has(value)) return;
		seen.add(value);
		if (Array.isArray(value)) {
			for (const item of value) visit(item);
			return;
		}
		nodes.push(value);
		for (const [property, child] of Object.entries(value)) {
			if (property !== '@context') visit(child);
		}
	};
	for (const document of documents) visit(document);
	return nodes;
}

function directSchemaTerm(value) {
	if (typeof value !== 'string') return value;
	const match = value.match(/^(?:https?:\/\/(?:www\.)?schema\.org\/|schema:)([^/?#]+)\/?$/i);
	return match ? decodeURIComponent(match[1]) : value;
}

function collectSchemaContext(documents) {
	const contexts = [];
	const seen = new Set();
	const visit = (value) => {
		if (!value || typeof value !== 'object' || seen.has(value)) return;
		seen.add(value);
		if (Array.isArray(value)) {
			for (const item of value) visit(item);
			return;
		}
		if (Object.hasOwn(value, '@context')) contexts.push(value['@context']);
		for (const child of Object.values(value)) visit(child);
	};
	for (const document of documents) visit(document);

	const aliases = new Map();
	const prefixes = new Map([['schema', 'https://schema.org/']]);
	const contextObjects = [];
	const flatten = (context) => {
		if (Array.isArray(context)) {
			for (const item of context) flatten(item);
		} else if (context && typeof context === 'object') {
			contextObjects.push(context);
		}
	};
	for (const context of contexts) flatten(context);

	for (const context of contextObjects) {
		for (const [alias, definition] of Object.entries(context)) {
			if (alias.startsWith('@')) continue;
			const id = typeof definition === 'string' ? definition : definition?.['@id'];
			if (typeof id === 'string' && /[\/#]$/u.test(id)) prefixes.set(alias, id);
		}
	}
	for (const context of contextObjects) {
		for (const [alias, definition] of Object.entries(context)) {
			if (alias.startsWith('@')) continue;
			const id = typeof definition === 'string' ? definition : definition?.['@id'];
			if (typeof id !== 'string') continue;
			const compact = id.match(/^([^:]+):(.+)$/u);
			const expanded = compact && prefixes.has(compact[1])
				? `${prefixes.get(compact[1])}${compact[2]}`
				: id;
			const term = directSchemaTerm(expanded);
			if (term !== expanded || expanded.startsWith('@')) aliases.set(alias, term);
		}
	}
	return { aliases, prefixes };
}

function schemaTerm(value, context = null) {
	if (typeof value !== 'string') return value;
	if (context?.aliases.has(value)) return context.aliases.get(value);
	const compact = value.match(/^([^:]+):(.+)$/u);
	if (compact && context?.prefixes.has(compact[1])) {
		return directSchemaTerm(`${context.prefixes.get(compact[1])}${compact[2]}`);
	}
	return directSchemaTerm(value);
}

function schemaProperty(node, expectedProperty, context) {
	if (!node || typeof node !== 'object') return undefined;
	const key = Object.keys(node).find((property) =>
		schemaTerm(property, context) === expectedProperty
	);
	return key === undefined ? undefined : node[key];
}

function typesOf(node, context) {
	const rawTypes = schemaProperty(node, '@type', context);
	return (Array.isArray(rawTypes) ? rawTypes : [rawTypes])
		.filter((value) => typeof value === 'string')
		.map((value) => schemaTerm(value, context));
}

function resolveReference(value, byId, context) {
	if (!value) return null;
	if (typeof value === 'string') return byId.get(value) ?? null;
	const referenceId = schemaProperty(value, '@id', context);
	if (typeof value === 'object' && Object.keys(value).length === 1 && referenceId) {
		return byId.get(referenceId) ?? value;
	}
	return typeof value === 'object' ? value : null;
}

function normalizedEntityId(value, baseUrl) {
	try {
		return new URL(String(value), baseUrl).href;
	} catch {
		return null;
	}
}

function isForbiddenLocationType(type) {
	if (FORBIDDEN_LOCATION_TYPES.has(type)) return true;
	if (type === 'OnlineStore') return false;
	return FORBIDDEN_LOCATION_TYPE_SUFFIXES.some((suffix) => type.endsWith(suffix));
}

function isOrganizationType(type) {
	return ORGANIZATION_TYPES.has(type)
		|| type.endsWith('Organization')
		|| type.endsWith('LocalBusiness')
		|| type.endsWith('Store')
		|| (FORBIDDEN_LOCATION_TYPES.has(type) && !NON_ORGANIZATION_LOCATION_TYPES.has(type));
}

function reportableSchemaTypes(types) {
	return [...new Set(types.filter((type) =>
		type === 'MerchantReturnPolicy'
		|| isOrganizationType(type)
		|| isForbiddenLocationType(type)
	))].sort();
}

function visiblePageText(html) {
	return normalizedText(decodeEntities(
		String(html ?? '')
			.replace(/<head\b[^>]*>[\s\S]*?<\/head>/gi, ' ')
			.replace(/<(?:script|style|template|svg)\b[^>]*>[\s\S]*?<\/(?:script|style|template|svg)>/gi, ' ')
			.replace(/<!--[\s\S]*?-->/g, ' ')
			.replace(/<[^>]+>/g, ' ')
	));
}

function mainContentHtml(html) {
	const value = String(html ?? '');
	return value.match(/<main\b[^>]*>([\s\S]*?)<\/main>/i)?.[1] ?? '';
}

function rawBodyContentHtml(html) {
	const value = String(html ?? '');
	return value.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i)?.[1] ?? value;
}

function bodyContentHtml(html) {
	return rawBodyContentHtml(html)
		.replace(/<(?:script|style|template|svg)\b[^>]*>[\s\S]*?<\/(?:script|style|template|svg)>/gi, ' ')
		.replace(/<!--[\s\S]*?-->/g, ' ');
}

function hasPublicLocationIcon(html) {
	return /<(?:svg|img|span|i)\b[^>]*(?:data-akibara-icon|data-icon|aria-label|class)\s*=\s*["'][^"']*(?:map[-_\s]?pin|location[-_\s]?pin|map[-_\s]?marker|location[-_\s]?marker)[^"']*["'][^>]*>/iu.test(
		String(html ?? '')
	);
}

function inspectPhysicalText(value, expectedPickupLocality) {
	const raw = normalizedText(decodeEntities(value));
	const text = comparableText(raw);
	const locality = comparableText(expectedPickupLocality);
	const claimText = text.replace(
		/\b(?:sin (?:un )?(?:local|tienda) abiert[oa] al p[uú]blico|no (?:hay|existe) (?:un )?(?:local|tienda) abiert[oa] al p[uú]blico|no (?:contamos|tenemos|disponemos) (?:con |de )?(?:un )?(?:local|tienda) abiert[oa] al p[uú]blico)\b/gu,
		' '
	);
	const physicalStorefrontClaim = /\b(?:vis[ií]tanos|ven a (?:nuestro|la) (?:tienda|local)|nuestro local|nuestra tienda f[ií]sica|local abierto al p[uú]blico|retiro presencial|atendemos en (?:nuestro|el) (?:local|domicilio))\b/u.test(claimText);
	const word = "[\\p{L}][\\p{L}.'’\\-]*";
	const namedAddressByLocality = locality !== '' && new RegExp(
		`\\b${word}(?:\\s+${word}){1,5}\\s+\\d{2,6}\\s*,?\\s*${escapeRegExp(locality)}\\b`,
		'u'
	).test(text);
	const typedStreetAddress = new RegExp(
		`\\b(?:calle|avenida|avda|pasaje|psje|camino|ruta)\\.?\\s+${word}(?:\\s+${word}){0,5}\\s+\\d{1,6}\\b`,
		'u'
	).test(text);
	const postalCode = /\b(?:c[oó]digo postal|c\.?p\.?)\s*:?\s*\d{4,8}\b/u.test(text);
	const publicMapReference = /(?:https?:\/\/|\/\/)?(?:www\.)?(?:maps\.google\.[a-z.]+|google\.[a-z.]+\/maps|maps\.app\.goo\.gl|goo\.gl\/maps|maps\.apple\.com|maps\.bing\.com|bing\.com\/maps|openstreetmap\.org|waze\.com\/(?:ul|live-map|directions)|waze\.to|mapquest\.com)(?:[/?#]|$)/iu.test(raw);
	return {
		physicalStorefrontClaim,
		publicAddress: namedAddressByLocality || typedStreetAddress || postalCode,
		publicMapReference,
	};
}

function inspectVisiblePhysicalLocation(html, expectedPickupLocality) {
	const rawBodyHtml = rawBodyContentHtml(html);
	const bodyHtml = bodyContentHtml(html);
	const signals = inspectPhysicalText(visiblePageText(bodyHtml), expectedPickupLocality);
	const markupSignals = inspectPhysicalText(bodyHtml, expectedPickupLocality);
	const addressElement = /<address\b/i.test(bodyHtml);
	return {
		physical_storefront_claim_absent: !signals.physicalStorefrontClaim,
		public_address_absent: !(signals.publicAddress || addressElement),
		public_map_link_absent: !(signals.publicMapReference || markupSignals.publicMapReference),
		public_location_icon_absent: !hasPublicLocationIcon(rawBodyHtml),
	};
}

function inspectTermsSellerIdentity(html, expectedPickupLocality) {
	const mainHtml = mainContentHtml(html);
	const rawText = visiblePageText(mainHtml);
	const text = comparableText(rawText);
	const physicalSignals = inspectPhysicalText(mainHtml, expectedPickupLocality);
	const hasAddressElement = /<address\b/i.test(mainHtml);
	const legalDomicileLabel = /\bdomicilio legal\b/u.test(text);
	const taxDomicileLabel = /\bdomicilio tributario\b/u.test(text);
	const noStoreRole = /\b(?:este|el) domicilio[^.!?]{0,180}\b(?:no es|no corresponde a)\b[^.!?]{0,120}\b(?:local(?: f[ií]sico)?|sucursal|punto de atenci[oó]n|punto de retiro)\b/u.test(text)
		|| /\b(?:no es|no corresponde a)\b[^.!?]{0,120}\b(?:local(?: f[ií]sico)?|sucursal|punto de atenci[oó]n|punto de retiro)\b[^.!?]{0,180}\bdomicilio\b/u.test(text);
	const placeholderAbsent = !/(?:\[(?:valor|dato|rut|domicilio|representante)[^\]]*\]|pendiente de (?:verificaci[oó]n|confirmaci[oó]n)|por confirmar|no informado)/iu.test(rawText);
	return {
		seller_identity_present: /\bakibara\s+spa\b/u.test(text) || /\braz[oó]n social\s*:\s*[\p{L}\d]/u.test(text),
		rut_present: /\b(?:rut|rol [uú]nico tributario)\s*:\s*\d{1,2}(?:\.?\d{3}){2}-[\dk]\b/u.test(text),
		legal_domicile_label_present: legalDomicileLabel,
		legal_address_present: physicalSignals.publicAddress || hasAddressElement,
		legal_representative_present: /\brepresentante legal\s*:\s*[\p{L}][\p{L}\s.'’\-]{2,120}/u.test(text),
		contact_channel_present: /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu.test(rawText)
			|| /(?:\+?56\s*)?(?:9\s*)?\d(?:[\s.-]*\d){7,8}/u.test(rawText),
		legal_domicile_not_storefront: noStoreRole,
		tax_domicile_only_absent: !(taxDomicileLabel && !legalDomicileLabel),
		placeholder_absent: placeholderAbsent,
		physical_storefront_claim_absent: !physicalSignals.physicalStorefrontClaim,
		public_map_link_absent: !physicalSignals.publicMapReference,
		public_location_icon_absent: !hasPublicLocationIcon(mainHtml),
	};
}

function inspectAboutTruth(html, expectedPickupLocality) {
	const mainHtml = mainContentHtml(html);
	const text = comparableText(visiblePageText(mainHtml));
	const locality = comparableText(expectedPickupLocality);
	const pickupPhrase = '(?:retiro(?: de pedidos)? coordinad[oa]|coordinaci[oó]n (?:previa )?del retiro|retiro solo con coordinaci[oó]n previa)';
	const onlineOnly = /\b(?:tienda|comercio) (?:100\s*%|cien por ciento|exclusivamente|solo) online\b/u.test(text);
	const noPhysicalStorefront = /\b(?:no (?:contamos|tenemos|disponemos) (?:con |de )?(?:un )?(?:local|tienda) (?:f[ií]sic[oa]|abiert[oa] al p[uú]blico)|sin (?:un )?(?:local|tienda) (?:f[ií]sic[oa]|abiert[oa] al p[uú]blico)|no (?:hay|existe) (?:un )?(?:local|tienda) (?:f[ií]sic[oa]|abiert[oa] al p[uú]blico))\b/u.test(text);
	const noWalkIn = /\b(?:(?:sin|ni) atenci[oó]n espont[aá]nea|no (?:hay|ofrecemos|realizamos|tenemos) atenci[oó]n (?:presencial )?(?:espont[aá]nea|sin coordinaci[oó]n)|no atendemos (?:al p[uú]blico )?sin coordinaci[oó]n|retiro solo con coordinaci[oó]n previa)\b/u.test(text);
	const coordinatedPickup = new RegExp(`\\b${pickupPhrase}\\b`, 'u').test(text);
	const pickupLocality = locality !== '' && new RegExp(
		`(?:\\b${pickupPhrase}\\b[^.!?]{0,120}\\b${escapeRegExp(locality)}\\b|\\b${escapeRegExp(locality)}\\b[^.!?]{0,120}\\b${pickupPhrase}\\b)`,
		'u'
	).test(text);
	// Verdad comercial 2026-08-07: el punto de retiro es público (Metro San
	// Miguel); ya no existe una dirección reservada que entregar tras confirmar.
	// La señal exigible pasó a ser la coordinación de día y hora al confirmar.
	const pickupCoordinationAfterConfirmation = /\b(?:d[ií]a y (?:la )?hora|hora y (?:el )?d[ií]a)\b[^.!?]{0,100}\b(?:se acuerdan|acordamos|se coordinan?|coordinamos|coordinando|acordando)\b/u.test(text)
		|| /\b(?:se acuerdan|acordamos|se coordinan?|coordinamos|coordinando|acordando)\b[^.!?]{0,100}\b(?:d[ií]a y (?:la )?hora|hora y (?:el )?d[ií]a)\b/u.test(text)
		|| /\bcoordinaci[oó]n previa\b/u.test(text);

	return {
		online_only: onlineOnly,
		no_physical_storefront: noPhysicalStorefront,
		no_walk_in: noWalkIn,
		coordinated_pickup: coordinatedPickup,
		pickup_locality: pickupLocality,
		pickup_coordination_after_confirmation: pickupCoordinationAfterConfirmation,
		...inspectVisiblePhysicalLocation(html, expectedPickupLocality),
	};
}

function directStringValues(value) {
	if (typeof value === 'string') return [value];
	if (!Array.isArray(value)) return [];
	return value.flatMap((item) => directStringValues(item));
}

function inspectJsonLdStringSignals(node, schemaContext, expectedPickupLocality) {
	return Object.entries(node).flatMap(([rawProperty, value]) => {
		const property = schemaTerm(rawProperty, schemaContext);
		if (property === '@context') return [];
		const signals = new Set();
		for (const stringValue of directStringValues(value)) {
			const observed = inspectPhysicalText(stringValue, expectedPickupLocality);
			if (observed.physicalStorefrontClaim) signals.add('physical_storefront_claim');
			if (observed.publicAddress) signals.add('public_address');
			if (observed.publicMapReference) signals.add('public_map_reference');
		}
		return signals.size > 0 ? [{ property, signals: [...signals].sort() }] : [];
	});
}

function inspectOrganizationSchema(
	html,
	baseUrl,
	expectedOrganizationId,
	expectedPickupLocality,
	expectedReturnDays,
	expectedReturnLink
) {
	const { documents, parseErrors } = jsonLdDocuments(html);
	const schemaContext = collectSchemaContext(documents);
	const nodes = collectJsonLdNodes(documents);
	const idOf = (node) => schemaProperty(node, '@id', schemaContext);
	const valueOf = (node, property) => schemaProperty(node, property, schemaContext);
	const byId = new Map(nodes.filter((node) => idOf(node)).map((node) => [idOf(node), node]));
	const organizations = nodes.filter((node) => typesOf(node, schemaContext).some(isOrganizationType));
	const expectedIdOrganizations = organizations.filter((organization) =>
		normalizedEntityId(idOf(organization), baseUrl) === expectedOrganizationId
	);
	const canonicalOrganizations = organizations.filter((organization) => {
		const types = typesOf(organization, schemaContext);
		return normalizedEntityId(idOf(organization), baseUrl) === expectedOrganizationId
			&& types.includes('OnlineStore')
			&& types.includes('Organization');
	});
	// Decisión del propietario 2026-08-07: un nodo de ubicación VACÍO (por
	// ejemplo el Place/PostalAddress residual que Rank Math emite cuando la
	// dirección está en blanco) no expone ningún dato y se tolera. El check
	// falla únicamente cuando propiedades o tipos de ubicación llevan valores.
	const metaOnlyKeys = new Set(['@type', '@id', '@context']);
	const hasMeaningfulLocationValue = (value) => {
		if (value === null || value === undefined) return false;
		if (typeof value === 'string') return value.trim() !== '';
		if (typeof value === 'number' || typeof value === 'boolean') return true;
		if (Array.isArray(value)) return value.some((item) => hasMeaningfulLocationValue(item));
		if (typeof value === 'object') {
			return Object.entries(value).some(([innerKey, innerValue]) =>
				!metaOnlyKeys.has(schemaTerm(innerKey, schemaContext))
				&& hasMeaningfulLocationValue(innerValue));
		}
		return false;
	};
	const locationViolations = nodes.flatMap((node, index) => {
		const forbiddenProperties = [...new Set(
			Object.entries(node)
				.filter(([property, value]) =>
					FORBIDDEN_LOCATION_PROPERTIES.has(schemaTerm(property, schemaContext))
					&& hasMeaningfulLocationValue(value))
				.map(([property]) => schemaTerm(property, schemaContext))
		)];
		const nodeCarriesLocationContent = Object.entries(node).some(([property, value]) =>
			!metaOnlyKeys.has(schemaTerm(property, schemaContext))
			&& hasMeaningfulLocationValue(value));
		const forbiddenTypes = nodeCarriesLocationContent
			? typesOf(node, schemaContext).filter(isForbiddenLocationType)
			: [];
		const forbiddenStringSignals = inspectJsonLdStringSignals(
			node,
			schemaContext,
			expectedPickupLocality
		);
		if (
			forbiddenProperties.length === 0
			&& forbiddenTypes.length === 0
			&& forbiddenStringSignals.length === 0
		) return [];
		return [{
			node: index + 1,
			nodeTypes: reportableSchemaTypes(typesOf(node, schemaContext)),
			forbiddenProperties,
			forbiddenTypes,
			forbiddenStringSignals,
		}];
	});

	const attachedPolicies = [];
	for (const organization of canonicalOrganizations) {
		const policyValue = valueOf(organization, 'hasMerchantReturnPolicy');
		const rawPolicies = Array.isArray(policyValue) ? policyValue : [policyValue];
		for (const rawPolicy of rawPolicies) {
			const policy = resolveReference(rawPolicy, byId, schemaContext);
			if (!policy) continue;
			attachedPolicies.push({ organization, policy });
		}
	}

	const policyDetails = attachedPolicies.map(({ organization, policy }) => ({
		organizationId: idOf(organization) ?? null,
		types: typesOf(policy, schemaContext),
		merchantReturnDays: valueOf(policy, 'merchantReturnDays') ?? null,
		applicableCountry: valueOf(policy, 'applicableCountry') ?? null,
		returnPolicyCountry: valueOf(policy, 'returnPolicyCountry') ?? null,
		returnPolicyCategory: valueOf(policy, 'returnPolicyCategory') ?? null,
		merchantReturnLink: valueOf(policy, 'merchantReturnLink') ?? null,
		returnMethod: valueOf(policy, 'returnMethod') ?? null,
		returnFees: valueOf(policy, 'returnFees') ?? null,
		refundType: valueOf(policy, 'refundType') ?? null,
		customerRemorseReturnFees: valueOf(policy, 'customerRemorseReturnFees') ?? null,
		itemDefectReturnFees: valueOf(policy, 'itemDefectReturnFees') ?? null,
		itemCondition: valueOf(policy, 'itemCondition') ?? null,
	}));

	const policyEvaluations = policyDetails.map((policy) => {
		const mismatchFields = [];
		if (!policy.types.includes('MerchantReturnPolicy')) mismatchFields.push('type');
		if (expectedReturnDays !== 'any' && Number(policy.merchantReturnDays) !== expectedReturnDays) mismatchFields.push('merchantReturnDays');
		if (policy.applicableCountry !== 'CL') mismatchFields.push('applicableCountry');
		if (policy.returnPolicyCountry !== 'CL') mismatchFields.push('returnPolicyCountry');
		if (policy.returnPolicyCategory !== 'https://schema.org/MerchantReturnFiniteReturnWindow') mismatchFields.push('returnPolicyCategory');
		if (policy.merchantReturnLink !== expectedReturnLink) mismatchFields.push('merchantReturnLink');
		if (policy.returnMethod !== 'https://schema.org/ReturnByMail') mismatchFields.push('returnMethod');
		if (policy.refundType !== 'https://schema.org/FullRefund') mismatchFields.push('refundType');
		if (policy.returnFees !== 'https://schema.org/ReturnFeesCustomerResponsibility') mismatchFields.push('returnFees');
		if (policy.customerRemorseReturnFees !== 'https://schema.org/ReturnFeesCustomerResponsibility') mismatchFields.push('customerRemorseReturnFees');
		if (policy.itemDefectReturnFees !== 'https://schema.org/FreeReturn') mismatchFields.push('itemDefectReturnFees');
		const itemConditions = Array.isArray(policy.itemCondition) ? policy.itemCondition : [policy.itemCondition];
		if (!itemConditions.includes('https://schema.org/NewCondition')) mismatchFields.push('itemCondition.NewCondition');
		if (!itemConditions.includes('https://schema.org/DamagedCondition')) mismatchFields.push('itemCondition.DamagedCondition');
		return { policy, mismatchFields };
	});
	const policyEvidence = policyEvaluations.map(({ policy, mismatchFields }) => ({
		types: reportableSchemaTypes(policy.types),
		merchant_return_days: Number.isInteger(Number(policy.merchantReturnDays))
			? Number(policy.merchantReturnDays)
			: null,
		valid: mismatchFields.length === 0,
		mismatch_fields: mismatchFields,
	}));
	const malformedPolicies = policyEvaluations
		.filter(({ mismatchFields }) => mismatchFields.length > 0)
		.map(({ policy, mismatchFields }) => ({
			types: reportableSchemaTypes(policy.types),
			mismatch_fields: mismatchFields,
		}));

	return {
		documents: documents.length,
		parseErrors,
		organizationCount: organizations.length,
		organizationTypes: reportableSchemaTypes(organizations.flatMap((node) => typesOf(node, schemaContext))),
		expectedIdOrganizationCount: expectedIdOrganizations.length,
		expectedIdOrganizationTypes: reportableSchemaTypes(expectedIdOrganizations.flatMap((node) => typesOf(node, schemaContext))),
		canonicalOrganizationCount: canonicalOrganizations.length,
		canonicalOrganizationTypes: reportableSchemaTypes(canonicalOrganizations.flatMap((node) => typesOf(node, schemaContext))),
		locationViolations,
		policyDetails: policyEvidence,
		malformedPolicies,
	};
}

function xmlLocs(xml) {
	return Array.from(xml.matchAll(/<loc(?:\s[^>]*)?>([\s\S]*?)<\/loc>/gi), (match) =>
		decodeEntities(match[1]).trim()
	);
}

function hasXmlRoot(xml, names) {
	return names.some((name) => new RegExp(`<${name}(?:\\s|>)`, 'i').test(xml));
}

function normalizedUrl(value) {
	const url = new URL(value);
	url.hash = '';
	return url.href.replace(/\/+$/, '/');
}

function validatedBaseUrl(value, flag) {
	let url;
	try {
		url = new URL(value);
	} catch {
		throw new Error(`${flag} debe ser una URL absoluta valida`);
	}
	if (!['http:', 'https:'].includes(url.protocol)) {
		throw new Error(`${flag} debe usar http o https`);
	}
	if (url.username || url.password) {
		throw new Error(`${flag} no puede incluir credenciales`);
	}
	url.pathname = url.pathname.replace(/\/*$/, '/');
	url.search = '';
	url.hash = '';
	return url;
}

function rebaseUrl(url, sourceBaseUrl, destinationBaseUrl) {
	const sourceUrl = new URL(url);
	const sourcePath = sourceBaseUrl.pathname;
	const relativePath = sourceUrl.pathname.startsWith(sourcePath)
		? sourceUrl.pathname.slice(sourcePath.length)
		: sourceUrl.pathname.replace(/^\/+/, '');
	const destinationUrl = new URL(relativePath, destinationBaseUrl);
	destinationUrl.search = sourceUrl.search;
	destinationUrl.hash = '';
	return destinationUrl;
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

async function mapLimit(items, limit, mapper) {
	const results = new Array(items.length);
	let cursor = 0;
	const worker = async () => {
		for (;;) {
			const index = cursor;
			cursor += 1;
			if (index >= items.length) return;
			results[index] = await mapper(items[index], index);
		}
	};
	await Promise.all(Array.from({ length: Math.min(limit, items.length || 1) }, worker));
	return results;
}

async function writeTemporaryResponse(workDir, sequence, response) {
	const prefix = String(sequence).padStart(4, '0');
	await fs.writeFile(path.join(workDir, `${prefix}.body`), response.body, { mode: 0o600 });
	await fs.writeFile(path.join(workDir, `${prefix}.json`), JSON.stringify({
		requestUrl: response.requestUrl,
		url: response.url,
		status: response.status,
		redirected: response.redirected,
		contentType: response.contentType,
		requestAccept: response.requestAccept,
		vary: response.vary,
		userAgent: response.userAgentId,
	}, null, 2), { mode: 0o600 });
}

function createFetcher(args) {
	let sequence = 0;
	return async function fetchReadOnly(
		url,
		userAgent,
		label,
		requestAccept = 'text/html,application/xhtml+xml,application/xml,text/plain;q=0.9,*/*;q=0.8'
	) {
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), args.timeoutMs);
		let result;
		try {
			const response = await fetch(url, {
				method: 'GET',
				redirect: 'manual',
				headers: {
					accept: requestAccept,
					'user-agent': userAgent.value,
				},
				signal: controller.signal,
			});
			const body = await response.text();
			const directRedirect = response.status >= 300 && response.status < 400;
			result = {
				label,
				requestUrl: url.href,
				url: response.url,
				status: response.status,
				redirected: response.redirected || directRedirect,
				contentType: response.headers.get('content-type'),
				contentSignal: response.headers.get('content-signal'),
				vary: response.headers.get('vary'),
				xRobotsTag: response.headers.get('x-robots-tag'),
				cfCacheStatus: response.headers.get('cf-cache-status'),
				body,
				bodySha256: sha256(body),
				userAgentId: userAgent.id,
				requestAccept,
				error: null,
			};
		} catch (error) {
			result = {
				label,
				requestUrl: url.href,
				url: null,
				status: null,
				redirected: false,
				contentType: null,
				contentSignal: null,
				vary: null,
				xRobotsTag: null,
				cfCacheStatus: null,
				body: '',
				bodySha256: sha256(''),
				userAgentId: userAgent.id,
				requestAccept,
				error: error.name === 'AbortError' ? `timeout de ${args.timeoutMs} ms` : error.message,
			};
		} finally {
			clearTimeout(timer);
		}

		sequence += 1;
		await writeTemporaryResponse(args.workDir, sequence, result);
		return result;
	};
}

function publicResponse(response) {
	return {
		label: response.label,
		user_agent: response.userAgentId,
		request_url: response.requestUrl,
		final_url: response.url,
		status: response.status,
		redirected: response.redirected,
		content_type: response.contentType,
		content_signal: response.contentSignal,
		request_accept: response.requestAccept,
		vary: response.vary,
		x_robots_tag: response.xRobotsTag,
		cf_cache_status: response.cfCacheStatus,
		body_sha256: response.bodySha256,
		error: response.error,
	};
}

async function audit(args) {
	const baseUrl = validatedBaseUrl(args.baseUrl, '--base-url');
	const fetchBaseUrl = validatedBaseUrl(args.fetchBaseUrl ?? args.baseUrl, '--fetch-base-url');

	const resourcePaths = {
		home: './',
		manga: 'manga/',
		about: 'nosotros/',
		contact: 'contacto/',
		faq: 'faq/',
		terms: 'terminos-y-condiciones/',
		robots: 'robots.txt',
		llms: 'llms.txt',
		sitemap_index: 'sitemap_index.xml',
	};
	const canonicalUrls = Object.fromEntries(
		Object.entries(resourcePaths).map(([label, resourcePath]) => [label, new URL(resourcePath, baseUrl)])
	);
	const fetchUrls = Object.fromEntries(
		Object.entries(resourcePaths).map(([label, resourcePath]) => [label, new URL(resourcePath, fetchBaseUrl)])
	);
	const canonicalManga = normalizedUrl(canonicalUrls.manga);
	const canonicalSitemap = normalizedUrl(canonicalUrls.sitemap_index);
	const fetchReadOnly = createFetcher(args);
	const checks = [];
	const addCheck = (id, status, message, evidence = {}) => {
		const effectiveStatus = args.strict && status === 'warn' ? 'fail' : status;
		checks.push({ id, status: effectiveStatus, message, evidence });
	};

	process.stderr.write(`Auditando ${Object.keys(canonicalUrls).length} recursos criticos con ${USER_AGENTS.length} user agents...\n`);
	const criticalJobs = USER_AGENTS.flatMap((userAgent) =>
		Object.entries(fetchUrls).map(([label, url]) => ({ userAgent, label, url }))
	);
	const criticalResponses = await mapLimit(criticalJobs, args.concurrency, (job) =>
		fetchReadOnly(job.url, job.userAgent, job.label)
	);
	const byKey = new Map(criticalResponses.map((response) => [
		`${response.userAgentId}:${response.label}`,
		response,
	]));

	const markdownResponses = await mapLimit(
		[
			{ label: 'home_markdown', url: fetchUrls.home },
			{ label: 'manga_markdown', url: fetchUrls.manga },
		],
		1,
		({ label, url }) => fetchReadOnly(url, USER_AGENTS[0], label, 'text/markdown')
	);
	const markdownEnabled = markdownResponses.filter((response) =>
		String(response.contentType ?? '').toLowerCase().startsWith('text/markdown')
	);
	const markdownHttpFailures = markdownResponses.filter((response) =>
		response.status !== 200 || response.redirected
	);
	const markdownMalformed = markdownEnabled.filter((response) => {
		const signal = parseContentSignal(response.contentSignal);
		const vary = String(response.vary ?? '').toLowerCase().split(',').map((value) => value.trim());
		return !vary.includes('accept')
			|| !signal
			|| signal.errors.length > 0
			|| signal.values.search !== 'yes'
			|| signal.values['ai-input'] !== 'yes';
	});
	if (markdownHttpFailures.length > 0) {
		addCheck(
			'representation.markdown_for_agents',
			'fail',
			'La negociacion Accept: text/markdown no respondio HTTP 200 directo.',
			{ failures: markdownHttpFailures.map(publicResponse) }
		);
	} else if (markdownEnabled.length === 0) {
		addCheck(
			'representation.markdown_for_agents',
			'skip',
			'La negociacion Accept: text/markdown devuelve HTML; Markdown for Agents no esta habilitado.',
			{
				responses: markdownResponses.map(publicResponse),
				official_reference: 'https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/',
			}
		);
	} else if (markdownEnabled.length !== markdownResponses.length || markdownMalformed.length > 0) {
		addCheck(
			'representation.markdown_for_agents',
			'fail',
			'Markdown for Agents responde de forma parcial o sin Content-Signal/Vary: Accept contractual.',
			{ responses: markdownResponses.map(publicResponse), malformed: markdownMalformed.map(publicResponse) }
		);
	} else {
		addCheck(
			'representation.markdown_for_agents',
			'pass',
			'Home y /manga/ negocian Markdown con Content-Signal y Vary: Accept validos.',
			{ responses: markdownResponses.map(publicResponse) }
		);
	}

	const criticalFailures = criticalResponses
		.filter((response) => response.status !== 200)
		.map(publicResponse);
	addCheck(
		'http.critical_resources_all_user_agents',
		criticalFailures.length === 0 ? 'pass' : 'fail',
		criticalFailures.length === 0
			? `Los ${Object.keys(canonicalUrls).length} recursos criticos respondieron HTTP 200 con los ${USER_AGENTS.length} user agents.`
			: `${criticalFailures.length} combinaciones de recurso y user agent no respondieron HTTP 200.`,
		{ requests: criticalResponses.length, failures: criticalFailures }
	);

	const redirectedCritical = criticalResponses.filter((response) => response.redirected).map(publicResponse);
	if (redirectedCritical.length > 0) {
		addCheck(
			'http.critical_resources_direct',
			'fail',
			`${redirectedCritical.length} recursos criticos respondieron con redireccion; no fueron seguidos.`,
			{ redirected: redirectedCritical }
		);
	} else {
		addCheck('http.critical_resources_direct', 'pass', 'Los recursos criticos se sirvieron sin redirecciones.');
	}

	const contentSignalResponses = criticalResponses.filter((response) => ['home', 'manga'].includes(response.label));
	const contentSignalMissing = [];
	const contentSignalBlocked = [];
	const contentSignalMalformed = [];
	for (const response of contentSignalResponses) {
		const signal = parseContentSignal(response.contentSignal);
		if (!signal) contentSignalMissing.push(publicResponse(response));
		else if (signal.errors.length > 0) {
			contentSignalMalformed.push({ ...publicResponse(response), parsed: signal });
		} else if (signal.values.search !== 'yes' || signal.values['ai-input'] !== 'yes') {
			contentSignalBlocked.push({ ...publicResponse(response), parsed: signal.values });
		}
	}
	if (contentSignalMalformed.length > 0) {
		addCheck(
			'headers.content_signal',
			'fail',
			'Content-Signal contiene una forma o dominio invalido en respuestas HTML.',
			{ malformed: contentSignalMalformed, blocked: contentSignalBlocked, missing: contentSignalMissing }
		);
	} else if (contentSignalBlocked.length > 0) {
		addCheck(
			'headers.content_signal',
			'fail',
			'Content-Signal no permite search=yes y ai-input=yes en todas las respuestas HTML.',
			{ blocked: contentSignalBlocked, missing: contentSignalMissing }
		);
	} else if (contentSignalMissing.length > 0) {
		addCheck(
			'headers.content_signal',
			args.requireContentSignal ? 'fail' : 'skip',
			args.requireContentSignal
				? `Content-Signal es obligatorio en este modo y falta en ${contentSignalMissing.length} respuestas HTML.`
				: 'La cabecera HTTP Content-Signal no esta publicada en HTML; este control no evalua la directiva independiente de robots.txt.',
			{ required: args.requireContentSignal, missing: contentSignalMissing }
		);
	} else {
		addCheck(
			'headers.content_signal',
			'pass',
			`La home y /manga/ declaran search=yes y ai-input=yes para los ${USER_AGENTS.length} user agents.`,
			{ values: contentSignalResponses.map((response) => ({ user_agent: response.userAgentId, path: response.label, value: response.contentSignal })) }
		);
	}

	const primaryUa = USER_AGENTS[0];
	const primary = Object.fromEntries(Object.keys(canonicalUrls).map((label) => [
		label,
		byKey.get(`${primaryUa.id}:${label}`),
	]));

	const robots = parseRobots(primary.robots.body);
	const blockedRobots = [];
	for (const userAgent of ROBOTS_POLICY_TOKENS) {
		for (const url of [
			canonicalUrls.home,
			canonicalUrls.manga,
			canonicalUrls.about,
			canonicalUrls.contact,
			canonicalUrls.faq,
			canonicalUrls.terms,
			canonicalUrls.sitemap_index,
			canonicalUrls.llms,
		]) {
			const target = `${url.pathname}${url.search}`;
			const decision = robotsAllows(robots, userAgent.robotToken, target);
			if (!decision.allowed) {
				blockedRobots.push({
					user_agent: userAgent.robotToken,
					path: target,
					matched_rule: decision.matchedRule,
				});
			}
		}
	}
	addCheck(
		'robots.critical_paths_allowed',
		blockedRobots.length === 0 ? 'pass' : 'fail',
		blockedRobots.length === 0
			? `robots.txt permite las superficies publicas, sitemap y llms.txt a los ${ROBOTS_POLICY_TOKENS.length} controles de politica.`
			: `robots.txt bloquea ${blockedRobots.length} combinaciones criticas.`,
		{ blocked: blockedRobots }
	);

	const storebotCommercePaths = ['/manga/', '/carrito/', '/checkout/', '/finalizar-compra/'];
	const storebotCommerceDecisions = storebotCommercePaths.map((target) => ({
		path: target,
		...robotsAllows(robots, 'Storebot-Google', target),
	}));
	const storebotCommerceBlocked = storebotCommerceDecisions.filter((decision) => !decision.allowed);
	addCheck(
		'robots.storebot_commerce_paths_allowed',
		storebotCommerceBlocked.length === 0 ? 'pass' : 'fail',
		storebotCommerceBlocked.length === 0
			? 'Storebot-Google puede recorrer catalogo, carrito y checkout para verificar Merchant.'
			: `robots.txt bloquea ${storebotCommerceBlocked.length} rutas del flujo comercial requerido por Storebot-Google.`,
		{
			decisions: storebotCommerceDecisions,
			official_references: [
				'https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers',
				'https://support.google.com/merchants/answer/13294660?hl=en',
				'https://support.google.com/merchants/answer/13484511?hl=en',
			],
		}
	);

	const robotsSignals = ROBOTS_POLICY_TOKENS.map((userAgent) => ({
		user_agent: userAgent.robotToken,
		...robotsContentSignal(robots, userAgent.robotToken),
	}));
	const robotsSignalsMissing = robotsSignals.filter((signal) => !signal.present);
	const robotsSignalsMalformed = robotsSignals.filter((signal) => signal.errors.length > 0);
	const robotsSignalsBlocked = robotsSignals.filter((signal) =>
		signal.present
		&& signal.errors.length === 0
		&& (signal.values.search !== 'yes' || signal.values['ai-input'] !== 'yes')
	);
	if (robotsSignalsMalformed.length > 0) {
		addCheck(
			'robots.content_signal',
			'fail',
			'robots.txt publica Content-Signal con forma, dominio o valores conflictivos.',
			{ malformed: robotsSignalsMalformed }
		);
	} else if (robotsSignalsBlocked.length > 0) {
		addCheck(
			'robots.content_signal',
			'fail',
			'robots.txt no permite search=yes y ai-input=yes para todos los agentes auditados.',
			{ blocked: robotsSignalsBlocked, missing: robotsSignalsMissing }
		);
	} else if (robotsSignalsMissing.length > 0) {
		addCheck(
			'robots.content_signal',
			'warn',
			'robots.txt no publica una directiva Content-Signal efectiva para todos los agentes auditados.',
			{ missing: robotsSignalsMissing, observed: robotsSignals }
		);
	} else {
		addCheck(
			'robots.content_signal',
			'pass',
			'robots.txt permite search=yes y ai-input=yes para todos los agentes auditados.',
			{ observed: robotsSignals }
		);
	}

	const observedAiTrain = [...new Set(
		robotsSignals
			.filter((signal) => signal.present && signal.errors.length === 0)
			.map((signal) => signal.values['ai-train'] ?? null)
	)];
	if (args.expectedAiTrain === null) {
		addCheck(
			'robots.ai_training_policy',
			'warn',
			'La politica ai-train publicada no tiene un contrato explicito en esta ejecucion.',
			{
				expected: null,
				observed: observedAiTrain,
				requires_owner_decision: true,
				google_extended_tradeoff: 'Gemini Apps grounding y entrenamiento futuro no se separan con Google-Extended',
			}
		);
	} else {
		const aiTrainMatches = observedAiTrain.length === 1 && observedAiTrain[0] === args.expectedAiTrain;
		addCheck(
			'robots.ai_training_policy',
			aiTrainMatches ? 'pass' : 'fail',
			aiTrainMatches
				? `robots.txt coincide con el contrato ai-train=${args.expectedAiTrain}.`
				: `robots.txt no coincide de forma uniforme con el contrato ai-train=${args.expectedAiTrain}.`,
			{ expected: args.expectedAiTrain, observed: observedAiTrain, requires_owner_decision: false }
		);
	}
	const declaredSitemaps = robots.sitemaps.map((value) => {
		try { return normalizedUrl(new URL(value, baseUrl)); } catch { return value; }
	});
	addCheck(
		'robots.sitemap_declared',
		declaredSitemaps.includes(canonicalSitemap) ? 'pass' : 'fail',
		declaredSitemaps.includes(canonicalSitemap)
			? 'robots.txt declara el sitemap index canonico.'
			: 'robots.txt no declara el sitemap index canonico.',
		{ expected: canonicalSitemap, declared: declaredSitemaps }
	);
	const robotsHashes = new Set(USER_AGENTS.map((userAgent) => byKey.get(`${userAgent.id}:robots`).bodySha256));
	addCheck(
		'robots.same_content_all_user_agents',
		robotsHashes.size === 1 ? 'pass' : 'warn',
		robotsHashes.size === 1
			? `robots.txt entrega el mismo contenido a los ${USER_AGENTS.length} user agents.`
			: 'robots.txt cambia segun el user agent; revisar que no exista bloqueo diferencial.',
		{ distinct_sha256: [...robotsHashes] }
	);

	const mangaMetadata = USER_AGENTS.map((userAgent) => ({
		userAgent: userAgent.id,
		response: byKey.get(`${userAgent.id}:manga`),
		metadata: parseHtmlMetadata(byKey.get(`${userAgent.id}:manga`).body),
	}));
	const titleMismatches = mangaMetadata.filter(({ metadata }) => metadata.title !== args.expectedTitle);
	addCheck(
		'manga.title',
		titleMismatches.length === 0 ? 'pass' : 'fail',
		titleMismatches.length === 0
			? `El title de /manga/ coincide exactamente bajo los ${USER_AGENTS.length} user agents.`
			: 'El title de /manga/ no coincide con el valor esperado en todas las respuestas.',
		{ expected: args.expectedTitle, observed: mangaMetadata.map(({ userAgent, metadata }) => ({ user_agent: userAgent, value: metadata.title })) }
	);
	const metaMismatches = mangaMetadata.filter(({ metadata }) => metadata.description !== args.expectedMeta);
	addCheck(
		'manga.meta_description',
		metaMismatches.length === 0 ? 'pass' : 'warn',
		metaMismatches.length === 0
			? `La meta description de /manga/ coincide exactamente bajo los ${USER_AGENTS.length} user agents.`
			: 'La meta description publicada no coincide con la propuesta configurada.',
		{ expected: args.expectedMeta, observed: mangaMetadata.map(({ userAgent, metadata }) => ({ user_agent: userAgent, value: metadata.description })) }
	);
	const canonicalMismatches = mangaMetadata.filter(({ metadata }) => {
		try { return new URL(metadata.canonical, baseUrl).href !== canonicalManga; } catch { return true; }
	});
	addCheck(
		'manga.canonical',
		canonicalMismatches.length === 0 ? 'pass' : 'fail',
		canonicalMismatches.length === 0
			? `La canonical de /manga/ es limpia y exacta bajo los ${USER_AGENTS.length} user agents.`
			: 'La canonical de /manga/ falta o no coincide con la URL limpia.',
		{ expected: canonicalManga, observed: mangaMetadata.map(({ userAgent, metadata }) => ({ user_agent: userAgent, value: metadata.canonical })) }
	);
	const noindexResponses = mangaMetadata.filter(({ metadata, response }) => {
		const xRobots = comparableText(response.xRobotsTag).split(/[\s,]+/).filter(Boolean);
		return metadata.robotDirectives.includes('noindex') || xRobots.includes('noindex');
	});
	addCheck(
		'manga.indexable',
		noindexResponses.length === 0 ? 'pass' : 'fail',
		noindexResponses.length === 0
			? '/manga/ no declara noindex en HTML ni en X-Robots-Tag.'
			: '/manga/ declara noindex para uno o mas user agents.',
		{ blocked_user_agents: noindexResponses.map(({ userAgent, metadata, response }) => ({
			user_agent: userAgent,
			meta_robots: metadata.robotDirectives,
			x_robots_tag: response.xRobotsTag,
		})) }
	);

	const schemaResults = USER_AGENTS.map((userAgent) => ({
		userAgent: userAgent.id,
		...inspectOrganizationSchema(
			byKey.get(`${userAgent.id}:home`).body,
			baseUrl,
			new URL('/#organization', baseUrl).href,
			args.expectedPickupLocality,
			args.expectedReturnDays,
			new URL('devoluciones/', baseUrl).href
		),
	}));
	const schemaSurfaceResults = USER_AGENTS.flatMap((userAgent) =>
		PUBLIC_BUSINESS_PAGE_LABELS.map((page) => ({
			userAgent: userAgent.id,
			page,
			...inspectOrganizationSchema(
				byKey.get(`${userAgent.id}:${page}`).body,
				baseUrl,
				new URL('#organization', baseUrl).href,
				args.expectedPickupLocality,
				args.expectedReturnDays,
				new URL('devoluciones/', baseUrl).href
			),
		}))
	);
	const canonicalOrganizationFailures = schemaSurfaceResults.filter((result) => {
		if (result.page === 'home') {
			return result.organizationCount !== 1 || result.canonicalOrganizationCount !== 1;
		}
		return result.organizationCount > 0
			&& (result.organizationCount !== 1 || result.expectedIdOrganizationCount !== 1);
	});
	addCheck(
		'schema.canonical_online_store',
		canonicalOrganizationFailures.length === 0 ? 'pass' : 'fail',
		canonicalOrganizationFailures.length === 0
			? `Home publica una unica /#organization tipada OnlineStore y Organization; las demas superficies que publican Organization reutilizan solo ese @id bajo los ${USER_AGENTS.length} user agents.`
			: 'Home debe publicar una sola /#organization tipada OnlineStore y Organization; ninguna otra superficie puede publicar una Organization competidora.',
		{
			expected_id: new URL('/#organization', baseUrl).href,
			expected_types: ['OnlineStore', 'Organization'],
			observed: schemaSurfaceResults.map((result) => ({
				user_agent: result.userAgent,
				page: result.page,
				organization_count: result.organizationCount,
				organization_types: result.organizationTypes,
				reused_id_count: result.expectedIdOrganizationCount,
				reused_id_types: result.expectedIdOrganizationTypes,
				canonical_count: result.canonicalOrganizationCount,
				canonical_types: result.canonicalOrganizationTypes,
			})),
		}
	);
	const locationSchemaFailures = schemaSurfaceResults.filter((result) => result.locationViolations.length > 0);
	addCheck(
		'schema.online_store_without_physical_location',
		locationSchemaFailures.length === 0 ? 'pass' : 'fail',
		locationSchemaFailures.length === 0
			? `Las ${PUBLIC_BUSINESS_PAGE_LABELS.length} superficies comerciales no publican direccion, Place, geolocalizacion, mapa, horarios, tipos ni valores JSON-LD de local fisico bajo los ${USER_AGENTS.length} user agents.`
			: 'Una superficie comercial contiene propiedades, tipos o valores string JSON-LD que representan una ubicacion fisica.',
		{
			forbidden_properties: [...FORBIDDEN_LOCATION_PROPERTIES],
			forbidden_types: [...FORBIDDEN_LOCATION_TYPES],
			forbidden_string_signals: [
				'physical_storefront_claim',
				'public_address',
				'public_map_reference',
			],
			observed: locationSchemaFailures.map((result) => ({
				user_agent: result.userAgent,
				page: result.page,
				violations: result.locationViolations,
			})),
		}
	);
	const jsonLdParseErrors = schemaSurfaceResults.flatMap((result) =>
		result.parseErrors.map((error) => ({ user_agent: result.userAgent, page: result.page, ...error }))
	);
	if (jsonLdParseErrors.length > 0) {
		addCheck('schema.jsonld_syntax', 'warn', 'Hay scripts JSON-LD que no pudieron parsearse.', { errors: jsonLdParseErrors });
	} else {
		addCheck('schema.jsonld_syntax', 'pass', 'Todos los scripts JSON-LD de las superficies comerciales son JSON valido.');
	}

	const policyCounts = schemaResults.map((result) => result.policyDetails.length);
	const policyMalformed = schemaResults.flatMap((result) =>
		result.malformedPolicies.map((policy) => ({ user_agent: result.userAgent, ...policy }))
	);
	if (policyMalformed.length > 0) {
		addCheck(
			'schema.merchant_return_policy',
			'fail',
			'MerchantReturnPolicy existe, pero no coincide con el contrato comercial esperado.',
			{ expected_return_days: args.expectedReturnDays, malformed: policyMalformed }
		);
	} else if (policyCounts.every((count) => count > 0)) {
		addCheck(
			'schema.merchant_return_policy',
			'pass',
			`MerchantReturnPolicy esta adjunta a Organization/OnlineStore bajo los ${USER_AGENTS.length} user agents.`,
			{
				required: args.requireReturnPolicy,
				expected_return_days: args.expectedReturnDays,
				observed: schemaResults.map((result) => ({ user_agent: result.userAgent, policies: result.policyDetails })),
			}
		);
	} else if (policyCounts.some((count) => count > 0)) {
		addCheck(
			'schema.merchant_return_policy',
			'fail',
			'MerchantReturnPolicy aparece de forma inconsistente segun el user agent.',
			{ counts: Object.fromEntries(schemaResults.map((result) => [result.userAgent, result.policyDetails.length])) }
		);
	} else if (args.requireReturnPolicy) {
		addCheck(
			'schema.merchant_return_policy',
			'fail',
			'MerchantReturnPolicy es obligatoria en este modo y no esta publicada.',
			{ required: true, expected_return_days: args.expectedReturnDays }
		);
	} else {
		addCheck(
			'schema.merchant_return_policy',
			'skip',
			'MerchantReturnPolicy no esta publicada; su presencia es opcional en este modo.',
			{ required: false, expected_return_days_if_present: args.expectedReturnDays }
		);
	}

	const aboutTruthResults = USER_AGENTS.map((userAgent) => ({
		user_agent: userAgent.id,
		...inspectAboutTruth(
			byKey.get(`${userAgent.id}:about`).body,
			args.expectedPickupLocality
		),
	}));
	const aboutTruthFailures = aboutTruthResults.filter((result) =>
		!result.online_only
		|| !result.no_physical_storefront
		|| !result.no_walk_in
		|| !result.coordinated_pickup
		|| !result.pickup_locality
		|| !result.pickup_coordination_after_confirmation
		|| !result.physical_storefront_claim_absent
		|| !result.public_address_absent
		|| !result.public_map_link_absent
		|| !result.public_location_icon_absent
	);
	addCheck(
		'content.about_online_store_truth',
		aboutTruthFailures.length === 0 ? 'pass' : 'fail',
		aboutTruthFailures.length === 0
			? `/nosotros/ declara dentro de main el modelo 100% online, sin local ni walk-in, con retiro coordinado en ${args.expectedPickupLocality}, dia y hora coordinados al confirmar y sin ubicacion publica contradictoria.`
			: '/nosotros/ no cumple todas las señales positivas y negativas exigidas dentro de su contenido principal.',
		{
			expected_pickup_locality: args.expectedPickupLocality,
			required_signals: [
				'online_only',
				'no_physical_storefront',
				'no_walk_in',
				'coordinated_pickup',
				'pickup_locality',
				'pickup_coordination_after_confirmation',
				'physical_storefront_claim_absent',
				'public_address_absent',
				'public_map_link_absent',
				'public_location_icon_absent',
			],
			observed: aboutTruthResults,
		}
	);

	const publicLocationResults = USER_AGENTS.flatMap((userAgent) =>
		PUBLIC_BUSINESS_PAGE_LABELS.map((page) => ({
			user_agent: userAgent.id,
			page,
			...inspectVisiblePhysicalLocation(
				byKey.get(`${userAgent.id}:${page}`).body,
				args.expectedPickupLocality
			),
		}))
	);
	const publicLocationFailures = publicLocationResults.filter((result) =>
		!result.physical_storefront_claim_absent
		|| !result.public_address_absent
		|| !result.public_map_link_absent
		|| !result.public_location_icon_absent
	);
	addCheck(
		'content.public_surfaces_without_physical_location',
		publicLocationFailures.length === 0 ? 'pass' : 'fail',
		publicLocationFailures.length === 0
			? `Las ${PUBLIC_BUSINESS_PAGE_LABELS.length} superficies comerciales omiten direccion publica, mapa y afirmaciones de local fisico bajo los ${USER_AGENTS.length} user agents.`
			: 'Una superficie comercial publica una direccion, mapa o afirmacion de local fisico.',
		{
			pages: PUBLIC_BUSINESS_PAGE_LABELS,
			forbidden_signals: [
				'physical_storefront_claim',
				'public_address',
				'public_map_link',
				'public_location_icon',
			],
			observed: publicLocationFailures,
		}
	);

	const termsIdentityResults = USER_AGENTS.map((userAgent) => ({
		user_agent: userAgent.id,
		...inspectTermsSellerIdentity(
			byKey.get(`${userAgent.id}:terms`).body,
			args.expectedPickupLocality
		),
	}));
	const termsRequiredSignals = [
		'seller_identity_present',
		'rut_present',
		'legal_domicile_label_present',
		'legal_address_present',
		'legal_representative_present',
		'contact_channel_present',
		'legal_domicile_not_storefront',
		'tax_domicile_only_absent',
		'placeholder_absent',
		'physical_storefront_claim_absent',
		'public_map_link_absent',
		'public_location_icon_absent',
	];
	const termsIdentityFailures = termsIdentityResults.filter((result) =>
		termsRequiredSignals.some((signal) => result[signal] !== true)
	);
	addCheck(
		'content.terms_seller_legal_identity',
		termsIdentityFailures.length === 0 ? 'pass' : 'fail',
		termsIdentityFailures.length === 0
			? `/terminos-y-condiciones/ identifica al vendedor con razon social, RUT, domicilio legal, representante y contacto; el domicilio esta rotulado como dato legal y negado como local, sucursal o retiro publico bajo los ${USER_AGENTS.length} user agents.`
			: '/terminos-y-condiciones/ no contiene una identidad legal completa y coherente del vendedor, o presenta el domicilio como ubicacion fisica/publica.',
		{
			required_signals: termsRequiredSignals,
			observed: termsIdentityResults,
		}
	);

	const llmsHasManga = primary.llms.body.includes(canonicalManga)
		|| /(?:^|[\s("'])\/manga\/(?:[\s)"']|$)/m.test(primary.llms.body);
	addCheck(
		'llms.manga_reference',
		llmsHasManga ? 'pass' : (args.requireLlmsManga ? 'fail' : 'warn'),
		llmsHasManga
			? 'llms.txt enlaza el catalogo /manga/.'
			: 'llms.txt no enlaza /manga/; este archivo no garantiza ranking y no es un requisito de los buscadores.',
		{ required: args.requireLlmsManga, expected: canonicalManga }
	);
	const llmsHashes = new Set(USER_AGENTS.map((userAgent) => byKey.get(`${userAgent.id}:llms`).bodySha256));
	addCheck(
		'llms.same_content_all_user_agents',
		llmsHashes.size === 1 ? 'pass' : 'warn',
		llmsHashes.size === 1
			? `llms.txt entrega el mismo contenido a los ${USER_AGENTS.length} user agents.`
			: 'llms.txt cambia segun el user agent.',
		{ distinct_sha256: [...llmsHashes] }
	);

	const sitemapIndexLooksXml = hasXmlRoot(primary.sitemap_index.body, ['sitemapindex']);
	const sitemapUrls = xmlLocs(primary.sitemap_index.body);
	addCheck(
		'sitemap.index_xml',
		sitemapIndexLooksXml && sitemapUrls.length > 0 ? 'pass' : 'fail',
		sitemapIndexLooksXml && sitemapUrls.length > 0
			? `El sitemap index es XML y declara ${sitemapUrls.length} hijos.`
			: 'El sitemap index no contiene un sitemapindex XML util.',
		{ child_count: sitemapUrls.length, content_type: primary.sitemap_index.contentType }
	);
	if (sitemapUrls.length > args.maxSitemaps) {
		addCheck(
			'sitemap.child_limit',
			'fail',
			`El indice declara ${sitemapUrls.length} hijos y supera el limite defensivo de ${args.maxSitemaps}; no se hara una auditoria parcial.`,
			{ child_count: sitemapUrls.length, max_sitemaps: args.maxSitemaps }
		);
	} else {
		addCheck('sitemap.child_limit', 'pass', 'Todos los sitemaps hijos caben dentro del limite defensivo.', {
			child_count: sitemapUrls.length,
			max_sitemaps: args.maxSitemaps,
		});
	}

	const sameOriginSitemaps = [];
	const externalOrInvalidSitemaps = [];
	for (const value of sitemapUrls.slice(0, args.maxSitemaps)) {
		try {
			const url = new URL(value, baseUrl);
			if (url.origin !== baseUrl.origin) externalOrInvalidSitemaps.push(value);
			else sameOriginSitemaps.push(url);
		} catch {
			externalOrInvalidSitemaps.push(value);
		}
	}
	addCheck(
		'sitemap.children_same_origin',
		externalOrInvalidSitemaps.length === 0 ? 'pass' : 'fail',
		externalOrInvalidSitemaps.length === 0
			? 'Todos los sitemaps hijos pertenecen al mismo origen auditado.'
			: 'El sitemap index contiene hijos externos o invalidos; no fueron rastreados.',
		{ invalid_or_external: externalOrInvalidSitemaps }
	);

	const sitemapUserAgents = args.sitemapUaScope === 'all' ? USER_AGENTS : [primaryUa];
	const sitemapJobs = sameOriginSitemaps.flatMap((canonicalUrl) =>
		sitemapUserAgents.map((userAgent) => ({
			url: rebaseUrl(canonicalUrl, baseUrl, fetchBaseUrl),
			userAgent,
		}))
	);
	process.stderr.write(`Auditando ${sameOriginSitemaps.length} sitemaps hijos con ${sitemapUserAgents.length} user agent(s)...\n`);
	const childResponses = await mapLimit(sitemapJobs, args.concurrency, ({ url, userAgent }) =>
		fetchReadOnly(url, userAgent, 'sitemap_child')
	);
	const childFailures = childResponses
		.filter((response) => response.status !== 200 || !hasXmlRoot(response.body, ['urlset', 'sitemapindex']))
		.map(publicResponse);
	addCheck(
		'sitemap.children_http_xml',
		childFailures.length === 0 && childResponses.length > 0 ? 'pass' : 'fail',
		childFailures.length === 0 && childResponses.length > 0
			? `Los ${childResponses.length} cruces de sitemap hijo y user agent respondieron HTTP 200 con XML.`
			: `${childFailures.length} respuestas de sitemaps hijos fallaron HTTP 200 o no contenian XML.`,
		{
			sitemap_count: sameOriginSitemaps.length,
			user_agent_count: sitemapUserAgents.length,
			requests: childResponses.length,
			failures: childFailures,
		}
	);
	const primaryChildBodies = childResponses
		.filter((response) => response.userAgentId === primaryUa.id)
		.map((response) => response.body);
	const mangaInSitemap = primaryChildBodies.some((body) =>
		xmlLocs(body).some((value) => {
			try { return normalizedUrl(new URL(value, baseUrl)) === canonicalManga; } catch { return false; }
		})
	);
	addCheck(
		'sitemap.manga_listed',
		mangaInSitemap ? 'pass' : 'fail',
		mangaInSitemap
			? '/manga/ aparece como URL exacta en un sitemap hijo.'
			: '/manga/ no aparece como URL exacta en los sitemaps hijos.',
		{ expected: canonicalManga }
	);

	const counts = {
		pass: checks.filter((check) => check.status === 'pass').length,
		warn: checks.filter((check) => check.status === 'warn').length,
		fail: checks.filter((check) => check.status === 'fail').length,
		skip: checks.filter((check) => check.status === 'skip').length,
	};
	const exitCode = counts.fail > 0 ? 1 : (counts.warn > 0 ? 2 : 0);
	const status = exitCode === 1 ? 'fail' : (exitCode === 2 ? 'warn' : 'pass');

	return {
		report: {
			schema_version: SCHEMA_VERSION,
			tool: TOOL,
			audited_at: new Date().toISOString(),
			base_url: baseUrl.href,
			fetch_base_url: fetchBaseUrl.href,
			read_only: true,
			ranking_guarantee: false,
			configuration: {
				expected_title: args.expectedTitle,
				expected_meta_description: args.expectedMeta,
				business_model_contract: 'online_store_without_public_storefront_v1',
				expected_pickup_locality: args.expectedPickupLocality,
				require_content_signal: args.requireContentSignal,
				expected_ai_train: args.expectedAiTrain,
				require_return_policy: args.requireReturnPolicy,
				expected_return_days: args.expectedReturnDays,
				require_llms_manga: args.requireLlmsManga,
				strict: args.strict,
				sitemap_user_agent_scope: args.sitemapUaScope,
				redirect_policy: 'manual_fail_closed',
			},
			user_agents: USER_AGENTS.map(({ id, robotToken, purpose, respectsRobots, value, officialReference }) => ({
				id,
				robots_token: robotToken,
				purpose,
				respects_robots: respectsRobots,
				value,
				official_reference: officialReference,
			})),
			robots_policy_only_tokens: ROBOTS_POLICY_TOKENS
				.filter(({ policyOnly }) => policyOnly)
				.map(({ id, robotToken, purpose, officialReference }) => ({
					id,
					robots_token: robotToken,
					purpose,
					official_reference: officialReference,
				})),
			summary: {
				status,
				exit_code: exitCode,
				checks: checks.length,
				...counts,
				critical_http_requests: criticalResponses.length,
				markdown_negotiation_http_requests: markdownResponses.length,
				sitemap_child_http_requests: childResponses.length,
			},
			checks,
		},
		exitCode,
	};
}

function errorReport(error) {
	return {
		schema_version: SCHEMA_VERSION,
		tool: TOOL,
		audited_at: new Date().toISOString(),
		read_only: true,
		ranking_guarantee: false,
		summary: {
			status: 'fail',
			exit_code: 1,
			checks: 1,
			pass: 0,
			warn: 0,
			fail: 1,
			skip: 0,
		},
		checks: [{
			id: 'tool.execution',
			status: 'fail',
			message: error.message,
			evidence: {},
		}],
	};
}

try {
	const args = parseArgs(process.argv);
	if (args.help) {
		process.stdout.write(`${usage()}\n`);
		process.exitCode = 0;
	} else {
		const { report, exitCode } = await audit(args);
		process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
		process.exitCode = exitCode;
	}
} catch (error) {
	process.stdout.write(`${JSON.stringify(errorReport(error), null, 2)}\n`);
	process.exitCode = 1;
}
