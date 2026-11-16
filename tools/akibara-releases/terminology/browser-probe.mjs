async (page) => {
	const expected = __AKB_TERMINOLOGY_EXPECTED__;
	const failures = [];
	const diagnostics = { consoleErrors: [], pageErrors: [], requestFailures: [] };
	const observations = {};
	const check = (condition, message) => {
		if (!condition) failures.push(message);
	};
	const visit = async (pathname) => {
		const response = await page.goto(expected.baseUrl + pathname, { waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('networkidle').catch(() => {});
		check(Boolean(response) && response.status() === 200, `${pathname} responde 200`);
		check(await page.evaluate(() => location.origin) === expected.baseUrl, `${pathname} permanece en Docker`);
		check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${pathname} sin overflow horizontal`);
	};

	page.on('console', (message) => {
		if (message.type() === 'error') diagnostics.consoleErrors.push(message.text());
	});
	page.on('pageerror', (error) => {
		diagnostics.pageErrors.push(String(error && error.message ? error.message : error));
	});
	page.on('requestfailed', (request) => {
		const failure = request.failure();
		const message = failure && failure.errorText ? failure.errorText : 'request failed';
		if (
			request.url().startsWith(expected.baseUrl)
			&& !/ERR_ABORTED|NS_BINDING_ABORTED|cancelled/i.test(message)
		) diagnostics.requestFailures.push(`${request.method()} ${request.url()}: ${message}`);
	});

	await visit('/manga/');
	const device = await page.evaluate(() => ({
		width: innerWidth,
		height: innerHeight,
		coarse: matchMedia('(pointer: coarse)').matches,
		touch: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
	}));
	observations.actualViewport = { width: device.width, height: device.height };
	check(device.width === expected.viewport.width && device.height === expected.viewport.height, 'viewport exacto');
	if (expected.mobile) {
		check(device.coarse, 'móvil usa pointer coarse');
		check(device.touch, 'móvil expone touch');
	}
	const resultCount = page.locator('.akb-result-count__total, .akb-result-count:not(.akb-result-count--extra)').first();
	const resultText = (await resultCount.textContent() || '').replace(/\s+/g, ' ').trim();
	observations.mangaResultCount = resultText;
	check(/^\d[\d.]* productos?$/.test(resultText), '/manga/ cuenta productos');
	check(!/\btomos?\b/i.test(resultText), '/manga/ no llama tomos al universo mixto');

	const seriesFacet = page.locator('[data-akb-facet-toggle]').filter({ hasText: 'Serie o edición' }).first();
	check(await seriesFacet.count() === 1, 'faceta visible Serie o edición');
	const facetInput = page.locator('[data-akb-facet-search][placeholder="Buscar serie o edición…"]').first();
	check(await facetInput.count() === 1, 'placeholder Buscar serie o edición');
	check(await facetInput.getAttribute('aria-label') === 'Buscar serie o edición', 'nombre accesible Buscar serie o edición');

	let searchInput;
	if (expected.mobile) {
		const openSearch = page.locator('#bottom-nav-search');
		check(await openSearch.isVisible(), 'móvil muestra el acceso Buscar');
		await openSearch.tap();
		try {
			await page.locator('#mobile-drawer[aria-hidden="false"]').waitFor({ state: 'visible', timeout: 4000 });
		} catch (error) {
			check(false, 'Buscar abre el drawer móvil');
		}
		searchInput = page.locator('#akb-mobile-search-input[data-akb-suggest]');
	} else {
		searchInput = page.locator('#akb-search-input[data-akb-suggest]');
	}
	check(await searchInput.count() === 1, 'buscador visible con autocomplete');
	if (await searchInput.count()) {
		await searchInput.fill('one piece');
		const suggestPanel = page.locator('.akb-suggest-panel:not([hidden])').first();
		try {
			await suggestPanel.waitFor({ state: 'visible', timeout: 8000 });
			// El panel se abre primero con «Buscando…» (sin grupos). Leer en ese
			// instante mide la carrera, no el vocabulario: se espera la primera
			// opción real que pinta la respuesta.
			await suggestPanel.locator('.akb-suggest-item').first().waitFor({ state: 'visible', timeout: 8000 });
			const heads = await suggestPanel.locator('.akb-suggest-head').allTextContents();
			observations.autocompleteGroups = heads;
			check(heads.includes('Series'), 'autocomplete conserva grupo Series');
			check(heads.includes('Productos'), 'autocomplete llama Productos al segundo grupo');
			check(!heads.includes('Tomos'), 'autocomplete no llama Tomos al grupo mixto');
		} catch (error) {
			check(false, 'autocomplete responde y abre el listbox');
		}
	}

	await visit('/editoriales/');
	const editorialCounts = (await page.locator('.akb-brand-card__count').allTextContents())
		.map((text) => text.replace(/\s+/g, ' ').trim());
	observations.editorialCounts = editorialCounts.slice(0, 4);
	check(editorialCounts.length > 0, 'directorio muestra conteos editoriales');
	check(editorialCounts.every((text) => /^\d[\d.]* productos? en catálogo$/.test(text)), 'editoriales cuentan producto(s) en catálogo');
	check(editorialCounts.every((text) => !/títulos?/i.test(text)), 'editoriales no confunden productos con títulos');

	await visit('/invencible-2-planeta-espana/');
	const faqText = (await page.locator('.product-faqs').textContent() || '').replace(/\s+/g, ' ').trim();
	observations.comicFaq = faqText;
	// La respuesta de género habla de la SERIE (`$serie_or_name` en ProductFaq), no del tomo.
	check(/Invencible(?: 02)? es un cómic/.test(faqText), 'FAQ reconoce Invencible como cómic');
	check(/Es una edición original publicada por Planeta Cómic y vendida por Akibara/.test(faqText), 'FAQ usa autenticidad editorial segura');
	check(!/Invencible 02 es un manga|Todos los mangas|funda protectora incluida/i.test(faqText), 'FAQ elimina afirmaciones falsas o impropias');

	await visit('/serie/one-piece/');
	const facts = page.locator('.akb-series-facts').first();
	check(await facts.getAttribute('aria-label') === 'Datos de esta edición y de la obra original', 'hechos distinguen edición y obra original');
	const firstProductUrl = await page.locator('li.product a.woocommerce-LoopProduct-link').first().getAttribute('href');
	check(Boolean(firstProductUrl), 'landing enlaza al primer producto');
	if (firstProductUrl) {
		const productPath = await page.evaluate((url) => new URL(url, location.href).pathname, firstProductUrl);
		await visit(productPath);
		const seriesCta = (await page.locator('.akb-series-nav__series-label').first().textContent() || '').trim();
		observations.pdpSeriesCta = seriesCta;
		// Carril SEO 2026-09-26: el enlace nombra la serie (copy «Ver los tomos de
		// {serie}», editable en Textos). Se mide que diga el nombre, no la frase.
		check(/^Ver los tomos de /.test(seriesCta) && seriesCta.includes('One Piece'), 'PDP dirige a los tomos nombrando la serie');
	}

	check(diagnostics.consoleErrors.length === 0, 'sin errores console');
	check(diagnostics.pageErrors.length === 0, 'sin pageerror');
	check(diagnostics.requestFailures.length === 0, 'sin requests locales fallidos');

	return {
		engine: expected.engine,
		mobile: expected.mobile,
		viewport: expected.viewport,
		observations,
		diagnostics,
		failures,
	};
}
