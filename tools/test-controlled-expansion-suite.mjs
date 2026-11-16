import { chromium } from './akibara-releases/rewards-qa/node_modules/playwright/index.mjs';
import { mkdirSync } from 'node:fs';

const BASE_URL = process.env.AKB_TEST_URL || 'http://localhost:8080';
const OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/controlled-expansion';
mkdirSync( OUT_DIR, { recursive: true } );

async function run() {
	console.log( `Iniciando suite de pruebas de Cuadrícula con Expansión Controlada en ${BASE_URL}...` );
	const browser = await chromium.launch();
	let failed = 0;

	function assert( desc, condition, extra = '' ) {
		if ( condition ) {
			console.log( `  ✔ ${desc}${extra ? ' (' + extra + ')' : ''}` );
		} else {
			console.error( `  ✖ FALLÓ: ${desc}${extra ? ' (' + extra + ')' : ''}` );
			failed++;
		}
	}

	try {
		// =========================================================================
		// TEST 1: ESCRITORIO (1280x800) - Dan Da Dan (23 tomos)
		// =========================================================================
		console.log( '\n--- TEST 1: Escritorio (1280x800) - Dan Da Dan 23 ---' );
		const ctxDesktop = await browser.newContext( { viewport: { width: 1280, height: 800 } } );
		const pageDesktop = await ctxDesktop.newPage();
		await pageDesktop.goto( `${BASE_URL}/dan-da-dan-23-ivrea-argentina/`, { waitUntil: 'domcontentloaded' } );
		await pageDesktop.waitForSelector( '[data-akb-sb-builder]' );

		// 1.1 Verificar overflow y max-height del grid (cero scroll anidado / cero scroll trap)
		const gridStyles = await pageDesktop.evaluate( () => {
			const grid = document.querySelector( '.akb-sb-builder__grid' );
			if ( ! grid ) return null;
			const cs = window.getComputedStyle( grid );
			return {
				maxHeight: cs.maxHeight,
				overflowY: cs.overflowY,
				columns: cs.gridTemplateColumns.split( ' ' ).length
			};
		} );

		assert( 'Desktop: Grid no tiene max-height restrictivo', gridStyles.maxHeight === 'none' || parseInt( gridStyles.maxHeight, 10 ) > 2000, `maxHeight: ${gridStyles.maxHeight}` );
		assert( 'Desktop: Grid tiene overflow-y visible (adiós al scroll trap)', gridStyles.overflowY === 'visible', `overflowY: ${gridStyles.overflowY}` );
		assert( 'Desktop: Grid tiene exactamente 8 columnas', gridStyles.columns === 8, `columnas: ${gridStyles.columns}` );

		// 1.2 Verificar estado colapsado inicial (16 visibles, resto ocultos)
		const initialCards = await pageDesktop.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			return { total: cards.length, visible: visible.length };
		} );

		assert( 'Desktop: Total de tomos en Dan Da Dan es 23', initialCards.total === 23, `total: ${initialCards.total}` );
		assert( 'Desktop: Inicialmente muestra exactamente 16 tomos (2 filas de 8)', initialCards.visible === 16, `visibles: ${initialCards.visible}` );
		await pageDesktop.screenshot( { path: `${OUT_DIR}/01-desktop-dandadan-colapsado.png`, fullPage: false } );

		// 1.3 Botón de expansión presente con texto correcto
		const expandBtnState = await pageDesktop.evaluate( () => {
			const btn = document.querySelector( '[data-akb-sb-expand-btn]' );
			if ( ! btn ) return null;
			return {
				text: btn.textContent.trim(),
				expanded: btn.getAttribute( 'aria-expanded' ),
				visible: window.getComputedStyle( btn ).display !== 'none'
			};
		} );

		assert( 'Desktop: Botón de expansión visible', expandBtnState && expandBtnState.visible );
		assert( 'Desktop: Texto botón indica "Ver los 23 tomos"', expandBtnState && expandBtnState.text.includes( '23' ), expandBtnState?.text );
		assert( 'Desktop: aria-expanded es false', expandBtnState && expandBtnState.expanded === 'false' );

		// 1.4 Click en expansión despliega los 23 tomos
		await pageDesktop.click( '[data-akb-sb-expand-btn]' );
		await pageDesktop.waitForTimeout( 150 );

		const expandedCards = await pageDesktop.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			const btn = document.querySelector( '[data-akb-sb-expand-btn]' );
			return {
				visible: visible.length,
				btnText: btn ? btn.textContent.trim() : '',
				ariaExpanded: btn ? btn.getAttribute( 'aria-expanded' ) : null
			};
		} );

		assert( 'Desktop: Tras expandir, se ven los 23 tomos completos', expandedCards.visible === 23, `visibles: ${expandedCards.visible}` );
		assert( 'Desktop: Botón cambia a "Ocultar tomos"', expandedCards.btnText.includes( 'Ocultar tomos' ), expandedCards.btnText );
		assert( 'Desktop: aria-expanded pasa a true', expandedCards.ariaExpanded === 'true' );
		await pageDesktop.screenshot( { path: `${OUT_DIR}/02-desktop-dandadan-expandido.png`, fullPage: false } );

		// 1.5 Atajos de pack: Seleccionar en stock
		await pageDesktop.click( '[data-akb-sb-shortcut="stock"]' );
		await pageDesktop.waitForTimeout( 200 );

		const stockSelected = await pageDesktop.evaluate( () => {
			const checks = Array.from( document.querySelectorAll( '[data-akb-sb-vol]:checked' ) );
			const stickyBar = document.getElementById( 'akb-sticky-atc' );
			const isPackMode = stickyBar ? stickyBar.classList.contains( 'is-pack-mode' ) : false;
			return {
				checkedCount: checks.length,
				isPackMode
			};
		} );

		assert( 'Desktop: Atajo "Seleccionar en stock" marca tomos', stockSelected.checkedCount >= 5, `marcados: ${stockSelected.checkedCount}` );
		assert( 'Desktop: Sticky bar activa modo pack', stockSelected.isPackMode );
		await pageDesktop.screenshot( { path: `${OUT_DIR}/03-desktop-dandadan-pack-activo.png`, fullPage: false } );

		// 1.6 Atajo Limpiar
		await pageDesktop.click( '[data-akb-sb-shortcut="clear"]' );
		await pageDesktop.waitForTimeout( 200 );

		const clearedState = await pageDesktop.evaluate( () => {
			const checks = Array.from( document.querySelectorAll( '[data-akb-sb-vol]:checked' ) );
			const stickyBar = document.getElementById( 'akb-sticky-atc' );
			const isPackMode = stickyBar ? stickyBar.classList.contains( 'is-pack-mode' ) : false;
			return {
				checkedCount: checks.length,
				isPackMode
			};
		} );

		assert( 'Desktop: Atajo "Limpiar" desmarca todos los tomos', clearedState.checkedCount === 0, `marcados: ${clearedState.checkedCount}` );
		assert( 'Desktop: Sticky bar vuelve a modo individual', ! clearedState.isPackMode );

		// 1.7 Atajo Últimos 5 tomos
		await pageDesktop.click( '[data-akb-sb-shortcut="last5"]' );
		await pageDesktop.waitForTimeout( 200 );

		const last5State = await pageDesktop.evaluate( () => {
			const checks = Array.from( document.querySelectorAll( '[data-akb-sb-vol]:checked' ) );
			return checks.length;
		} );

		assert( 'Desktop: Atajo "Últimos 5 tomos" marca 5 tomos', last5State === 5, `marcados: ${last5State}` );

		// 1.8 Click en Ocultar tomos colapsa de nuevo
		await pageDesktop.click( '[data-akb-sb-expand-btn]' );
		await pageDesktop.waitForTimeout( 150 );

		const reCollapsedCards = await pageDesktop.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			return visible.length;
		} );

		assert( 'Desktop: Tras colapsar, vuelven a verse solo 16 tomos', reCollapsedCards === 16, `visibles: ${reCollapsedCards}` );

		// =========================================================================
		// TEST 2: MÓVIL (390x844) - Dan Da Dan (23 tomos)
		// =========================================================================
		console.log( '\n--- TEST 2: Móvil (390x844) - Dan Da Dan 23 ---' );
		const ctxMobile = await browser.newContext( {
			viewport: { width: 390, height: 844 },
			userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1',
			isMobile: true,
			hasTouch: true
		} );
		const pageMobile = await ctxMobile.newPage();
		await pageMobile.goto( `${BASE_URL}/dan-da-dan-23-ivrea-argentina/`, { waitUntil: 'domcontentloaded' } );
		await pageMobile.waitForSelector( '[data-akb-sb-builder]' );

		// 2.1 Verificar 3 columnas en móvil y overflow-y visible
		const mobileGridStyles = await pageMobile.evaluate( () => {
			const grid = document.querySelector( '.akb-sb-builder__grid' );
			if ( ! grid ) return null;
			const cs = window.getComputedStyle( grid );
			return {
				overflowY: cs.overflowY,
				columns: cs.gridTemplateColumns.split( ' ' ).length
			};
		} );

		assert( 'Móvil: Grid tiene exactamente 3 columnas por fila', mobileGridStyles.columns === 3, `columnas: ${mobileGridStyles.columns}` );
		assert( 'Móvil: Grid tiene overflow-y visible (cero doble scroll)', mobileGridStyles.overflowY === 'visible', `overflowY: ${mobileGridStyles.overflowY}` );

		// 2.2 Estado colapsado móvil (6 visibles, 2 filas de 3)
		const mobileInitialCards = await pageMobile.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			return visible.length;
		} );

		assert( 'Móvil: Inicialmente muestra exactamente 6 tomos (2 filas de 3)', mobileInitialCards === 6, `visibles: ${mobileInitialCards}` );
		await pageMobile.screenshot( { path: `${OUT_DIR}/04-mobile-dandadan-colapsado.png` } );

		// 2.3 Expansión en móvil
		await pageMobile.click( '[data-akb-sb-expand-btn]' );
		await pageMobile.waitForTimeout( 150 );

		const mobileExpanded = await pageMobile.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			return visible.length;
		} );

		assert( 'Móvil: Tras expandir, se ven los 23 tomos completos', mobileExpanded === 23, `visibles: ${mobileExpanded}` );
		await pageMobile.screenshot( { path: `${OUT_DIR}/05-mobile-dandadan-expandido.png` } );

		// 2.4 Atajo en stock en móvil
		await pageMobile.click( '[data-akb-sb-shortcut="stock"]' );
		await pageMobile.waitForTimeout( 200 );
		await pageMobile.screenshot( { path: `${OUT_DIR}/06-mobile-dandadan-pack-activo.png` } );

		// =========================================================================
		// TEST 2.5: Chainsaw Man 23 (Escritorio y Móvil)
		// =========================================================================
		console.log( '\n--- TEST 2.5: Chainsaw Man 23 ---' );
		await pageDesktop.goto( `${BASE_URL}/chainsaw-man-23-ivrea-argentina/`, { waitUntil: 'domcontentloaded' } );
		await pageDesktop.waitForSelector( '[data-akb-sb-builder]' );
		const csmInitial = await pageDesktop.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			return { total: cards.length, visible: visible.length };
		} );
		assert( 'Chainsaw Man Desktop: Total 23 tomos', csmInitial.total === 23, `total: ${csmInitial.total}` );
		assert( 'Chainsaw Man Desktop: Inicialmente 16 visibles', csmInitial.visible === 16, `visibles: ${csmInitial.visible}` );
		await pageDesktop.screenshot( { path: `${OUT_DIR}/07-desktop-chainsawman-colapsado.png` } );

		await pageDesktop.click( '[data-akb-sb-shortcut="stock"]' );
		await pageDesktop.waitForTimeout( 200 );
		await pageDesktop.screenshot( { path: `${OUT_DIR}/08-desktop-chainsawman-pack-activo.png` } );

		// =========================================================================
		// TEST 3: Serie corta (<= 6 tomos) - Hellsing Edicion Inmortal (5 tomos)
		// =========================================================================
		console.log( '\n--- TEST 3: Serie corta (Hellsing Edicion Inmortal 5 tomos) ---' );
		const pageShort = await ctxDesktop.newPage();
		await pageShort.goto( `${BASE_URL}/hellsing-edicion-inmortal-5-ivrea-argentina/`, { waitUntil: 'domcontentloaded' } );
		await pageShort.waitForSelector( '[data-akb-sb-builder]' );

		const shortSeries = await pageShort.evaluate( () => {
			const cards = Array.from( document.querySelectorAll( '.akb-sb-builder__card' ) );
			const visible = cards.filter( c => window.getComputedStyle( c ).display !== 'none' );
			const expandWrap = document.querySelector( '.akb-sb-builder__expand-wrap' );
			return {
				total: cards.length,
				visible: visible.length,
				hasExpandBtn: !! expandWrap
			};
		} );

		assert( 'Serie corta: 5 tomos totales', shortSeries.total === 5, `total: ${shortSeries.total}` );
		assert( 'Serie corta: Todos los tomos visibles de inmediato', shortSeries.visible === 5, `visibles: ${shortSeries.visible}` );
		assert( 'Serie corta: NO muestra botón de expansión innecesario', ! shortSeries.hasExpandBtn );

		await browser.close();

		if ( failed > 0 ) {
			console.error( `\n❌ Fallaron ${failed} pruebas.` );
			process.exit( 1 );
		} else {
			console.log( '\n🎉 ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (100% VERDE)!' );
		}
	} catch ( err ) {
		console.error( 'Error durante la ejecución del test:', err );
		if ( browser ) await browser.close();
		process.exit( 1 );
	}
}

run();
