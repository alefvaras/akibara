'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const { createDOM, REPO_ROOT } = require( path.join( __dirname, '../../../../../../../tools/testing/dom-harness.js' ) );

const SCRIPT_PATH = path.join( REPO_ROOT, 'public_html/wp-content/plugins/akibara-business/assets/js/series-directory.js' );

function buildFixtureHtml() {
	return `
		<div class="akb-series-directory" data-akb-series-directory>
			<div class="akb-series-directory__filter" data-akb-series-filter hidden>
				<label class="akb-series-directory__filter-label" for="akb-series-directory-input">Buscar una serie o edición</label>
				<input type="search" id="akb-series-directory-input" class="akb-series-directory__input" autocomplete="off">
				<div class="akb-series-directory__quick-bar" role="toolbar" aria-label="Filtros rápidos y panel">
					<button type="button" class="akb-series-directory__toggle-filters" data-akb-toggle-facets aria-expanded="false" aria-controls="akb-series-facets-panel">
						<span class="akb-toggle-icon">⚡</span> Filtros <span class="akb-filter-badge" data-akb-filter-count hidden>0</span> <span class="akb-toggle-arrow">▾</span>
					</button>
					<div class="akb-series-directory__quick-chips">
						<button type="button" class="akb-series-directory__quick-chip" data-akb-quick-estado="completa" aria-pressed="false">📚 Completas</button>
						<button type="button" class="akb-series-directory__quick-chip" data-akb-quick-estado="tomo_unico" aria-pressed="false">📖 Tomo único</button>
					</div>
				</div>
				<div class="akb-series-directory__facets-panel" id="akb-series-facets-panel" data-akb-facets-panel hidden>
					<div class="akb-series-directory__facets">
						<div class="akb-series-directory__facet" role="group" aria-label="Filtrar por estado o formato de la edición">
							<span class="akb-series-directory__facet-label">Estado</span>
							<div class="akb-series-directory__chips">
								<button type="button" class="akb-series-directory__chip is-active" data-akb-filter-estado="" aria-pressed="true">Todas</button>
								<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="en_publicacion" aria-pressed="false">En publicación</button>
								<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="al_dia" aria-pressed="false">Al día</button>
								<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="completa" aria-pressed="false">Completas</button>
								<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="tomo_unico" aria-pressed="false">Tomo único</button>
								<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="en_pausa" aria-pressed="false">En pausa</button>
							</div>
						</div>
					</div>
				</div>
			</div>
			<p class="akb-series-directory__count" id="akb-series-directory-count" role="status" aria-live="polite" aria-atomic="true"
				data-akb-series-count-all="5 ediciones con tomos en catálogo"
				data-akb-series-count-filtered="%s de 5 ediciones con tomos en catálogo"
				data-akb-series-locale="es-CL">5 ediciones con tomos en catálogo</p>
			<p class="akb-series-directory__empty" data-akb-series-empty hidden>Ninguna serie o edición coincide.</p>
			<div id="akb-series-directory-results">
				<section class="akb-series-directory__group" id="akb-series-f" data-akb-series-group>
					<h2 class="akb-series-directory__heading">F</h2>
					<ul class="akb-series-directory__list">
						<li class="akb-series-directory__item" data-akb-serie="frieren kanehito yamada ivrea espana" data-akb-estado="al_dia">
							<a class="akb-series-directory__link" href="https://akibara.cl/serie/frieren/">
								<span class="akb-series-directory__name">Frieren</span>
								<span class="akb-series-directory__tomos">15 tomos en catálogo</span>
							</a>
						</li>
					</ul>
				</section>
				<section class="akb-series-directory__group" id="akb-series-n" data-akb-series-group>
					<h2 class="akb-series-directory__heading">N</h2>
					<ul class="akb-series-directory__list">
						<li class="akb-series-directory__item" data-akb-serie="naruto masashi kishimoto panini argentina" data-akb-estado="completa">
							<a class="akb-series-directory__link" href="https://akibara.cl/serie/naruto/">
								<span class="akb-series-directory__name">Naruto</span>
								<span class="akb-series-directory__tomos">5 de 72 tomos en catálogo</span>
							</a>
						</li>
					</ul>
				</section>
				<section class="akb-series-directory__group" id="akb-series-s" data-akb-series-group>
					<h2 class="akb-series-directory__heading">S</h2>
					<ul class="akb-series-directory__list">
						<li class="akb-series-directory__item" data-akb-serie="saga integral planeta comic espana" data-akb-estado="en_pausa">
							<a class="akb-series-directory__link" href="https://akibara.cl/serie/saga-integral/">
								<span class="akb-series-directory__name">Saga Integral</span>
								<span class="akb-series-directory__tomos">3 tomos en catálogo</span>
							</a>
						</li>
						<li class="akb-series-directory__item" data-akb-serie="sakamoto days ivrea argentina" data-akb-estado="en_publicacion">
							<a class="akb-series-directory__link" href="https://akibara.cl/serie/sakamoto-days/">
								<span class="akb-series-directory__name">Sakamoto Days</span>
								<span class="akb-series-directory__tomos">23 tomos en catálogo</span>
							</a>
						</li>
					</ul>
				</section>
				<section class="akb-series-directory__group" id="akb-series-u" data-akb-series-group>
					<h2 class="akb-series-directory__heading">U</h2>
					<ul class="akb-series-directory__list">
						<li class="akb-series-directory__item" data-akb-serie="uzumaki ivrea argentina" data-akb-estado="tomo_unico">
							<a class="akb-series-directory__link" href="https://akibara.cl/serie/uzumaki/">
								<span class="akb-series-directory__name">Uzumaki</span>
								<span class="akb-series-directory__tomos">1 tomo en catálogo</span>
							</a>
						</li>
					</ul>
				</section>
			</div>
		</div>
	`;
}

