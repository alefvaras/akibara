'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const { createDOM, REPO_ROOT } = require( path.join( __dirname, '../../../../../../../tools/testing/dom-harness.js' ) );

const SCRIPT_PATH = path.join( REPO_ROOT, 'public_html/wp-content/plugins/akibara-business/assets/js/series-multiselect.js' );

function buildSeriesFixtureHtml() {
	return `
		<div class="akb-series-select-toolbar" data-akb-series-select-toolbar>
			<div class="akb-series-select-toolbar__actions">
				<button type="button" class="akb-series-select-toolbar__btn" data-akb-select-all aria-pressed="false">
					Seleccionar disponibles
				</button>
				<button type="button" class="akb-series-select-toolbar__btn" data-akb-clear-selection hidden>
					Deseleccionar
				</button>
				<span class="akb-series-select-toolbar__status" data-akb-toolbar-status hidden></span>
			</div>
			<div class="akb-series-range-selector" data-akb-range-selector>
				<input type="number" class="akb-range-selector__input" data-akb-range-from placeholder="1">
				<input type="number" class="akb-range-selector__input" data-akb-range-to placeholder="12">
				<button type="button" class="akb-series-select-toolbar__btn" data-akb-range-apply>
					Seleccionar tramo
				</button>
			</div>
		</div>

		<div data-akb-series-purchase-preview hidden></div>
		<ul class="products">
			<li class="product">
				<div class="akb-series-card-select">
					<label class="akb-series-card-select__label">
						<input type="checkbox" class="akb-series-card-select__input" data-akb-volume-checkbox
							data-product-id="101" data-price="9990" data-regular-price="9990" data-is-sale="0" data-volume-number="1">
					</label>
				</div>
				<h2 class="woocommerce-loop-product__title">Chainsaw Man 01</h2>
			</li>
			<li class="product">
				<div class="akb-series-card-select">
					<label class="akb-series-card-select__label">
						<input type="checkbox" class="akb-series-card-select__input" data-akb-volume-checkbox
							data-product-id="102" data-price="8990" data-regular-price="9990" data-is-sale="1" data-volume-number="2">
					</label>
				</div>
				<h2 class="woocommerce-loop-product__title">Chainsaw Man 02 (Oferta)</h2>
			</li>
			<li class="product">
				<div class="akb-series-card-select">
					<label class="akb-series-card-select__label">
						<input type="checkbox" class="akb-series-card-select__input" data-akb-volume-checkbox
							data-product-id="103" data-price="45000" data-regular-price="45000" data-is-sale="0" data-volume-number="3">
					</label>
				</div>
				<h2 class="woocommerce-loop-product__title">Chainsaw Man Boxset</h2>
			</li>
			<li class="product">
				<div class="akb-series-card-select">
					<span class="akb-series-card-select__disabled">🔒</span>
				</div>
				<h2 class="woocommerce-loop-product__title">Chainsaw Man 04 (Agotado)</h2>
			</li>
		</ul>

		<div class="akb-series-action-bar" id="akb-series-action-bar" data-akb-series-action-bar hidden>
			<div class="akb-series-action-bar__inner">
				<div class="akb-series-action-bar__summary">
					<div class="akb-series-action-bar__info">
						<span class="akb-series-action-bar__count" data-akb-action-bar-count>0 tomos seleccionados</span>
						<span class="akb-series-action-bar__total" data-akb-action-bar-total>$0</span>

					</div>
					<button type="button" class="akb-series-action-bar__submit" data-akb-batch-submit>
						<span class="akb-submit-label" data-akb-submit-label>Añadir al carrito</span>
					</button>
				</div>
			</div>
		</div>
	`;
}

function loadSeries( dom, editionProducts ) {
	const config = dom.window.akbSeriesBatchConfig || {};
	config.editionProducts = editionProducts === undefined ? [
		{ id: 101, volume: 1, price: '9990', stock_status: 'instock', purchasable: true, type: 'simple' },
		{ id: 102, volume: 2, price: '8990', stock_status: 'instock', purchasable: true, type: 'simple' },
		{ id: 103, volume: 3, price: '45000', stock_status: 'instock', purchasable: true, type: 'simple' }
	] : editionProducts;
	dom.window.akbSeriesBatchConfig = { purchase_incentives: { rewards: {}, shipping: { enabled: true, threshold: 60000 } }, ...config, i18n: {
		selected_singular: '1 tomo seleccionado', selected_plural: '%s tomos seleccionados', add_selection: 'Añadir selección (%s)', adding: 'Añadiendo...',
		free_shipping_ready: '🎉 ¡Envío GRATIS a domicilio desbloqueado!', free_shipping_short: 'Te faltan %s para Envío GRATIS a domicilio', ...config.i18n
	} };
	dom.loadScript( path.join( path.dirname( SCRIPT_PATH ), 'batch-cart.js' ) );
	dom.loadScript( path.join( path.dirname( SCRIPT_PATH ), 'edition-products.js' ) );
	dom.loadScript( path.join( path.dirname( SCRIPT_PATH ), 'purchase-incentives.js' ) );
	dom.loadScript( SCRIPT_PATH );
}

