#!/usr/bin/env node

/**
 * Verifica que sitemaps y manifiestos IA describan el mismo inventario.
 *
 * Uso:
 *   node ops/verify-discovery-manifests.mjs --base-url=http://127.0.0.1:8080
 */

import process from 'node:process';

const argument = process.argv.find((value) => value.startsWith('--base-url='));
const baseUrl = new URL(argument ? argument.slice('--base-url='.length) : 'http://127.0.0.1:8080');
baseUrl.pathname = '/';
baseUrl.search = '';
baseUrl.hash = '';

const forbiddenPaths = new Set(
  process.argv
    .filter((value) => value.startsWith('--forbid-path='))
    .map((value) => new URL(value.slice('--forbid-path='.length), baseUrl).pathname),
);

const timeoutMs = 30_000;

function fail(message, details = []) {
  console.error(`FAIL: ${message}`);
  for (const detail of details.slice(0, 20)) {
    console.error(`  ${detail}`);
  }
  process.exitCode = 1;
}

async function fetchText(pathOrUrl, userAgent = 'AkibaraDiscoveryGate/1.0') {
  const url = new URL(pathOrUrl, baseUrl);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, {
      headers: { 'user-agent': userAgent },
      redirect: 'manual',
      signal: controller.signal,
    });
    if (response.status !== 200) {
      throw new Error(`${url.href} devolvió HTTP ${response.status}`);
    }
    return {
      body: await response.text(),
      contentType: response.headers.get('content-type') ?? '',
      url: url.href,
    };
  } finally {
    clearTimeout(timer);
  }
}

function assertSurfaceSignature(pathOrUrl, document) {
  const pathname = new URL(pathOrUrl, baseUrl).pathname;
  if (pathname === '/robots.txt') {
    if (!/^User-agent:/mu.test(document.body) || !/^Sitemap:\s*https?:\/\//mu.test(document.body)) {
      throw new Error(`${document.url} devolvió 200 sin firma válida de robots.txt`);
    }
    return;
  }
  if (pathname === '/llms.txt' || pathname === '/llms-full.txt') {
    if (!/^#\s+Akibara\s*$/mu.test(document.body) || !/^##\s+Series(?:\s|$)/mu.test(document.body)) {
      throw new Error(`${document.url} devolvió 200 sin firma válida de llms.txt`);
    }
    return;
  }
  if (/sitemap.*\.xml$/u.test(pathname)) {
    if (!/<(?:sitemapindex|urlset)\b/iu.test(document.body) || !/<loc>\s*https?:\/\//iu.test(document.body)) {
      throw new Error(`${document.url} devolvió 200 sin firma XML de sitemap`);
    }
  }
}

async function probeSurface(pathOrUrl, userAgent) {
  const document = await fetchText(pathOrUrl, userAgent);
  assertSurfaceSignature(pathOrUrl, document);
  return document;
}

function decodeXml(value) {
  return value
    .replaceAll('&amp;', '&')
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'");
}

function sitemapLocations(xml) {
  return [...xml.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/giu)].map((match) => decodeXml(match[1].trim()));
}

function section(markdown, heading, nextHeadings) {
	const lines = markdown.split('\n');
	const start = lines.findIndex((line) => line === heading || line.startsWith(`${heading} (`));
	if (start < 0) {
		return '';
	}
	const stop = lines.findIndex(
		(line, index) => index > start && nextHeadings.some((candidate) => line === candidate || line.startsWith(`${candidate} (`)),
	);
	return lines.slice(start + 1, stop >= 0 ? stop : undefined).join('\n');
}

function markdownLinks(markdown, indentation = null) {
  const links = [];
  for (const line of markdown.split('\n')) {
    const match = line.match(/^(\s*)- \[[^\]]+\]\((https?:\/\/[^)]+)\)/u);
    if (!match || (indentation !== null && match[1].length !== indentation)) {
      continue;
    }
    links.push(match[2]);
  }
  return links;
}

function normalize(url) {
  const parsed = new URL(url);
  parsed.hash = '';
  return parsed.href;
}