test( 'series-directory.js reveals filter bar and filters by Al día (al_dia) and En pausa (en_pausa) chips', () => {
	const dom = createDOM( {
		html: buildFixtureHtml(),
		url: 'https://akibara.cl/series/'
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const filterBox = dom.document.querySelector( '[data-akb-series-filter]' );
		assert.equal( filterBox.hasAttribute( 'hidden' ), false, 'El contenedor de filtros se revela al inicializar JS' );

		const countEl = dom.document.getElementById( 'akb-series-directory-count' );
		assert.equal( countEl.textContent, '5 ediciones con tomos en catálogo' );

		// Filtrar por "Al día"
		dom.click( '[data-akb-filter-estado="al_dia"]' );
		assert.equal(
			dom.document.querySelector( '[data-akb-filter-estado="al_dia"]' ).getAttribute( 'aria-pressed' ),
			'true'
		);
		assert.equal( countEl.textContent, '1 de 5 ediciones con tomos en catálogo' );
		assert.equal( dom.document.querySelector( '[data-akb-estado="al_dia"]' ).hasAttribute( 'hidden' ), false );
		assert.equal( dom.document.querySelector( '[data-akb-estado="en_pausa"]' ).hasAttribute( 'hidden' ), true );
		assert.equal( dom.document.querySelector( '[data-akb-estado="completa"]' ).hasAttribute( 'hidden' ), true );

		// Filtrar por "En pausa"
		dom.click( '[data-akb-filter-estado="en_pausa"]' );
		assert.equal(
			dom.document.querySelector( '[data-akb-filter-estado="en_pausa"]' ).getAttribute( 'aria-pressed' ),
			'true'
		);
		assert.equal(
			dom.document.querySelector( '[data-akb-filter-estado="al_dia"]' ).getAttribute( 'aria-pressed' ),
			'false'
		);
		assert.equal( countEl.textContent, '1 de 5 ediciones con tomos en catálogo' );
		assert.equal( dom.document.querySelector( '[data-akb-estado="en_pausa"]' ).hasAttribute( 'hidden' ), false );
		assert.equal( dom.document.querySelector( '[data-akb-estado="al_dia"]' ).hasAttribute( 'hidden' ), true );

		// Clic de nuevo en "En pausa" desactiva el filtro y vuelve a "Todas"
		dom.click( '[data-akb-filter-estado="en_pausa"]' );
		assert.equal(
			dom.document.querySelector( '[data-akb-filter-estado=""]' ).getAttribute( 'aria-pressed' ),
			'true'
		);
		assert.equal( countEl.textContent, '5 ediciones con tomos en catálogo' );
	} finally {
		dom.teardown();
	}
} );

test( 'series-directory.js filters by author name via search input', () => {
	const dom = createDOM( {
		html: buildFixtureHtml(),
		url: 'https://akibara.cl/series/'
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const input = dom.document.querySelector( '.akb-series-directory__input' );
		const countEl = dom.document.getElementById( 'akb-series-directory-count' );

		// Escribir autor "kishimoto"
		dom.fill( '.akb-series-directory__input', 'kishimoto' );

		assert.equal( countEl.textContent, '1 de 5 ediciones con tomos en catálogo' );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="naruto"]' ).hasAttribute( 'hidden' ), false );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="frieren"]' ).hasAttribute( 'hidden' ), true );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="saga"]' ).hasAttribute( 'hidden' ), true );

		// Escribir autor "yamada"
		dom.fill( '.akb-series-directory__input', 'yamada' );

		assert.equal( countEl.textContent, '1 de 5 ediciones con tomos en catálogo' );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="frieren"]' ).hasAttribute( 'hidden' ), false );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="naruto"]' ).hasAttribute( 'hidden' ), true );
	} finally {
		dom.teardown();
	}
} );