test( 'range selects the full edition beyond nineteen rendered cards, deduplicates variants and retains failed or newly selected IDs', async ( t ) => {
	const products = Array.from( { length: 109 }, ( _, index ) => ( { id: index + 101, volume: index + 1, price: '1000', stock_status: index === 4 ? 'outofstock' : 'instock', purchasable: true, type: 'simple' } ) );
	products.push( { ...products[ 1 ], id: 999, variant: true } );
	const visible = products.slice( 0, 19 ).filter( ( product ) => product.stock_status === 'instock' ).map( ( product ) => '<li class="product"><input type="checkbox" data-akb-volume-checkbox data-product-id="' + product.id + '" data-volume-number="' + product.volume + '" data-price="1000"></li>' ).join( '' );
	const dom = createDOM( { html: buildSeriesFixtureHtml().replace( /<ul class="products">[\s\S]*?<\/ul>/, '<ul class="products">' + visible + '</ul>' ) } );
	t.after( () => dom.teardown() );
	loadSeries( dom, products );
	const sent = [];
	let acknowledge;
	dom.window.akbBatchCart = { add( payload ) {
		sent.push( Array.from( payload.productIds ) );
		return new Promise( ( resolve ) => { acknowledge = resolve; } );
	} };
	dom.fill( '[data-akb-range-from]', '1' );
	dom.fill( '[data-akb-range-to]', '30' );
	dom.click( '[data-akb-range-apply]' );
	assert.equal( dom.document.querySelector( '[data-akb-action-bar-count]' ).textContent, '29 tomos seleccionados' );
	assert.equal( dom.document.querySelector( '[data-akb-action-bar-total]' ).textContent, '$29.000' );
	assert.equal( dom.document.querySelector( '[data-akb-toolbar-status]' ).textContent, '29 seleccionados · 11 en otras páginas' );
	dom.click( '[data-akb-batch-submit]' );
	assert.deepEqual( sent[ 0 ], products.slice( 0, 30 ).filter( ( product ) => product.stock_status === 'instock' ).map( ( product ) => product.id ) );
	assert.ok( ! sent[ 0 ].includes( 999 ), 'La selección masiva compra un único producto por número' );
	dom.fill( '[data-akb-range-from]', '40' );
	dom.fill( '[data-akb-range-to]', '41' );
	dom.click( '[data-akb-range-apply]' );
	acknowledge( { failed_ids: [ 125 ], added_ids: sent[ 0 ].filter( ( id ) => id !== 125 ), already_ids: [] } );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	dom.click( '[data-akb-batch-submit]' );
	assert.deepEqual( sent[ 1 ], [ 140, 141 ], 'La respuesta anterior conserva la nueva selección del cliente' );
	acknowledge( { failed_ids: [ 140 ], added_ids: [ 141 ], already_ids: [] } );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	dom.click( '[data-akb-batch-submit]' );
	assert.deepEqual( sent[ 2 ], [ 140 ], 'El reintento incluye solo el tomo fallido, aunque no esté en el DOM' );
} );

test( 'unavailable full-edition snapshot disables bulk and range with a visible explanation but keeps individual buying', ( t ) => {
	const dom = createDOM( { html: buildSeriesFixtureHtml() } );
	t.after( () => dom.teardown() );
	loadSeries( dom, null );
	assert.equal( dom.document.querySelector( '[data-akb-select-all]' ).disabled, true );
	assert.equal( dom.document.querySelector( '[data-akb-range-apply]' ).disabled, true );
	assert.equal( dom.document.querySelector( '[data-akb-toolbar-status]' ).hidden, false );
	assert.ok( dom.document.querySelector( '[data-akb-toolbar-status]' ).textContent.includes( 'tomos visibles' ) );
	const checkbox = dom.document.querySelector( '[data-product-id="101"]' );
	checkbox.checked = true;
	checkbox.dispatchEvent( new dom.window.Event( 'change' ) );
	assert.equal( dom.document.querySelector( '[data-akb-action-bar-count]' ).textContent, '1 tomo seleccionado' );
	assert.equal( dom.document.querySelector( '[data-akb-batch-submit]' ).disabled, false );
} );

