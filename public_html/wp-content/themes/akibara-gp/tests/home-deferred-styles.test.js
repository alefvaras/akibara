'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const themeRoot = join(__dirname, '..');
const performancePhp = readFileSync(
	join(themeRoot, 'inc/performance.php'),
	'utf8'
);

function deferredHandles() {
	const functionBody = performancePhp.match(
		/function akibara_gp_home_deferred_style_handles\(\): array \{([\s\S]*?)\n\}/
	);

	assert.ok(functionBody, 'deferred style function must remain present');

	return [...functionBody[1].matchAll(/'([^']+)'/g)].map(
		(match) => match[1]
	);
}

test('home critical viewport styles are never deferred', () => {
	// woocommerce.css y loop.css no tocan el primer viewport, pero son las
	// tarjetas de TODA la portada: diferidas, quien baja antes de que lleguen
	// ve tarjetas sin estilo y luego un salto de CLS 1,4-1,9, y las portadas
	// diferidas eligen su candidato con la tarjeta sin caja (bajan el original).
	// Medido el 2026-09-23 (PC-6, ver akibara_gp_home_deferred_style_handles()).
	const handles = deferredHandles();
	const criticalHandles = [
		'generate-style',
		'akibara-tokens',
		'akibara-fonts',
		'akibara-base',
		'akibara-header',
		'akibara-footer',
		'akibara-woocommerce',
		'akibara-loop',
		'akibara-home',
		'akibara-trust',
		'akb-announcement-bar',
		'akb-home-hero',
		'joinchat',
	];

	for (const handle of criticalHandles) {
		assert.ok(
			!handles.includes(handle),
			`${handle} must stay render-blocking on home`
		);
	}
});

test('deferred home styles preserve progressive enhancement', () => {
	// El gate de ruta se resuelve por SLUG en inc/page-targets.php, no por el ID
	// 210: un ID de wp_posts no sobrevive a una migración de contenido y dejaba
	// esta rama muerta en silencio.
	assert.match(performancePhp, /akibara_gp_is_home_surface\(\)/);
	assert.match(performancePhp, /media='print'/);
	assert.match(performancePhp, /this\.media='all'/);
	assert.match(performancePhp, /<noscript>/);
	assert.match(performancePhp, /data-akb-deferred-style='1'/);
});