test( 'series-directory.js toggles facets panel and filters via quick chips', () => {
	const dom = createDOM( {
		html: buildFixtureHtml(),
		url: 'https://akibara.cl/series/'
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const toggleBtn = dom.document.querySelector( '[data-akb-toggle-facets]' );
		const panel = dom.document.getElementById( 'akb-series-facets-panel' );
		const badge = dom.document.querySelector( '[data-akb-filter-count]' );
		const countEl = dom.document.getElementById( 'akb-series-directory-count' );

		assert.equal( toggleBtn.getAttribute( 'aria-expanded' ), 'false' );
		assert.equal( panel.hasAttribute( 'hidden' ), true, 'El panel de facetas inicia colapsado' );
		assert.equal( badge.hasAttribute( 'hidden' ), true, 'El badge inicia oculto sin filtros' );

		// Abrir panel
		dom.click( '[data-akb-toggle-facets]' );
		assert.equal( toggleBtn.getAttribute( 'aria-expanded' ), 'true' );
		assert.equal( panel.hasAttribute( 'hidden' ), false, 'El panel se revela al pulsar el toggle' );

		// Cerrar panel
		dom.click( '[data-akb-toggle-facets]' );
		assert.equal( toggleBtn.getAttribute( 'aria-expanded' ), 'false' );
		assert.equal( panel.hasAttribute( 'hidden' ), true, 'El panel se colapsa nuevamente' );

		// Usar quick chip de "Completas"
		dom.click( '[data-akb-quick-estado="completa"]' );
		assert.equal( dom.document.querySelector( '[data-akb-quick-estado="completa"]' ).getAttribute( 'aria-pressed' ), 'true' );
		assert.equal( dom.document.querySelector( '[data-akb-filter-estado="completa"]' ).getAttribute( 'aria-pressed' ), 'true' );
		assert.equal( badge.textContent, '1' );
		assert.equal( badge.hasAttribute( 'hidden' ), false, 'Badge muestra 1 filtro activo' );
		assert.equal( toggleBtn.classList.contains( 'has-active-filters' ), true );

		// Comprobar que filtró a "Naruto" (único completo en fixture)
		assert.equal( countEl.textContent, '1 de 5 ediciones con tomos en catálogo' );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="naruto"]' ).hasAttribute( 'hidden' ), false );
		assert.equal( dom.document.querySelector( '[data-akb-serie*="frieren"]' ).hasAttribute( 'hidden' ), true );

		// Desactivar quick chip
		dom.click( '[data-akb-quick-estado="completa"]' );
		assert.equal( dom.document.querySelector( '[data-akb-quick-estado="completa"]' ).getAttribute( 'aria-pressed' ), 'false' );
		assert.equal( badge.hasAttribute( 'hidden' ), true, 'Badge se oculta al remover filtros' );
		assert.equal( countEl.textContent, '5 ediciones con tomos en catálogo' );
	} finally {
		dom.teardown();
	}
} );