test( 'series-multiselect.js calculates totals, respects sale price, and updates FSP progress', () => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/chainsaw-man/'
	} );

	try {
		loadSeries( dom );

		const actionBar = dom.document.getElementById( 'akb-series-action-bar' );
		const preview = dom.document.querySelector( '[data-akb-series-purchase-preview]' );
		const countEl = dom.document.querySelector( '[data-akb-action-bar-count]' );
		const totalEl = dom.document.querySelector( '[data-akb-action-bar-total]' );

		assert.equal( actionBar.hasAttribute( 'hidden' ), true, 'La barra flotante inicia oculta sin selección' );

		// Seleccionar Tomo 1 ($9.990 normal)
		const cb1 = dom.document.querySelector( '[data-product-id="101"]' );
		cb1.checked = true;
		cb1.dispatchEvent( new dom.window.Event( 'change' ) );

		assert.equal( actionBar.hasAttribute( 'hidden' ), false, 'La barra se revela al seleccionar un tomo' );
		assert.equal( countEl.textContent, '1 tomo seleccionado' );
		assert.equal( totalEl.textContent, '$9.990' );
		assert.match( preview.textContent, /50\.010/ );
		assert.equal( actionBar.classList.contains( 'is-qualified' ), false );
		assert.equal( cb1.closest( 'li.product' ).classList.contains( 'akb-volume-selected' ), true, 'La tarjeta del tomo marcado adquiere akb-volume-selected' );
		assert.equal( dom.document.body.classList.contains( 'akb-has-series-action-bar' ), true, 'El body adquiere la clase de barra activa para el lift de WhatsApp' );

		// Seleccionar Tomo 2 en oferta ($8.990) -> Total $18.980
		const cb2 = dom.document.querySelector( '[data-product-id="102"]' );
		cb2.checked = true;
		cb2.dispatchEvent( new dom.window.Event( 'change' ) );

		assert.equal( countEl.textContent, '2 tomos seleccionados' );
		assert.equal( totalEl.textContent, '$18.980' );
		assert.match( preview.textContent, /41\.020/ );

		// Seleccionar Boxset ($45.000) -> Total $63.980 (Supera $60.000 CLP de envío gratis)
		const cb3 = dom.document.querySelector( '[data-product-id="103"]' );
		cb3.checked = true;
		cb3.dispatchEvent( new dom.window.Event( 'change' ) );

		assert.equal( countEl.textContent, '3 tomos seleccionados' );
		assert.equal( totalEl.textContent, '$63.980' );
		assert.ok( preview.textContent.includes( 'referencia cubierta' ) );
		assert.ok( ! preview.textContent.includes( 'desbloqueado' ) );
		assert.equal( actionBar.classList.contains( 'is-qualified' ), false );
		assert.equal( preview.querySelector( 'progress' ).value, 100 );

		// Desmarcar todo vía botón Deseleccionar
		dom.click( '[data-akb-clear-selection]' );
		assert.equal( cb1.checked, false );
		assert.equal( cb2.checked, false );
		assert.equal( cb3.checked, false );
		assert.equal( cb1.closest( 'li.product' ).classList.contains( 'akb-volume-selected' ), false, 'La tarjeta pierde akb-volume-selected' );
		assert.equal( dom.document.body.classList.contains( 'akb-has-series-action-bar' ), false, 'El body retira akb-has-series-action-bar' );
		assert.equal( actionBar.hasAttribute( 'hidden' ), true, 'La barra se oculta al deseleccionar todo' );
	} finally {
		dom.teardown();
	}
} );

