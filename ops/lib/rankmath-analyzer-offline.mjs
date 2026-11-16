import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

export const RANK_MATH_ANALYZER_VERSION = '1.0.277.2';

// Mirrors Post_Screen::get_analysis() in Rank Math 1.0.274.1. The analyzer
// omits titleSentiment for Spanish because that analysis is not applicable.
export const RANK_MATH_RESEARCH_TESTS = Object.freeze([
	'contentHasTOC',
	'contentHasShortParagraphs',
	'contentHasAssets',
	'keywordInTitle',
	'keywordInMetaDescription',
	'keywordInPermalink',
	'keywordIn10Percent',
	'keywordInContent',
	'keywordInSubheadings',
	'keywordInImageAlt',
	'keywordDensity',
	'keywordNotUsed',
	'lengthContent',
	'lengthPermalink',
	'linksHasInternal',
	'linksHasExternals',
	'linksNotAllExternals',
	'titleStartWithKeyword',
	'titleSentiment',
	'titleHasPowerWords',
	'titleHasNumber',
	'hasContentAI',
]);

const moduleDir = path.dirname(fileURLToPath(import.meta.url));
const defaultProjectRoot = path.resolve(moduleDir, '../..');
const wordpressRelativeFiles = Object.freeze([
	'wp-includes/js/dist/vendor/lodash.min.js',
	'wp-includes/js/dist/hooks.min.js',
	'wp-includes/js/dist/i18n.min.js',
	'wp-includes/js/dist/wordcount.min.js',
	'wp-includes/js/dist/url.min.js',
	'wp-includes/js/dist/autop.min.js',
	'wp-content/plugins/seo-by-rank-math/assets/admin/js/analyzer.js',
	'wp-content/plugins/seo-by-rank-math/assets/vendor/powerwords/es.php',
]);

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function readRequired(file) {
	if (!fs.existsSync(file)) {
		throw new Error(`Rank Math offline analyzer missing required file: ${file}`);
	}
	return fs.readFileSync(file);
}

function parsePluginVersion(pluginFile) {
	const source = readRequired(pluginFile).toString('utf8');
	const match = /^\s*\*\s*Version:\s*([^\s]+)\s*$/mu.exec(source);
	if (!match) {
		throw new Error(`Unable to read Rank Math version from ${pluginFile}`);
	}
	return match[1];
}

function parsePowerWords(file) {
	const source = readRequired(file).toString('utf8');
	const words = [...source.matchAll(/'([^']+)'/gu)]
		.map((match) => match[1].toLocaleLowerCase('es-CL'));
	if (words.length < 100) {
		throw new Error(`Unexpected Spanish power-word catalog in ${file}`);
	}
	return words;
}

function escapeAttribute(value) {
	return String(value ?? '')
		.replaceAll('&', '&amp;')
		.replaceAll('"', '&quot;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;');
}

function featuredImageHtml(alt) {
	if (!String(alt ?? '').trim()) return '';
	// Akibara's rank_math_content editor filter appends the featured image to
	// the analyzed content. It does not populate Paper.thumbnail.
	return (
		'\n<img src="akibara-featured-image.jpg" alt="'
		+ `${escapeAttribute(alt)}">`
	);
}

function syntheticInlineImages(count) {
	if (!Number.isSafeInteger(count) || count < 0) {
		throw new Error('additionalInlineImageCount must be a non-negative integer');
	}
	return Array.from(
		{ length: count },
		(_, index) => (
			`\n<img src="offline-media-${index + 1}.jpg" `
			+ `alt="Imagen editorial ${index + 1}">`
		)
	).join('');
}

