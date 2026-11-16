async (page) => {
	const expected = __AKB_FSP_EXPECTED__;
	const failures = [];
	const diagnostics = {
		consoleErrors: [],
		pageErrors: [],
		requestFailures: [],
		httpErrors: [],
		mutationRequests: [],
		offOriginRequests: [],
	};
	const check = (condition, message) => {
		if (!condition) failures.push(message);
	};
	const waitTwoFrames = () => page.evaluate(() => new Promise((resolve) => {
		requestAnimationFrame(() => requestAnimationFrame(resolve));
	}));
	const closeEnough = (first, second, tolerance = 2) => Math.abs(first - second) <= tolerance;
	const isRgb = (color, red, green, blue, tolerance = 2) => (
		color
		&& closeEnough(color.r, red, tolerance)
		&& closeEnough(color.g, green, tolerance)
		&& closeEnough(color.b, blue, tolerance)
		&& color.a >= 0.99
	);
	const noDurations = (value) => String(value || '')
		.split(',')
		.every((part) => (Number.parseFloat(part) || 0) === 0);

	page.on('console', (message) => {
		if (message.type() === 'error') diagnostics.consoleErrors.push(message.text());
	});
	page.on('pageerror', (error) => {
		diagnostics.pageErrors.push(String(error && error.message ? error.message : error));
	});
	page.on('requestfailed', (request) => {
		const failure = request.failure();
		diagnostics.requestFailures.push({
			method: request.method(),
			url: request.url(),
			error: failure && failure.errorText ? failure.errorText : 'request failed',
		});
	});
	page.on('response', (response) => {
		if (response.status() >= 400) {
			diagnostics.httpErrors.push({ status: response.status(), url: response.url() });
		}
	});
	page.on('request', (request) => {
		if (!['GET', 'HEAD'].includes(request.method())) {
			diagnostics.mutationRequests.push({ method: request.method(), url: request.url() });
		}
		if (
			(request.url().startsWith('http://') || request.url().startsWith('https://'))
			&& request.url() !== expected.baseUrl
			&& !request.url().startsWith(expected.baseUrl + '/')
		) {
			diagnostics.offOriginRequests.push({ method: request.method(), url: request.url() });
		}
	});

	await page.emulateMedia({ reducedMotion: 'no-preference' });
	const documentResponse = await page.goto(expected.stylesheetUrl, { waitUntil: 'domcontentloaded' });
	check(Boolean(documentResponse), 'el stylesheet local responde como documento de preflight');
	check(documentResponse && documentResponse.status() === 200, 'el stylesheet local responde HTTP 200');
	check(
		documentResponse && documentResponse.url().startsWith(expected.baseUrl + '/'),
		'el preflight permanece en el origen Docker loopback'
	);

	await page.setContent(`<!doctype html>
		<html lang="es">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title>Gate local del progreso de envío gratis</title>
			<style>
				html { background: #303438; }
				html, body { margin: 0; min-height: 100%; }
				*, *::before, *::after { box-sizing: border-box; }
				body {
					min-height: 100vh;
					padding: 24px 0;
					background: #303438;
					font-family: Arial, sans-serif;
				}
				#akb-fsp-gate-shell {
					--aki-black: #121212;
					--aki-white: #ffffff;
					--aki-yellow: #ffd700;
					--aki-success: #00c853;
					--font-heading: Arial, sans-serif;
					width: min(calc(100% - 32px), 720px);
					margin: 0 auto;
					padding: 24px;
					background: #121212;
					color: #e0e0e0;
					overflow: hidden;
				}
				.akb-cart-progress-slot { width: 100%; min-height: 58px; }
				@media (max-width: 480px) {
					body { padding-top: 16px; }
					#akb-fsp-gate-shell { padding: 18px; }
				}
			</style>
		</head>
		<body>
			<main id="akb-fsp-gate-shell" aria-label="Fixture aislada del indicador">
				<div class="akb-cart-progress-slot"></div>
			</main>
		</body>
		</html>`, { waitUntil: 'domcontentloaded' });

	const stylesheetLoaded = await page.evaluate((href) => new Promise((resolve) => {
		const link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = href;
		link.dataset.akbFspGate = '1';
		link.onload = () => resolve(true);
		link.onerror = () => resolve(false);
		document.head.appendChild(link);
	}), expected.stylesheetUrl);
	check(stylesheetLoaded, 'el navegador aplica free-shipping-progress.css local');

	const device = await page.evaluate(() => ({
		viewport: { width: innerWidth, height: innerHeight },
		origin: location.origin,
		userAgent: navigator.userAgent,
		maxTouchPoints: navigator.maxTouchPoints,
		coarsePointer: matchMedia('(pointer: coarse)').matches,
	}));
	check(
		device.viewport.width === expected.viewport.width
		&& device.viewport.height === expected.viewport.height,
		'viewport exacto'
	);
	check(device.origin === expected.baseUrl, 'la fixture conserva el origen Docker loopback');
	if (expected.engine === 'chromium') {
		check(/Chrome|Chromium/i.test(device.userAgent), 'la celda Chromium usa su motor esperado');
	} else {
		check(/AppleWebKit/i.test(device.userAgent) && !/Chrome|Chromium/i.test(device.userAgent), 'la celda WebKit usa su motor esperado');
	}
	const renderState = (percent) => page.evaluate((statePercent) => {
		const slot = document.querySelector('.akb-cart-progress-slot');
		const previousWrap = slot.querySelector('.akb-fsp-wrap');
		const previousIdentity = previousWrap ? previousWrap.dataset.akbFspFixtureIdentity : null;
		const fixtureIdentity = `fixture-${statePercent}-${Date.now()}-${Math.random()}`;
		if (statePercent === 100) {
			slot.innerHTML = `<div class="akb-fsp-wrap akb-fsp-qualified akb-fsp-q--order_amount" data-akb-fsp-fixture-identity="${fixtureIdentity}"><div class="akb-fsp-message">¡Listo! Tu pedido tiene envío gratis a domicilio</div></div>`;
			return { previousIdentity, fixtureIdentity, identityReplaced: previousWrap !== slot.querySelector('.akb-fsp-wrap') };
		}
		const almost = statePercent >= 80 ? ' akb-fsp-almost-there' : '';
		const warning = statePercent >= 80
			? '<svg class="akb-fsp-warn" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="vertical-align:-.125em;fill:currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"></path></svg> ¡CASI LO LOGRAS! '
			: '';
		const remaining = statePercent === 79 ? '11.550' : (statePercent === 80 ? '11.000' : '550');
		slot.innerHTML = `<div class="akb-fsp-wrap akb-fsp-active${almost}" data-akb-fsp-fixture-identity="${fixtureIdentity}">
			<div class="akb-fsp-title">Envío GRATIS a domicilio sobre $55.000</div>
			<div class="akb-fsp-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${statePercent}" aria-label="Envío GRATIS a domicilio sobre $55.000">
				<span class="akb-fsp-fill" style="width:${statePercent}%"><span class="akb-fsp-truck" aria-hidden="true"></span></span>
			</div>
			<div class="akb-fsp-message">${warning}Agrega <span class="woocommerce-Price-amount amount"><bdi>$${remaining}</bdi></span> más para envío gratis a domicilio</div>
		</div>`;
		return { previousIdentity, fixtureIdentity, identityReplaced: previousWrap !== slot.querySelector('.akb-fsp-wrap') };
	}, percent);

	const measureState = (percent) => page.evaluate((statePercent) => {
		const shell = document.querySelector('#akb-fsp-gate-shell');
		const slot = shell.querySelector('.akb-cart-progress-slot');
		const wrap = slot.querySelector('.akb-fsp-wrap');
		const track = wrap.querySelector('.akb-fsp-track');
		const fill = wrap.querySelector('.akb-fsp-fill');
		const truck = wrap.querySelector('.akb-fsp-truck');
		const message = wrap.querySelector('.akb-fsp-message');
		const warning = wrap.querySelector('.akb-fsp-warn');
		const amount = wrap.querySelector('.amount');
		const rect = (element) => {
			if (!element) return null;
			const bounds = element.getBoundingClientRect();
			return {
				x: bounds.x,
				y: bounds.y,
				left: bounds.left,
				top: bounds.top,
				right: bounds.right,
				bottom: bounds.bottom,
				width: bounds.width,
				height: bounds.height,
			};
		};
		const parseColor = (value) => {
			const match = String(value || '').match(/rgba?\(([^)]+)\)/i);
			if (!match) return null;
			const parts = match[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
			return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
		};
		const composite = (foreground, background) => {
			if (!foreground || !background) return null;
			const alpha = foreground.a + background.a * (1 - foreground.a);
			if (alpha === 0) return { r: 0, g: 0, b: 0, a: 0 };
			return {
				r: (foreground.r * foreground.a + background.r * background.a * (1 - foreground.a)) / alpha,
				g: (foreground.g * foreground.a + background.g * background.a * (1 - foreground.a)) / alpha,
				b: (foreground.b * foreground.a + background.b * background.a * (1 - foreground.a)) / alpha,
				a: alpha,
			};
		};
		const luminance = (color) => {
			if (!color) return 0;
			return [color.r, color.g, color.b]
				.map((value) => value / 255)
				.map((value) => value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4))
				.reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
		};
		const contrast = (first, second) => {
			if (!first || !second) return null;
			const firstLuminance = luminance(first);
			const secondLuminance = luminance(second);
			return Number(((Math.max(firstLuminance, secondLuminance) + 0.05) / (Math.min(firstLuminance, secondLuminance) + 0.05)).toFixed(2));
		};
		const style = (element, pseudo) => element ? getComputedStyle(element, pseudo || null) : null;
		const wrapStyle = style(wrap);
		const trackStyle = style(track);
		const fillStyle = style(fill);
		const truckStyle = style(truck);
		const truckInnerStyle = style(truck, '::after');
		const messageStyle = style(message);
		const amountStyle = style(amount);
		const checkStyle = statePercent === 100 ? style(message, '::before') : null;
		const shellBackground = parseColor(style(shell).backgroundColor);
		const wrapBackground = parseColor(wrapStyle.backgroundColor);
		const trackBackground = parseColor(trackStyle && trackStyle.backgroundColor);
		const fillBackground = parseColor(fillStyle && fillStyle.backgroundColor);
		const truckColor = parseColor(truckStyle && truckStyle.backgroundColor);
		const truckInnerColor = parseColor(truckInnerStyle && truckInnerStyle.backgroundColor);
		const messageColor = parseColor(messageStyle.color);
		const amountColor = parseColor(amountStyle && amountStyle.color);
		const trackBorder = parseColor(trackStyle && trackStyle.borderTopColor);
		const qualifiedBackground = statePercent === 100 ? wrapBackground : null;
		const effectiveMessageBackground = statePercent === 100 ? qualifiedBackground : shellBackground;
		const truckBounds = rect(truck);
		const fillBounds = rect(fill);
		const shellBounds = rect(shell);
		const wrapBounds = rect(wrap);
		let truckProbe = null;
		if (truck && truckBounds) {
			const centerX = Math.min(innerWidth - 1, Math.max(1, truckBounds.left + truckBounds.width / 2));
			const centerY = Math.min(innerHeight - 1, Math.max(1, truckBounds.top + truckBounds.height / 2));
			const naturalHit = document.elementFromPoint(centerX, centerY);
			const originalPointerEvents = truck.style.pointerEvents;
			truck.style.pointerEvents = 'auto';
			let paintedHitCount = 0;
			for (let x = Math.ceil(truckBounds.left + 2); x <= Math.floor(truckBounds.right - 2); x += 3) {
				for (let y = Math.ceil(truckBounds.top + 2); y <= Math.floor(truckBounds.bottom - 2); y += 3) {
					if (document.elementFromPoint(x, y) === truck) paintedHitCount += 1;
				}
			}
			const forcedCenterHit = document.elementFromPoint(centerX, centerY);
			truck.style.pointerEvents = originalPointerEvents;
			truckProbe = {
				center: { x: centerX, y: centerY },
				naturalHitClass: naturalHit ? naturalHit.className : null,
				naturalHitInsideFill: Boolean(naturalHit && (naturalHit === fill || naturalHit.closest('.akb-fsp-fill') === fill)),
				forcedCenterHitTruck: forcedCenterHit === truck,
				paintedHitCount,
			};
		}
		const maskImage = truckStyle
			? (truckStyle.maskImage || truckStyle.webkitMaskImage || truckStyle.getPropertyValue('-webkit-mask-image'))
			: '';
		const truckInnerMaskImage = truckInnerStyle
			? (truckInnerStyle.maskImage || truckInnerStyle.webkitMaskImage || truckInnerStyle.getPropertyValue('-webkit-mask-image'))
			: '';
		const checkMaskImage = checkStyle
			? (checkStyle.maskImage || checkStyle.webkitMaskImage || checkStyle.getPropertyValue('-webkit-mask-image'))
			: '';
		const checkBackground = parseColor(checkStyle && checkStyle.backgroundColor);

		return {
			percent: statePercent,
			classes: wrap.className,
			progress: track ? {
				role: track.getAttribute('role'),
				valueMin: track.getAttribute('aria-valuemin'),
				valueMax: track.getAttribute('aria-valuemax'),
				valueNow: track.getAttribute('aria-valuenow'),
				label: track.getAttribute('aria-label'),
			} : null,
			counts: {
				track: wrap.querySelectorAll('.akb-fsp-track').length,
				fill: wrap.querySelectorAll('.akb-fsp-fill').length,
				truck: wrap.querySelectorAll('.akb-fsp-truck').length,
				warning: wrap.querySelectorAll('.akb-fsp-warn').length,
			},
			geometry: {
				viewport: { width: innerWidth, height: innerHeight },
				documentOverflow: Math.max(0, document.documentElement.scrollWidth - innerWidth),
				shellOverflow: Math.max(0, shell.scrollWidth - shell.clientWidth),
				slotOverflow: Math.max(0, slot.scrollWidth - slot.clientWidth),
				wrapOverflow: Math.max(0, wrap.scrollWidth - wrap.clientWidth),
				shell: shellBounds,
				wrap: wrapBounds,
				track: rect(track),
				fill: fillBounds,
				truck: truckBounds,
				message: rect(message),
				messageClipped: message.scrollWidth > message.clientWidth + 1 || message.scrollHeight > message.clientHeight + 1,
				wrapOverflowContained: wrapStyle.overflowX === 'hidden' || wrapStyle.overflow === 'hidden',
				wrapInsideShell: Boolean(
					wrapBounds && shellBounds
					&& wrapBounds.left >= shellBounds.left - 1
					&& wrapBounds.right <= shellBounds.right + 1
				),
				truckHorizontalInsideFill: Boolean(
					truckBounds && fillBounds
					&& truckBounds.left >= fillBounds.left - 1
					&& truckBounds.right <= fillBounds.right + 1
				),
				containmentAxis: 'horizontal; el overhang vertical es intencional (truck 24px sobre track 8-10px)',
			},
			truckProbe,
			icons: {
				truckAriaHidden: truck ? truck.getAttribute('aria-hidden') : null,
				truckMaskImage: maskImage,
				truckMaskDataSvg: /data:image\/svg\+xml/i.test(maskImage),
				truckMaskSize: truckStyle ? (truckStyle.maskSize || truckStyle.webkitMaskSize || truckStyle.getPropertyValue('-webkit-mask-size')) : null,
				truckDisplay: truckStyle ? truckStyle.display : null,
				truckVisibility: truckStyle ? truckStyle.visibility : null,
				truckOpacity: truckStyle ? Number.parseFloat(truckStyle.opacity) : null,
				truckInnerContent: truckInnerStyle ? truckInnerStyle.content : null,
				truckInnerMaskImage,
				truckInnerMaskDataSvg: /data:image\/svg\+xml/i.test(truckInnerMaskImage),
				truckInnerMaskSize: truckInnerStyle ? (truckInnerStyle.maskSize || truckInnerStyle.webkitMaskSize || truckInnerStyle.getPropertyValue('-webkit-mask-size')) : null,
				truckInnerInsets: truckInnerStyle ? {
					top: truckInnerStyle.top,
					right: truckInnerStyle.right,
					bottom: truckInnerStyle.bottom,
					left: truckInnerStyle.left,
				} : null,
				warningAriaHidden: warning ? warning.getAttribute('aria-hidden') : null,
				checkContent: checkStyle ? checkStyle.content : null,
				checkMaskImage,
				checkMaskDataSvg: /data:image\/svg\+xml/i.test(checkMaskImage),
				checkWidth: checkStyle ? Number.parseFloat(checkStyle.width) : null,
				checkHeight: checkStyle ? Number.parseFloat(checkStyle.height) : null,
			},
			colors: {
				shellBackground,
				wrapBackground,
				trackBackground,
				trackBorder,
				trackBorderComposite: composite(trackBorder, trackBackground),
				fillBackground,
				truck: truckColor,
				truckInner: truckInnerColor,
				message: messageColor,
				amount: amountColor,
				check: checkBackground,
				truckOnFillContrast: contrast(truckColor, fillBackground),
				truckOnTrackContrast: contrast(truckColor, trackBackground),
				truckInnerOnFillContrast: contrast(truckInnerColor, fillBackground),
				messageContrast: contrast(messageColor, effectiveMessageBackground),
				amountContrast: contrast(amountColor, effectiveMessageBackground),
				checkContrast: contrast(checkBackground, qualifiedBackground),
			},
			motion: {
				reduced: matchMedia('(prefers-reduced-motion: reduce)').matches,
				fillTransitionProperty: fillStyle ? fillStyle.transitionProperty : null,
				fillTransitionDuration: fillStyle ? fillStyle.transitionDuration : null,
				fillAnimationName: fillStyle ? fillStyle.animationName : null,
				truckTransitionProperty: truckStyle ? truckStyle.transitionProperty : null,
				truckTransitionDuration: truckStyle ? truckStyle.transitionDuration : null,
				truckAnimationName: truckStyle ? truckStyle.animationName : null,
				wrapAnimationName: wrapStyle.animationName,
			},
		};
	}, percent);

	const states = [];
	const replacements = [];
	let touchEvidence = null;
	for (const percent of [79, 80, 99, 100]) {
		const replacement = await renderState(percent);
		replacements.push({ percent, ...replacement });
		await waitTwoFrames();
		if (percent === 80 && expected.mobile) {
			await page.evaluate(() => {
				window.__akbFspTouchEvents = [];
				window.__akbFspPointerEvents = [];
				document.addEventListener('touchstart', (event) => {
					const target = event.target;
					window.__akbFspTouchEvents.push({
						tag: target && target.tagName,
						className: target && target.className,
						insideTrack: Boolean(target && target.closest && target.closest('.akb-fsp-track')),
					});
				}, { capture: true, once: true });
				document.addEventListener('pointerdown', (event) => {
					const target = event.target;
					window.__akbFspPointerEvents.push({
						pointerType: event.pointerType,
						tag: target && target.tagName,
						className: target && target.className,
						insideTrack: Boolean(target && target.closest && target.closest('.akb-fsp-track')),
					});
				}, { capture: true, once: true });
			});
			await page.locator('.akb-fsp-track').tap({ position: { x: 8, y: 4 } });
			touchEvidence = await page.evaluate(() => ({
				touchEvents: window.__akbFspTouchEvents,
				pointerEvents: window.__akbFspPointerEvents,
			}));
			device.touchEvidence = touchEvidence;
			check(
				touchEvidence.touchEvents.some((event) => event.insideTrack)
				|| touchEvidence.pointerEvents.some((event) => event.insideTrack && event.pointerType === 'touch'),
				'la celda móvil dispara un gesto touch real sobre el track 80%'
			);
		}
		const state = await measureState(percent);
		states.push(state);
		await page.locator('#akb-fsp-gate-shell').screenshot({
			path: `state-${percent}-component.png`,
			animations: 'disabled',
		});

		check(state.geometry.documentOverflow <= 1, `${percent}%: documento sin overflow horizontal`);
		check(state.geometry.shellOverflow <= 1, `${percent}%: fixture sin overflow horizontal`);
		check(state.geometry.wrapInsideShell, `${percent}%: componente visible contenido dentro de la superficie`);
		check(
			state.geometry.slotOverflow <= (percent === 100 ? 8 : 1),
			`${percent}%: la proyección sesgada queda contenida y no crea scroll de viewport`
		);
		check(
			state.geometry.wrapOverflow <= 4 && state.geometry.wrapOverflowContained,
			`${percent}%: las esquinas sesgadas del track quedan recortadas por el wrap`
		);
		check(!state.geometry.messageClipped, `${percent}%: mensaje sin recorte`);
		check(state.motion.wrapAnimationName === 'none', `${percent}%: wrap sin animación continua`);

		if (percent < 100) {
			check(state.progress && state.progress.role === 'progressbar', `${percent}%: semántica progressbar`);
			check(state.progress && state.progress.valueMin === '0', `${percent}%: aria-valuemin 0`);
			check(state.progress && state.progress.valueMax === '100', `${percent}%: aria-valuemax 100`);
			check(state.progress && state.progress.valueNow === String(percent), `${percent}%: aria-valuenow exacto`);
			check(state.progress && state.progress.label.length > 0, `${percent}%: progressbar con nombre accesible`);
			check(state.counts.track === 1 && state.counts.fill === 1 && state.counts.truck === 1, `${percent}%: una barra, fill y camión`);
			check(state.icons.truckAriaHidden === 'true', `${percent}%: camión decorativo fuera del árbol accesible`);
			check(state.icons.truckMaskDataSvg, `${percent}%: máscara SVG del camión aplicada`);
			check(String(state.icons.truckMaskSize).includes('contain'), `${percent}%: máscara del camión usa contain`);
			check(state.icons.truckDisplay !== 'none' && state.icons.truckVisibility === 'visible' && state.icons.truckOpacity > 0.99, `${percent}%: camión pintable y visible`);
			check(state.geometry.truck && state.geometry.truck.width >= 23 && state.geometry.truck.height >= 23, `${percent}%: camión conserva 24x24 CSS px`);
			check(state.truckProbe && state.truckProbe.forcedCenterHitTruck && state.truckProbe.paintedHitCount > 0, `${percent}%: elementFromPoint encuentra área pintable del camión`);
			check(state.colors.messageContrast >= 4.5, `${percent}%: mensaje con contraste WCAG AA`);
			check(state.colors.amountContrast >= 4.5, `${percent}%: monto con contraste WCAG AA`);
			check(state.motion.fillAnimationName === 'none' && state.motion.truckAnimationName === 'none', `${percent}%: fill/camión sin animación continua`);
		}

		if (percent === 79) {
			check(!state.classes.includes('akb-fsp-almost-there'), '79%: aún no activa casi-lo-logras');
			check(isRgb(state.colors.fillBackground, 217, 0, 16), '79%: fill rojo Akibara');
			check(isRgb(state.colors.truck, 255, 255, 255), '79%: camión blanco reconocible');
			check(state.colors.truckOnFillContrast >= 4.5, '79%: camión blanco contrasta sobre fill rojo');
			check(state.colors.truckOnTrackContrast >= 7, '79%: porción del camión blanco contrasta sobre pista negra');
			check(state.counts.warning === 0, '79%: sin aviso prematuro');
		}

		if (percent === 80 || percent === 99) {
			check(state.classes.includes('akb-fsp-almost-there'), `${percent}%: activa casi-lo-logras en el umbral correcto`);
			check(isRgb(state.colors.fillBackground, 255, 215, 0), `${percent}%: fill amarillo Akibara`);
			check(isRgb(state.colors.truck, 255, 215, 0), `${percent}%: silueta exterior amarilla del camión`);
			check(state.colors.truckOnTrackContrast >= 7, `${percent}%: silueta exterior contrasta sobre la pista negra`);
			check(isRgb(state.colors.truckInner, 18, 18, 18), `${percent}%: dibujo interior negro del camión`);
			check(state.colors.truckInnerOnFillContrast >= 7, `${percent}%: dibujo interior contrasta sobre el fill amarillo`);
			check(
				state.icons.truckInnerContent === '\"\"'
					&& state.icons.truckInnerMaskDataSvg
					&& String(state.icons.truckInnerMaskSize).includes('contain')
					&& state.icons.truckInnerMaskImage === state.icons.truckMaskImage
					&& ['2px'].every((value) => Object.values(state.icons.truckInnerInsets || {}).every((inset) => inset === value)),
				`${percent}%: doble máscara anatómica deja contorno amarillo y vehículo negro`
			);
			check(state.geometry.truckHorizontalInsideFill, `${percent}%: camión completo dentro del fill en el eje horizontal (±1px)`);
			check(state.truckProbe && state.truckProbe.naturalHitInsideFill, `${percent}%: el centro visual del camión cae sobre el fill, no sobre pista pendiente`);
			check(state.counts.warning === 1 && state.icons.warningAriaHidden === 'true', `${percent}%: aviso visual único y decorativo`);
			check(!isRgb(state.colors.trackBorder, 255, 215, 0), `${percent}%: pista pendiente sin borde amarillo`);
			check(state.colors.trackBorder && state.colors.trackBorder.a <= 0.2, `${percent}%: pista pendiente usa borde neutral tenue`);
			check(isRgb(state.colors.trackBackground, 18, 18, 18), `${percent}%: pista pendiente conserva fondo negro`);
		}

		if (percent === 100) {
			check(state.classes.includes('akb-fsp-qualified'), '100%: activa estado calificado');
			check(state.counts.track === 0 && state.counts.fill === 0 && state.counts.truck === 0, '100%: banda verde sin barra ni camión residuales');
			check(isRgb(state.colors.wrapBackground, 0, 200, 83), '100%: banda verde de éxito');
			check(isRgb(state.colors.message, 18, 18, 18), '100%: texto negro sobre banda verde');
			check(state.colors.messageContrast >= 4.5, '100%: banda verde con contraste WCAG AA');
			check(state.icons.checkMaskDataSvg, '100%: check SVG aplicado');
			check(state.icons.checkWidth >= 15 && state.icons.checkHeight >= 15, '100%: check mantiene tamaño visible');
			check(isRgb(state.colors.check, 18, 18, 18), '100%: check negro sobre verde');
			check(state.colors.checkContrast >= 4.5, '100%: check con contraste WCAG AA');
		}
	}
	check(replacements.length === 4, 'fixture ejecuta los cuatro reemplazos de fragmento');
	check(replacements.every((replacement) => replacement.identityReplaced), 'cada estado reemplaza la identidad DOM del fragmento');
	check(
		replacements.slice(1).every((replacement) => replacement.previousIdentity && replacement.previousIdentity !== replacement.fixtureIdentity),
		'los reemplazos 80/99/100 descartan el wrap anterior como native-cart'
	);

	await page.emulateMedia({ reducedMotion: 'reduce' });
	await renderState(80);
	await waitTwoFrames();
	const reducedBefore = await measureState(80);
	await page.waitForTimeout(120);
	const reducedAfter = await measureState(80);
	check(reducedBefore.motion.reduced && reducedAfter.motion.reduced, 'reduced-motion activo en la medición');
	check(noDurations(reducedBefore.motion.fillTransitionDuration), 'reduced-motion elimina transición del fill');
	check(noDurations(reducedBefore.motion.truckTransitionDuration), 'reduced-motion elimina transición del camión');
	check(reducedBefore.motion.fillAnimationName === 'none', 'reduced-motion sin animación del fill');
	check(reducedBefore.motion.truckAnimationName === 'none', 'reduced-motion sin animación del camión');
	check(
		Math.abs(reducedBefore.geometry.fill.width - reducedAfter.geometry.fill.width) <= 0.1
		&& Math.abs(reducedBefore.geometry.truck.x - reducedAfter.geometry.truck.x) <= 0.1,
		'reduced-motion conserva geometría estable'
	);
	await page.locator('#akb-fsp-gate-shell').screenshot({
		path: 'state-80-reduced-motion-component.png',
		animations: 'disabled',
	});

	check(diagnostics.consoleErrors.length === 0, 'sin errores de consola');
	check(diagnostics.pageErrors.length === 0, 'sin errores JavaScript de página');
	check(diagnostics.requestFailures.length === 0, 'sin requests fallidas');
	check(diagnostics.httpErrors.length === 0, 'sin respuestas HTTP >=400');
	check(diagnostics.mutationRequests.length === 0, 'fixture sin requests mutativas');
	check(diagnostics.offOriginRequests.length === 0, 'fixture sin requests fuera del origen local');

	return {
		expected,
		result: failures.length === 0 ? 'PASS' : 'FAIL',
		failures,
		device,
		stylesheetLoaded,
		states,
		replacements,
		touchEvidence,
		reducedMotion: { before: reducedBefore, after: reducedAfter },
		diagnostics,
	};
}