test( 'series-multiselect.js toolbar "Seleccionar disponibles" selects all buyable volumes', () => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/chainsaw-man/'
	} );

	try {
		loadSeries( dom );

		const actionBar = dom.document.getElementById( 'akb-series-action-bar' );
		const countEl = dom.document.querySelector( '[data-akb-action-bar-count]' );
		const selectAllBtn = dom.document.querySelector( '[data-akb-select-all]' );

		// Clic en "Seleccionar disponibles" (hay 3 disponibles, 1 agotado)
		dom.click( '[data-akb-select-all]' );

		assert.equal( selectAllBtn.getAttribute( 'aria-pressed' ), 'true' );
		assert.equal( actionBar.hasAttribute( 'hidden' ), false );
		assert.equal( countEl.textContent, '3 tomos seleccionados' );

		// Clic de nuevo para deseleccionar todo
		dom.click( '[data-akb-select-all]' );
		assert.equal( selectAllBtn.getAttribute( 'aria-pressed' ), 'false' );
		assert.equal( actionBar.hasAttribute( 'hidden' ), true );
	} finally {
		dom.teardown();
	}
} );

test( 'series-multiselect.js range selector marks only volumes within [from, to] range', () => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/one-piece/'
	} );

	try {
		loadSeries( dom );

		const cb1 = dom.document.querySelector( '[data-product-id="101"]' );
		const cb2 = dom.document.querySelector( '[data-product-id="102"]' );
		const cb3 = dom.document.querySelector( '[data-product-id="103"]' );
		const fromInput = dom.document.querySelector( '[data-akb-range-from]' );
		const toInput = dom.document.querySelector( '[data-akb-range-to]' );
		const applyBtn = dom.document.querySelector( '[data-akb-range-apply]' );
		const countEl = dom.document.querySelector( '[data-akb-action-bar-count]' );
		const totalEl = dom.document.querySelector( '[data-akb-action-bar-total]' );
		const actionBar = dom.document.getElementById( 'akb-series-action-bar' );

		// Seleccionar tramo del 1 al 2
		fromInput.value = '1';
		toInput.value = '2';
		dom.click( applyBtn );

		assert.equal( cb1.checked, true, 'Tomo 1 debe estar seleccionado' );
		assert.equal( cb2.checked, true, 'Tomo 2 debe estar seleccionado' );
		assert.equal( cb3.checked, false, 'Tomo 3 está fuera de rango' );
		assert.equal( actionBar.hasAttribute( 'hidden' ), false, 'La barra flotante se activa' );
		assert.equal( countEl.textContent, '2 tomos seleccionados' );
		assert.equal( totalEl.textContent, '$18.980' );

		// Rango invertido (de 3 a 2): debe normalizar min=2, max=3
		fromInput.value = '3';
		toInput.value = '2';
		dom.click( applyBtn );

		assert.equal( cb1.checked, false, 'Tomo 1 queda fuera de [2, 3]' );
		assert.equal( cb2.checked, true, 'Tomo 2 está dentro de [2, 3]' );
		assert.equal( cb3.checked, true, 'Tomo 3 está dentro de [2, 3]' );
		assert.equal( countEl.textContent, '2 tomos seleccionados' );
	} finally {
		dom.teardown();
	}
} );

test( 'series-multiselect.js calculates and renders Akibara Rewards points reactively', () => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/chainsaw-man/'
	} );

	try {
		dom.window.akbSeriesBatchConfig = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'nonce_test',
			action: 'akb_batch_add_to_cart',
			carrierThreshold: 60000,
			purchase_incentives: { rewards: { points: 2, amount: 100, multiplier: 1.5, program_url: '/akibara-puntos/' }, shipping: { enabled: true, threshold: 60000 } },
			i18n: {
				selected_singular: '1 tomo seleccionado',
				selected_plural: '%s tomos seleccionados',
				add_selection: 'Añadir selección (%s)',
				adding: 'Añadiendo...',
				free_shipping_ready: '🎉 ¡Envío GRATIS a domicilio desbloqueado!',
				free_shipping_short: 'Te faltan %s para Envío GRATIS a domicilio'
			}
		};

		loadSeries( dom );

		const cb1 = dom.document.querySelector( '[data-product-id="101"]' );
		const cb2 = dom.document.querySelector( '[data-product-id="102"]' );
		const rewardsEl = dom.document.querySelector( '[data-akb-series-purchase-preview]' );

		assert.equal( rewardsEl.hasAttribute( 'hidden' ), true, 'El aviso de puntos inicia oculto' );

		// Seleccionar Tomo 1 ($9.990) -> round(9990 / 100 * 2) = round(199.8) = 200 pts
		cb1.checked = true;
		cb1.dispatchEvent( new dom.window.Event( 'change' ) );

		assert.equal( rewardsEl.hasAttribute( 'hidden' ), false, 'El bloque de puntos se muestra al seleccionar un tomo' );
		assert.match( rewardsEl.textContent, /300 Puntos Akibara/ );

		// Seleccionar también Tomo 2 en oferta ($8.990) -> Total $18.980 -> round(18980 / 100 * 2) = 380 pts
		cb2.checked = true;
		cb2.dispatchEvent( new dom.window.Event( 'change' ) );

		assert.match( rewardsEl.textContent, /570 Puntos Akibara/ );

		// Desmarcar todo
		dom.click( '[data-akb-clear-selection]' );
		assert.equal( rewardsEl.hasAttribute( 'hidden' ), true, 'El bloque de puntos se oculta al deseleccionar' );
	} finally {
		dom.teardown();
	}
} );