export function createRankMathOfflineAnalyzer({
	projectRoot = defaultProjectRoot,
} = {}) {
	const publicHtml = path.join(projectRoot, 'public_html');
	const pluginFile = path.join(
		publicHtml,
		'wp-content/plugins/seo-by-rank-math/rank-math.php'
	);
	const actualVersion = parsePluginVersion(pluginFile);
	if (actualVersion !== RANK_MATH_ANALYZER_VERSION) {
		throw new Error(
			`Rank Math version drifted: ${actualVersion} `
			+ `!= ${RANK_MATH_ANALYZER_VERSION}`
		);
	}

	const runtimeFiles = Object.fromEntries(
		wordpressRelativeFiles.map((relative) => {
			const absolute = path.join(publicHtml, relative);
			return [relative, sha256(readRequired(absolute))];
		})
	);
	const powerWordsPath = path.join(
		publicHtml,
		'wp-content/plugins/seo-by-rank-math/assets/vendor/powerwords/es.php'
	);
	const powerWords = parsePowerWords(powerWordsPath);
	const sandbox = {
		console,
		setTimeout,
		clearTimeout,
		URL,
		TextDecoder,
		TextEncoder,
	};
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	sandbox.self = sandbox;
	function jQueryStub() {
		return {
			attr: () => '',
			find: () => jQueryStub(),
			length: 0,
			done: () => jQueryStub(),
			fail: () => jQueryStub(),
			always: () => jQueryStub(),
		};
	}
	jQueryStub.ajax = () => ({
		done: () => ({ fail: () => ({ always: () => {} }), always: () => {} }),
		fail: () => ({ always: () => {} }),
		always: () => {},
	});
	sandbox.$ = jQueryStub;
	sandbox.jQuery = jQueryStub;
	vm.createContext(sandbox);

	for (const relative of wordpressRelativeFiles.slice(0, 6)) {
		const absolute = path.join(publicHtml, relative);
		vm.runInContext(readRequired(absolute).toString('utf8'), sandbox, {
			filename: relative,
		});
		if (sandbox._) sandbox.lodash = sandbox._;
	}
	sandbox.rankMath = {
		assessor: {
			diacritics: false,
			focusKeywordLink: '',
			hasTOCPlugin: false,
			powerWords,
			researchesTests: [...RANK_MATH_RESEARCH_TESTS],
		},
		homeUrl: 'https://akibara.cl',
		links: {
			'content-ai-settings': '#',
			'content-length': '#',
			toc: '#',
		},
		locale: 'es',
		localeFull: 'es_CL',
		objectID: 0,
		objectType: 'post',
		parentDomain: 'akibara.cl',
		postType: 'post',
	};
	const analyzerPath = path.join(
		publicHtml,
		'wp-content/plugins/seo-by-rank-math/assets/admin/js/analyzer.js'
	);
	vm.runInContext(readRequired(analyzerPath).toString('utf8'), sandbox, {
		filename: 'rank-math-analyzer-1.0.274.1.js',
	});
	if (!sandbox.rankMathAnalyzer?.Analyzer || !sandbox.rankMathAnalyzer?.Paper) {
		throw new Error('Official Rank Math analyzer bundle did not initialize');
	}

	async function analyzePost(post, {
		additionalInlineImageCount = 0,
		origin = 'https://akibara.cl',
	} = {}) {
		if (
			!Number.isSafeInteger(post?.production_post_id)
			|| typeof post?.content_key !== 'string'
			|| typeof post?.fields?.post_content !== 'string'
		) {
			throw new Error('Invalid desired post passed to Rank Math analyzer');
		}
		sandbox.rankMath.objectID = post.production_post_id;
		const paper = new sandbox.rankMathAnalyzer.Paper('', {
			locale: 'es_CL',
		});
		paper.setText(
			post.fields.post_content
			+ featuredImageHtml(post.featured_image_alt)
			+ syntheticInlineImages(additionalInlineImageCount)
		);
		paper.setKeyword(post.fields.rank_math_focus_keyword);
		paper.setKeywords([post.fields.rank_math_focus_keyword]);
		paper.setTitle(post.fields.rank_math_title);
		paper.setDescription(post.fields.rank_math_description);
		paper.setUrl(
			`${origin.replace(/\/+$/u, '')}/${post.content_key}/`
		);
		paper.setPermalink(post.content_key);
		paper.setPostType('post');
		paper.setSchema({});
		paper.setContentAI(false);

		const analyzer = new sandbox.rankMathAnalyzer.Analyzer({
			analyses: [...RANK_MATH_RESEARCH_TESTS],
		});
		const results = await analyzer.analyze(paper);
		const manager = new sandbox.rankMathAnalyzer.ResultManager();
		manager.update('primary', results, true);
		let earned = 0;
		let maximum = 0;
		const tests = Object.fromEntries(
			Object.entries(results).map(([name, result]) => {
				const score = result.getScore();
				const maxScore = result.getMaxScore('es');
				earned += score;
				maximum += maxScore;
				return [name, {
					applicable: true,
					earned_score: score,
					has_score: result.hasScore(),
					max_score: maxScore,
					text: result.getText(),
					tooltip: result.getTooltip(),
				}];
			})
		);
		const omittedTests = RANK_MATH_RESEARCH_TESTS.filter(
			(name) => !Object.hasOwn(tests, name)
		);
		const score = manager.getScore('primary');
		if (score !== Math.round((earned / maximum) * 100)) {
			throw new Error(
				`Rank Math score reconciliation failed for post `
				+ `${post.production_post_id}`
			);
		}
		return {
			production_post_id: post.production_post_id,
			content_key: post.content_key,
			focus_keyword: post.fields.rank_math_focus_keyword,
			predicted_score: score,
			earned_score: earned,
			max_score: maximum,
			additional_inline_image_count: additionalInlineImageCount,
			omitted_not_applicable_tests: omittedTests,
			tests,
		};
	}

	return Object.freeze({
		analyzePost,
		evidence: Object.freeze({
			analyzer_version: actualVersion,
			locale: 'es_CL',
			plugin_file_sha256: sha256(readRequired(pluginFile)),
			power_word_count: powerWords.length,
			research_tests: [...RANK_MATH_RESEARCH_TESTS],
			runtime_files_sha256: runtimeFiles,
			ui_adapter: Object.freeze({
				featured_image_appended_to_content: true,
				paper_thumbnail_populated: false,
				rationale:
					'Mirrors the Akibara rank_math_content editor filter.',
			}),
		}),
	});
}