function hasPath(url, paths) {
  return paths.has(new URL(url).pathname);
}

function duplicates(values) {
  const seen = new Set();
  const repeated = new Set();
  for (const value of values) {
    if (seen.has(value)) {
      repeated.add(value);
    }
    seen.add(value);
  }
  return [...repeated].sort();
}

function compareSets(label, expectedValues, actualValues) {
  const expected = new Set(expectedValues.map(normalize));
  const actual = new Set(actualValues.map(normalize));
  const missing = [...expected].filter((url) => !actual.has(url)).sort();
  const unexpected = [...actual].filter((url) => !expected.has(url)).sort();

  if (missing.length > 0) {
    fail(`${label}: faltan ${missing.length}`, missing);
  }
  if (unexpected.length > 0) {
    fail(`${label}: sobran ${unexpected.length}`, unexpected);
  }

  return { expected: expected.size, actual: actual.size };
}

function canonicalFromHtml(html) {
  for (const tag of html.match(/<link\b[^>]*>/giu) ?? []) {
    const rel = tag.match(/\brel\s*=\s*(["'])(.*?)\1/iu)?.[2] ?? '';
    if (!rel.split(/\s+/u).some((value) => value.toLowerCase() === 'canonical')) {
      continue;
    }
    return tag.match(/\bhref\s*=\s*(["'])(.*?)\1/iu)?.[2] ?? '';
  }
  return '';
}

async function validateDirectSelfCanonicals(label, values) {
  const urls = [...new Set(values.map(normalize))];
  const concurrency = 6;
  let cursor = 0;

  async function worker() {
    while (cursor < urls.length) {
      const url = urls[cursor];
      cursor += 1;
      try {
        const document = await fetchText(url);
        if (!/^text\/html\b/iu.test(document.contentType)) {
          fail(`${label}: ${url} no devolvió HTML`, [document.contentType || '(sin Content-Type)']);
          continue;
        }
        const canonical = canonicalFromHtml(document.body);
        if (!canonical) {
          fail(`${label}: falta canonical en ${url}`);
        } else if (normalize(canonical) !== normalize(url)) {
          fail(`${label}: canonical no propio en ${url}`, [canonical]);
        }
      } catch (error) {
        fail(`${label}: URL no directa ${url}`, [error instanceof Error ? error.message : String(error)]);
      }
    }
  }

  await Promise.all(Array.from({ length: Math.min(concurrency, urls.length) }, () => worker()));
}

function assertForbiddenPathsAbsent(label, values) {
  if (forbiddenPaths.size === 0) {
    return;
  }
  const matches = values
    .filter((url) => forbiddenPaths.has(new URL(url).pathname))
    .map(normalize);
  if (matches.length > 0) {
    fail(`${label}: contiene ${matches.length} URL prohibidas`, matches);
  }
}

async function main() {
  const corePaths = ['/robots.txt', '/sitemap_index.xml', '/llms.txt', '/llms-full.txt'];
  const botUserAgents = [
    'Googlebot/2.1 (+http://www.google.com/bot.html)',
    'GPTBot/1.2',
    'meta-externalagent/1.1',
  ];
  for (const path of corePaths) {
    for (const userAgent of botUserAgents) {
      await probeSurface(path, userAgent);
    }
  }

  const [index, compact, full] = await Promise.all(
    ['/sitemap_index.xml', '/llms.txt', '/llms-full.txt'].map((path) => probeSurface(path, 'AkibaraDiscoveryGate/1.0')),
  );
  const childUrls = sitemapLocations(index.body);
  const productSitemaps = childUrls.filter((url) => /\/product-sitemap\d*\.xml(?:$|\?)/u.test(new URL(url).pathname));
  const seriesSitemap = childUrls.find((url) => /\/pa_serie-sitemap\.xml(?:$|\?)/u.test(new URL(url).pathname));
  const postSitemap = childUrls.find((url) => /\/post-sitemap\.xml(?:$|\?)/u.test(new URL(url).pathname));

  if (productSitemaps.length < 1 || !seriesSitemap || !postSitemap) {
    throw new Error('El índice no expone product, pa_serie y post sitemaps.');
  }

  for (const childUrl of childUrls) {
    for (const userAgent of botUserAgents) {
      await probeSurface(childUrl, userAgent);
    }
  }

  const [productDocuments, seriesDocument, postDocument] = await Promise.all([
    Promise.all(productSitemaps.map((url) => fetchText(url))),
    fetchText(seriesSitemap),
    fetchText(postSitemap),
  ]);

	const sitemapProducts = productDocuments.flatMap(({ body }) => sitemapLocations(body));
	const sitemapSeries = sitemapLocations(seriesDocument.body);
	const sitemapPosts = sitemapLocations(postDocument.body);
	const productHubPaths = new Set(['/tienda/', '/preventas/', '/disponibles/']);
	const postHubPaths = new Set(['/blog/']);
	const sitemapProductItems = sitemapProducts.filter((url) => !hasPath(url, productHubPaths));
	const sitemapGuideItems = sitemapPosts.filter((url) => !hasPath(url, postHubPaths));
	assertForbiddenPathsAbsent('sitemaps', [...sitemapProducts, ...sitemapSeries, ...sitemapPosts]);

  const seriesBlock = section(full.body, '## Series', ['## Otros productos', '## Guías']);
  const standaloneBlock = section(full.body, '## Otros productos', ['## Guías']);
  const guidesBlock = section(full.body, '## Guías', ['## Tienda']);
  const manifestSeries = markdownLinks(seriesBlock, 0);
  const manifestChildren = markdownLinks(seriesBlock, 2);
  const manifestStandalone = markdownLinks(standaloneBlock, 0);
  const manifestProducts = [...manifestChildren, ...manifestStandalone];
	const allManifestGuides = markdownLinks(guidesBlock, 0);
	const manifestGuides = allManifestGuides.filter((url) => !hasPath(url, postHubPaths));
	const allManifestLinks = markdownLinks(full.body);
	assertForbiddenPathsAbsent('llms-full.txt', allManifestLinks);
	const missingHubs = [...productHubPaths, ...postHubPaths].filter(
		(path) => !allManifestLinks.some((url) => new URL(url).pathname === path),
	);
	if (missingHubs.length > 0) {
		fail(`llms-full.txt no representa ${missingHubs.length} hubs del sitemap`, missingHubs);
	}

  const duplicateProducts = duplicates(manifestProducts.map(normalize));
  if (duplicateProducts.length > 0) {
    fail(`llms-full.txt repite ${duplicateProducts.length} productos`, duplicateProducts);
  }

  const seriesCounts = compareSets('series sitemap vs llms-full', sitemapSeries, manifestSeries);
	const productCounts = compareSets('product sitemap vs llms-full', sitemapProductItems, manifestProducts);
	const guideCounts = compareSets('post sitemap vs llms-full', sitemapGuideItems, manifestGuides);

	// Las guías son pocas y editoriales: probarlas directamente evita que una
	// URL 3xx/404 con canonical ajeno pase por igualdad de conjuntos.
	await validateDirectSelfCanonicals('guías de post-sitemap', sitemapGuideItems);

  const compactSeries = markdownLinks(section(compact.body, '## Series', ['## Otros productos', '## Guías']), 0);
  const compactStandalone = markdownLinks(section(compact.body, '## Otros productos', ['## Guías']), 0);
  compareSets('series llms compacto vs full', manifestSeries, compactSeries);
  compareSets('standalone llms compacto vs full', manifestStandalone, compactStandalone);

  if (process.exitCode) {
    return;
  }

  console.log(
    `PASS: ${seriesCounts.actual} series, ${productCounts.actual} productos `
      + `(${manifestChildren.length} bajo serie + ${manifestStandalone.length} otros), `
      + `${guideCounts.actual} guías; sin omisiones ni duplicados.`,
  );
}

main().catch((error) => {
  fail(error instanceof Error ? error.message : String(error));
});
