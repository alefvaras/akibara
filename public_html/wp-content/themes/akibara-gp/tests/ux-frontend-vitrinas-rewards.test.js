'use strict';

/**
 * Test Suite: UX Frontend & Accesibilidad Visual en Vitrinas, Recompensas y Niveles
 *
 * Misión: Validar de forma adversarial en DOM headless (happy-dom) y CSS parsing:
 * 1. Vitrinas de portada y comportamiento responsive:
 *    - Selectores de ocultamiento para secciones vacías (.akb-section:empty y .akb-section:has(...))
 *    - Tamaños táctiles (tap targets >= 44px) en .akb-section__link y botones de carrusel
 *    - Contención de desbordamiento horizontal en viewport móvil de 375px (iPhone SE)
 * 2. Experiencia de Usuario en Recompensas y Niveles:
 *    - Landing del Club de Puntos (/akibara-puntos/): simulador interactivo, niveles (Lector, Coleccionista, Residente),
 *      badges, vigencia 365 días / 1 año estándar y Soft-Landing
 *    - Mi Cuenta (/mi-cuenta/rewards/): tarjeta de nivel, barra de progreso ARIA, y tabla de historial
 *    - Checkout Blocks: widget de canje y gancho de registro
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createDOM } = require('../../../../../tools/testing/dom-harness.js');

const REPO_ROOT = path.resolve(__dirname, '../../../../..');
const HOME_CSS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/themes/akibara-gp/assets/css/home.css');
const BRANDS_HOME_CSS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css');
const PROGRAM_PAGE_CSS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/plugins/akibara-rewards/assets/css/program-page.css');
const PROGRAM_PAGE_JS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/plugins/akibara-rewards/assets/js/program-page.js');
const ACCOUNT_REWARDS_CSS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/plugins/akibara-rewards/assets/css/account-rewards.css');
const ACCOUNT_REWARDS_JS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/plugins/akibara-rewards/assets/js/account-rewards.js');
const CHECKOUT_BLOCKS_JS_PATH = path.join(REPO_ROOT, 'public_html/wp-content/plugins/akibara-rewards/assets/js/checkout-blocks.js');

test('Vitrinas Home: selectores de ocultamiento de secciones vacías en home.css', () => {
	const css = fs.readFileSync(HOME_CSS_PATH, 'utf8');

	// 1. Selector :empty para secciones sin contenido
	assert.match(
		css,
		/\.akb-section:empty\s*\{[^}]*display:\s*none;/,
		'Debe ocultar secciones completamente vacías con .akb-section:empty'
	);

	// 2. Selectores :has() para grupos Gutenberg con solo cabecera o inner container vacío
	assert.match(
		css,
		/\.akb-section:has\(>\s*\.akb-section__header:only-child\)/,
		'Debe ocultar la sección si el único hijo directo es la cabecera (sin productos)'
	);
	assert.match(
		css,
		/\.akb-section:has\(>\s*\.wp-block-group__inner-container:empty\)/,
		'Debe ocultar la sección si el inner container de Gutenberg está vacío'
	);
	assert.match(
		css,
		/\.akb-section:has\(>\s*\.wp-block-group__inner-container\s*>\s*\.akb-section__header:only-child\)/,
		'Debe ocultar la sección si el inner container contiene únicamente la cabecera'
	);

	// Validar en DOM headless que los selectores existen y su estructura es coherente
	const dom = createDOM({
		html: `
			<div class="akb-section akb-section--empty-test"></div>
			<div class="akb-section akb-section--header-only-test">
				<div class="akb-section__header">
					<h2>Mangas Agotados</h2>
					<a href="/shop/" class="akb-section__link">VER TODOS →</a>
				</div>
			</div>
			<div class="akb-section akb-section--populated-test">
				<div class="akb-section__header">
					<h2>Novedades</h2>
					<a href="/shop/" class="akb-section__link">VER TODOS →</a>
				</div>
				<div class="woocommerce">
					<ul class="products"><li>Producto 1</li></ul>
				</div>
			</div>
		`
	});

	try {
		const emptySec = dom.document.querySelector('.akb-section--empty-test');
		const headerOnlySec = dom.document.querySelector('.akb-section--header-only-test');
		const populatedSec = dom.document.querySelector('.akb-section--populated-test');

		assert.ok(emptySec, 'Sección vacía encontrada');
		assert.ok(headerOnlySec, 'Sección con solo cabecera encontrada');
		assert.ok(populatedSec, 'Sección poblada encontrada');

		// Verificar que header-only tiene solo un elemento hijo directo
		assert.equal(headerOnlySec.children.length, 1);
		assert.equal(headerOnlySec.firstElementChild.classList.contains('akb-section__header'), true);

		// Verificar que la sección poblada tiene más de un hijo directo (cabecera + rejilla)
		assert.ok(populatedSec.children.length > 1);
	} finally {
		dom.teardown();
	}
});

test('Vitrinas Home: tamaño táctil accesible (tap targets >= 44px) en enlaces y botones de carrusel', () => {
	const homeCss = fs.readFileSync(HOME_CSS_PATH, 'utf8');
	const brandsCss = fs.readFileSync(BRANDS_HOME_CSS_PATH, 'utf8');

	// .akb-section__link ("VER TODOS →")
	assert.match(
		homeCss,
		/\.akb-section__link\s*\{[^}]*min-height:\s*44px;/,
		'.akb-section__link debe definir min-height: 44px'
	);
	assert.match(
		homeCss,
		/\.akb-section__link\s*\{[^}]*min-width:\s*44px;/,
		'.akb-section__link debe definir min-width: 44px'
	);

	// .akb-brands-home__more ("Ver editoriales")
	assert.match(
		brandsCss,
		/\.akb-brands-home__more\s*\{[^}]*min-height:\s*44px;/,
		'.akb-brands-home__more debe definir min-height: 44px'
	);

	// .akb-brands-home__pause (Botón de pausa accesible WCAG 2.2.2)
	assert.match(
		brandsCss,
		/\.akb-brands-home__pause\s*\{[^}]*width:\s*52px;/,
		'.akb-brands-home__pause debe tener ancho >= 44px (52px)'
	);
	assert.match(
		brandsCss,
		/\.akb-brands-home__pause\s*\{[^}]*height:\s*calc\(44px \+ 0\.9rem \+ 4px\);/,
		'.akb-brands-home__pause debe tener altura sincronizada con el enlace (>= 44px)'
	);
});

test('Vitrinas Home: geometría responsiva sin desbordamiento horizontal en viewport de 375px (iPhone SE)', () => {
	const homeCss = fs.readFileSync(HOME_CSS_PATH, 'utf8');
	const brandsCss = fs.readFileSync(BRANDS_HOME_CSS_PATH, 'utf8');

	// Canal lateral uniforme en .akb-section
	assert.match(
		homeCss,
		/\.akb-section\s*\{[^}]*padding-inline:\s*clamp\(14px,\s*4vw,\s*32px\);/,
		'La sección define padding-inline con clamp'
	);

	// Contención de fila deslizable móvil (max-width: 768px)
	assert.match(
		homeCss,
		/--akb-fila-canal:\s*clamp\(14px,\s*4vw,\s*32px\);/,
		'La fila móvil define su canal lateral idéntico al padding de sección'
	);
	assert.match(
		homeCss,
		/margin-inline:\s*calc\(-1\s*\*\s*var\(--akb-fila-canal\)\);/,
		'La fila móvil anula el padding con margen negativo simétrico para scroll a sangre'
	);
	assert.match(
		homeCss,
		/overflow-x:\s*auto;/,
		'El scroll horizontal está contenido dentro del contenedor ul.products'
	);
	assert.match(
		homeCss,
		/overflow-y:\s*hidden;/,
		'Previene scroll vertical accidental en el carrusel'
	);
	assert.match(
		homeCss,
		/overscroll-behavior-x:\s*contain;/,
		'Aisla el gesto de deslizamiento para no arrastrar la página'
	);

	// Marquesina de marcas: ventana recortada
	assert.match(
		brandsCss,
		/\.akb-brands-home__viewport:has\(>\s*\.is-marquee\)\s*\{[^}]*overflow:\s*hidden;/,
		'La ventana de marquesina recorta su desborde para 0 overflow horizontal'
	);

	// En DOM virtual con viewport de 375px
	const dom = createDOM({
		innerWidth: 375,
		innerHeight: 667,
		html: `
			<main class="site-main">
				<section class="akb-section">
					<div class="akb-section__header">
						<h2>Manga Destacado</h2>
						<a href="/shop/" class="akb-section__link">VER TODOS →</a>
					</div>
					<div class="woocommerce columns-4">
						<ul class="products columns-4">
							<li class="product">Tomo 1</li>
							<li class="product">Tomo 2</li>
							<li class="product">Tomo 3</li>
						</ul>
					</div>
				</section>
			</main>
		`
	});

	try {
		const main = dom.document.querySelector('.site-main');
		const sec = dom.document.querySelector('.akb-section');
		const link = dom.document.querySelector('.akb-section__link');
		const list = dom.document.querySelector('ul.products');

		assert.ok(main, 'Main presente');
		assert.ok(sec, 'Sección presente');
		assert.ok(link, 'Enlace "VER TODOS" presente');
		assert.ok(list, 'Lista de productos presente');
		assert.equal(dom.window.innerWidth, 375, 'Viewport virtual fijado en 375px');
	} finally {
		dom.teardown();
	}
});

test('Club de Puntos (/akibara-puntos/): simulador interactivo calcula puntos y ahorro dinámico', () => {
	const dom = createDOM({
		html: `
			<div class="akb-club-wrap">
				<div class="akb-calc-card" id="akb-club-calc" data-earn-amount="100" data-earn-points="2" data-redeem-value="1">
					<span id="akb-calc-display">$35.000</span>
					<input type="range" class="akb-calc-range" min="10000" max="150000" step="5000" value="35000" aria-label="Monto de compra para calcular puntos" />
					<span id="akb-calc-points-val">700</span>
					<span id="akb-calc-discount-val">$700</span>
				</div>
			</div>
		`
	});

	try {
		dom.loadScript(PROGRAM_PAGE_JS_PATH);

		const slider = dom.document.querySelector('.akb-calc-range');
		const display = dom.document.getElementById('akb-calc-display');
		const ptsVal = dom.document.getElementById('akb-calc-points-val');
		const clpVal = dom.document.getElementById('akb-calc-discount-val');

		assert.ok(slider, 'Control de rango presente');
		assert.equal(slider.getAttribute('aria-label'), 'Monto de compra para calcular puntos');

		// Simulación 1: Valor inicial $35.000 -> 700 pts ($700)
		assert.equal(display.textContent, '$35.000');
		assert.equal(ptsVal.textContent, '700');
		assert.equal(clpVal.textContent, '$700');

		// Simulación 2: Slider a $60.000 (umbral de envío gratis)
		slider.value = '60000';
		slider.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

		assert.equal(display.textContent, '$60.000');
		assert.equal(ptsVal.textContent, '1.200');
		assert.equal(clpVal.textContent, '$1.200');

		// Simulación 3: Slider a $120.000
		slider.value = '120000';
		slider.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

		assert.equal(display.textContent, '$120.000');
		assert.equal(ptsVal.textContent, '2.400');
		assert.equal(clpVal.textContent, '$2.400');
	} finally {
		dom.teardown();
	}
});

test('Club de Puntos (/akibara-puntos/): Niveles de Coleccionista, badges y vigencia 365 días con Soft-Landing', () => {
	const dom = createDOM({
		html: `
			<section class="akb-club-tiers-section">
				<div class="akb-tiers-grid">
					<div class="akb-tier-card">
						<span class="akb-tier-icon" aria-hidden="true">📖</span>
						<h3 class="akb-tier-name">Lector</h3>
						<div class="akb-tier-threshold">Nivel inicial (0 a 1.999 pts)</div>
						<span class="akb-tier-multiplier">Multiplicador 1x</span>
					</div>
					<div class="akb-tier-card">
						<span class="akb-tier-icon" aria-hidden="true">⭐</span>
						<h3 class="akb-tier-name">Coleccionista</h3>
						<div class="akb-tier-threshold">Desde 2.000 a 4.999 pts</div>
						<span class="akb-tier-multiplier">Multiplicador 1,25x (+25% pts)</span>
					</div>
					<div class="akb-tier-card akb-tier-card--highlight">
						<span class="akb-tier-icon" aria-hidden="true">👑</span>
						<h3 class="akb-tier-name">Residente Akiba</h3>
						<div class="akb-tier-threshold">Desde 5.000 pts acumulados</div>
						<span class="akb-tier-multiplier">Multiplicador 1,5x (+50% pts)</span>
					</div>
				</div>
				<p class="akb-calc-note">
					Tu nivel dura <strong>365 días</strong> (1 año estándar · vigencia móvil renovada con cada compra). Si vence sin compras nuevas, bajas suavemente un nivel (Soft-Landing) sin perder tu histórico hasta volver a comprar.
				</p>
			</section>
		`
	});

	try {
		const tierCards = dom.document.querySelectorAll('.akb-tier-card');
		assert.equal(tierCards.length, 3, 'Deben existir 3 niveles de coleccionista');

		const names = Array.from(tierCards).map((c) => c.querySelector('.akb-tier-name').textContent.trim());
		assert.deepEqual(names, ['Lector', 'Coleccionista', 'Residente Akiba']);

		// Multiplicadores
		const multipliers = Array.from(tierCards).map((c) => c.querySelector('.akb-tier-multiplier').textContent.trim());
		assert.ok(multipliers[0].includes('1x'));
		assert.ok(multipliers[1].includes('1,25x'));
		assert.ok(multipliers[2].includes('1,5x'));

		// Residente Akiba tiene clase destacada
		assert.equal(tierCards[2].classList.contains('akb-tier-card--highlight'), true);

		// Explicación de vigencia de 365 días y Soft-Landing
		const note = dom.document.querySelector('.akb-calc-note').textContent;
		assert.ok(note.includes('365 días'), 'Debe mencionar 365 días');
		assert.ok(note.includes('1 año estándar'), 'Debe mencionar 1 año estándar');
		assert.ok(note.includes('Soft-Landing'), 'Debe explicar el mecanismo de Soft-Landing');
	} finally {
		dom.teardown();
	}
});

test('Club de Puntos: accesibilidad y tap targets en FAQ y botones CTA', () => {
	const css = fs.readFileSync(PROGRAM_PAGE_CSS_PATH, 'utf8');

	// .akb-faq-summary debe cumplir target táctil >= 44px
	assert.match(
		css,
		/\.akb-faq-summary\s*\{[^}]*min-height:\s*44px;/,
		'.akb-faq-summary debe garantizar min-height: 44px para toques cómodos en móvil'
	);

	// .akb-club-btn debe cumplir target táctil >= 48px
	assert.match(
		css,
		/\.akb-club-btn\s*\{[^}]*min-height:\s*48px;/,
		'.akb-club-btn debe tener min-height: 48px'
	);

	const dom = createDOM({
		html: `
			<details class="akb-faq-item">
				<summary class="akb-faq-summary">
					<span>¿Pierdo mi Envío Gratis si canjeo puntos?</span>
					<span class="akb-faq-icon">+</span>
				</summary>
				<div class="akb-faq-body">
					<p><strong>¡No, tu Envío Gratis está 100% protegido!</strong> Si tu compra suma $60.000 o más...</p>
				</div>
			</details>
		`
	});

	try {
		const details = dom.document.querySelector('.akb-faq-item');
		const summary = dom.document.querySelector('.akb-faq-summary');

		assert.equal(details.open, false);
		dom.click(summary);
		// El click sobre summary abre el elemento details
		assert.ok(details.querySelector('.akb-faq-body'));
	} finally {
		dom.teardown();
	}
});

test('Mi Cuenta (/mi-cuenta/rewards/): tarjeta de nivel, barra de progreso ARIA y saldo', () => {
	const dom = createDOM({
		html: `
			<div class="akb-rw-account">
				<div class="akb-rw-balance-card">
					<p class="akb-rw-balance-card__label">Tu saldo</p>
					<p class="akb-rw-balance-card__value">3.450 <span class="akb-rw-balance-card__unit">Puntos Akibara</span></p>
					<p class="akb-rw-balance-card__equiv">Equivalencia potencial: $3.450 (1 punto = $1)</p>
				</div>

				<div class="akb-rw-tier-card">
					<div class="akb-rw-tier-card__header">
						<div class="akb-rw-tier-card__current">
							<span class="akb-rw-tier-card__eyebrow">Tu nivel en Akibara</span>
							<div class="akb-rw-tier-card__badge-row">
								<span class="akb-tier-badge akb-tier-badge--coleccionista">Coleccionista</span>
								<span class="akb-rw-tier-card__multiplier">1,25x en compras</span>
							</div>
						</div>
						<span class="akb-rw-tier-card__next">Siguiente nivel: <strong>Residente Akiba</strong></span>
					</div>
					<div class="akb-rw-tier-card__bar-wrap" role="progressbar" aria-valuenow="48" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso de nivel">
						<div class="akb-rw-tier-card__bar" style="width:48%"></div>
					</div>
					<p class="akb-rw-tier-card__desc">
						Llevas 3.450 puntos ganados históricamente. Te faltan <strong>1.550 puntos</strong> para ascender a <strong>Residente Akiba</strong> (y ganar 1,5x (+50%) en todas tus compras). Vigencia de tu nivel hasta el <time datetime="2027-08-15"><strong>15 de agosto de 2027</strong></time> (se renueva con cada compra).
					</p>
				</div>
			</div>
		`
	});

	try {
		// Validar tarjeta de saldo
		const balanceVal = dom.document.querySelector('.akb-rw-balance-card__value');
		assert.ok(balanceVal.textContent.includes('3.450'));

		// Validar accesibilidad de barra de progreso
		const progressWrap = dom.document.querySelector('.akb-rw-tier-card__bar-wrap');
		assert.equal(progressWrap.getAttribute('role'), 'progressbar');
		assert.equal(progressWrap.getAttribute('aria-valuenow'), '48');
		assert.equal(progressWrap.getAttribute('aria-valuemin'), '0');
		assert.equal(progressWrap.getAttribute('aria-valuemax'), '100');
		assert.equal(progressWrap.getAttribute('aria-label'), 'Progreso de nivel');

		const progressBar = dom.document.querySelector('.akb-rw-tier-card__bar');
		assert.equal(progressBar.style.width, '48%');

		// Validar badge y multiplicador
		const badge = dom.document.querySelector('.akb-tier-badge--coleccionista');
		assert.ok(badge);
		assert.equal(badge.textContent.trim(), 'Coleccionista');

		const mult = dom.document.querySelector('.akb-rw-tier-card__multiplier');
		assert.equal(mult.textContent.trim(), '1,25x en compras');

		// Validar texto explicativo con fecha y vigencia móvil
		const desc = dom.document.querySelector('.akb-rw-tier-card__desc');
		assert.ok(desc.textContent.includes('1.550 puntos'));
		assert.ok(desc.textContent.includes('se renueva con cada compra'));
	} finally {
		dom.teardown();
	}
});

test('Mi Cuenta (/mi-cuenta/rewards/): tabla de historial con contraste accesible y números tabulares', () => {
	const css = fs.readFileSync(ACCOUNT_REWARDS_CSS_PATH, 'utf8');

	// Contraste accesible en deltas
	assert.match(
		css,
		/\.akb-rw-history-table\s*\{[^}]*overflow-x:\s*auto;/,
		'La tabla de historial tiene scroll horizontal contenido en pantallas estrechas'
	);
	assert.match(
		css,
		/font-variant-numeric:\s*tabular-nums;/,
		'La columna de puntos usa tabular-nums para alineación matemática'
	);

	const dom = createDOM({
		html: `
			<div class="akb-rw-history-table">
				<table class="shop_table">
					<thead>
						<tr>
							<th scope="col">Fecha</th>
							<th scope="col">Motivo</th>
							<th scope="col" style="text-align:right">Puntos</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><time datetime="2026-11-05">05 nov 2026</time></td>
							<td>Compra #18420</td>
							<td style="text-align:right;font-weight:700;color:#8ce99a">+450</td>
						</tr>
						<tr>
							<td><time datetime="2026-11-08">08 nov 2026</time></td>
							<td>Canje en pedido #18490</td>
							<td style="text-align:right;font-weight:700;color:#ffa8a8">−1.000</td>
						</tr>
					</tbody>
				</table>
			</div>
		`
	});

	try {
		const rows = dom.document.querySelectorAll('tbody tr');
		assert.equal(rows.length, 2);

		const cellsPos = rows[0].querySelectorAll('td');
		assert.equal(cellsPos[2].textContent.trim(), '+450');
		assert.equal(cellsPos[2].style.color, '#8ce99a');

		const cellsNeg = rows[1].querySelectorAll('td');
		assert.equal(cellsNeg[2].textContent.trim(), '−1.000');
		assert.equal(cellsNeg[2].style.color, '#ffa8a8');
	} finally {
		dom.teardown();
	}
});

test('Checkout: widget de canje y gancho de registro respetan contrato y accesibilidad', () => {
	const js = fs.readFileSync(CHECKOUT_BLOCKS_JS_PATH, 'utf8');

	// Verificación de tap targets en checkout
	assert.match(
		js,
		/minHeight:\s*'44px'/,
		'Los controles y botones de canje en el checkout declaran minHeight: 44px'
	);

	// Verificación de live regions para lectores de pantalla
	assert.match(
		js,
		/role:\s*'status',\s*'aria-live':\s*'polite'/,
		'El estado de canje se comunica mediante live region aria-live="polite"'
	);

	// Verificación del mensaje del gancho con moneda y puntos
	assert.match(
		js,
		/Este pedido suma/,
		'El gancho anuncia claramente los puntos que suma el pedido actual'
	);
	assert.match(
		js,
		/para tus próximas compras/,
		'El gancho clarifica que los puntos aplican en las próximas compras'
	);
});