test( 'series-multiselect.js skips owned volumes on bulk/range select while allowing manual toggle', ( t ) => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/chainsaw-man/'
	} );
	t.after( () => dom.teardown() );

	loadSeries( dom, [
		{ id: 101, volume: 1, price: '9990', stock_status: 'instock', purchasable: true, type: 'simple', owned: true },
		{ id: 102, volume: 2, price: '8990', stock_status: 'instock', purchasable: true, type: 'simple', owned: false },
		{ id: 103, volume: 3, price: '45000', stock_status: 'instock', purchasable: true, type: 'simple', owned: false }
	] );

	const cb1 = dom.document.querySelector( '[data-product-id="101"]' );
	const cb2 = dom.document.querySelector( '[data-product-id="102"]' );
	const cb3 = dom.document.querySelector( '[data-product-id="103"]' );
	const countEl = dom.document.querySelector( '[data-akb-action-bar-count]' );

	// Seleccionar disponibles excluye el tomo ya comprado (101)
	dom.click( '[data-akb-select-all]' );
	assert.equal( cb1.checked, false );
	assert.equal( cb2.checked, true );
	assert.equal( cb3.checked, true );
	assert.equal( countEl.textContent, '2 tomos seleccionados' );

	// Seleccionar tramo [1..2] también omite el tomo 1 ya comprado
	dom.fill( '[data-akb-range-from]', '1' );
	dom.fill( '[data-akb-range-to]', '2' );
	dom.click( '[data-akb-range-apply]' );
	assert.equal( cb1.checked, false );
	assert.equal( cb2.checked, true );
	assert.equal( cb3.checked, false );
	assert.equal( countEl.textContent, '1 tomo seleccionado' );

	// El cliente puede marcar manualmente un tomo ya comprado si quiere una copia extra
	cb1.checked = true;
	cb1.dispatchEvent( new dom.window.Event( 'change' ) );
	assert.equal( cb1.checked, true );
	assert.equal( countEl.textContent, '2 tomos seleccionados' );
} );

test( 'series-multiselect.js ignores Enter during IME composition and applies range on confirmed Enter', ( t ) => {
	const dom = createDOM( {
		html: buildSeriesFixtureHtml(),
		url: 'https://akibara.cl/serie/chainsaw-man/'
	} );
	t.after( () => dom.teardown() );

	loadSeries( dom );

	const fromInput = dom.document.querySelector( '[data-akb-range-from]' );
	const toInput = dom.document.querySelector( '[data-akb-range-to]' );
	const cb1 = dom.document.querySelector( '[data-product-id="101"]' );
	const cb2 = dom.document.querySelector( '[data-product-id="102"]' );

	fromInput.value = '1';
	toInput.value = '2';

	toInput.dispatchEvent( new dom.window.KeyboardEvent( 'keydown', { key: 'Enter', isComposing: true, bubbles: true, cancelable: true } ) );
	assert.equal( cb1.checked, false, 'Enter con isComposing=true no debe aplicar rango' );

	const safariImeEvent = new dom.window.KeyboardEvent( 'keydown', { key: 'Enter', keyCode: 229, bubbles: true, cancelable: true } );
	Object.defineProperty( safariImeEvent, 'keyCode', { value: 229 } );
	toInput.dispatchEvent( safariImeEvent );
	assert.equal( cb1.checked, false, 'Enter con keyCode=229 (fallback Safari IME) no debe aplicar rango' );

	toInput.dispatchEvent( new dom.window.KeyboardEvent( 'keydown', { key: 'Enter', isComposing: false, bubbles: true, cancelable: true } ) );
	assert.equal( cb1.checked, true );
	assert.equal( cb2.checked, true );
} );
