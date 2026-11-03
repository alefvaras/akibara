'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const rail = require( '../../../assets/js/series-bundles.js' );
const source = fs.readFileSync( require.resolve( '../../../assets/js/series-bundles.js' ), 'utf8' );
const style = fs.readFileSync( require.resolve( '../../../assets/css/series-bundles.css' ), 'utf8' );

assert.match( source, /var LANDING_STATE_VERSION = 3;/, 'el estado persistido conserva el contrato v3' );

const priced = rail.pricedTotals( [
	{ current: 10000, regular: 10000, discountEligible: true },
	{ current: 10000, regular: 10000, discountEligible: false }
], 10 );
assert.deepEqual( priced, {
	currentTotal: 20000,
	regularTotal: 20000,
	finalTotal: 19000,
	savings: 1000,
	totalSavingsVsRegular: 1000,
	hasPreorderSavings: false
} );

const sixEligibleOneOrdinary = Array.from( { length: 6 }, () => ( {
	current: 10000,
	regular: 10000,
	discountEligible: true
} ) );
sixEligibleOneOrdinary.push( { current: 10000, regular: 10000, discountEligible: false } );
const mixedEligibleQty = sixEligibleOneOrdinary.filter( ( item ) => item.discountEligible ).length;
const mixedPct = rail.resolvePct( [ { min: 5, pct: 8 }, { min: 7, pct: 10 } ], mixedEligibleQty );
assert.equal( mixedPct, 8, 'el séptimo tomo ordinario no activa el tramo 7→10' );
assert.deepEqual(
	rail.pricedTotals( sixEligibleOneOrdinary, mixedPct ),
	{
		currentTotal: 70000,
		regularTotal: 70000,
		finalTotal: 65200,
		savings: 4800,
		totalSavingsVsRegular: 4800,
		hasPreorderSavings: false
	},
	'el tomo ordinario queda en el subtotal sin recibir descuento'
);

const roundedPerSku = rail.pricedTotals( Array.from( { length: 5 }, () => ( {
	current: 9995,
	regular: 9995,
	discountEligible: true
} ) ), 8 );
assert.deepEqual(
	roundedPerSku,
	{
		currentTotal: 49975,
		regularTotal: 49975,
		finalTotal: 45975,
		savings: 4000,
		totalSavingsVsRegular: 4000,
		hasPreorderSavings: false
	},
	'la interfaz redondea cada aporte CLP antes de sumar, igual que WooCommerce'
);

const bestPriceWins = rail.pricedTotals( [
	{ current: 8000, regular: 10000, discountEligible: true },
	{ current: 9500, regular: 10000, discountEligible: true }
], 10 );
assert.deepEqual(
	bestPriceWins,
	{
		currentTotal: 17500,
		regularTotal: 20000,
		finalTotal: 17000,
		savings: 500,
		totalSavingsVsRegular: 3000,
		hasPreorderSavings: true
	},
	'la interfaz respeta una oferta mejor y descuenta sólo el tomo que aún supera el objetivo'
);

const bakemonogatariPreorderPack = rail.pricedTotals( Array.from( { length: 5 }, () => ( {
	current: 13800,
	regular: 14500,
	discountEligible: true
} ) ), 8 );
assert.deepEqual(
	bakemonogatariPreorderPack,
	{
		currentTotal: 69000,
		regularTotal: 72500,
		finalTotal: 66700,
		savings: 2300,
		totalSavingsVsRegular: 5800,
		hasPreorderSavings: true
	},
	'el pack a pedido explicita el ahorro total contra el precio regular de lista ($5.800) y el adicional del pack ($2.300)'
);

const noAdditionalSaving = rail.pricedTotals( Array.from( { length: 5 }, () => ( {
	current: 8000,
	regular: 10000,
	discountEligible: true
} ) ), 8 );
assert.equal( noAdditionalSaving.savings, 0, 'una oferta igual o mejor no inventa ahorro BYP' );
assert.match(
	source,
	/totals\.savings > 0[\s\S]*?Tramo de[\s\S]*?Ya tienen un precio igual o mejor/,
	'la interfaz distingue un tramo alcanzado de un descuento realmente aplicado'
);
assert.match( source, /Total estimado del pack:/, 'la landing no llama subtotal al total post-promoción' );
assert.match( source, /tomo elegible[\s\S]*?más para obtener/, 'el progreso pide tomos que realmente cuentan para el tramo' );

const preorder = rail.normalizeLandingItem( {
	id: 7,
	current: 8990,
	regular: 9990,
	status: 'onbackorder',
	discount_eligible: false
} );
assert.equal( preorder.status, 'onbackorder' );
assert.equal( preorder.discountEligible, false );

const now = Date.now();
const items = {};
for ( let id = 1; id <= 107; id++ ) {
	items[ id ] = { id, current: 9990, regular: 10990, discountEligible: true };
}
const normalized = rail.normalizeLandingState( {
	version: 3,
	termId: 378,
	updatedAt: now,
	active: true,
	items
}, 378, 300, now );
assert.equal( Object.keys( normalized.items ).length, 107 );
assert.equal( normalized.active, true );

const staleVersion = rail.normalizeLandingState( {
	version: 2,
	termId: 378,
	updatedAt: now,
	active: true,
	items
}, 378, 300, now );
assert.equal( Object.keys( staleVersion.items ).length, 0 );
assert.equal( staleVersion.active, false );

const reconciledVisible = rail.reconcileVisibleLandingItems(
	{
		'1': { id: 1, current: 10990, regular: 10990, title: 'Precio anterior', discountEligible: true },
		'2': { id: 2, current: 9990, regular: 9990, title: 'Otra pagina', discountEligible: true },
		'3': { id: 3, current: 9990, regular: 9990, title: 'Sin stock', discountEligible: true }
	},
	[ { id: 1, current: 0, regular: 10990, title: 'Precio actual', discountEligible: false } ],
	[ 3 ]
);
assert.deepEqual(
	reconciledVisible,
	{
		'1': { id: 1, current: 0, regular: 10990, title: 'Precio actual', status: '', discountEligible: false },
		'2': { id: 2, current: 9990, regular: 9990, title: 'Otra pagina', discountEligible: true }
	},
	'el estado visible se actualiza con precio y elegibilidad vigentes, conserva otras paginas y elimina indisponibles'
);

const pending = rail.reconcileLandingItems( normalized.items, [ 1, 3, 107 ] );
assert.equal( pending['1'], undefined );
assert.equal( pending['3'], undefined );
assert.equal( pending['107'], undefined );
assert.ok( pending['2'] );
assert.equal( Object.keys( pending ).length, 104 );

assert.deepEqual(
	rail.reconcileSelectedIds( [ 1, 2, 3 ], [ 1, 3 ] ),
	[ 2 ],
	'el builder desmarca los éxitos y conserva sólo el retry exacto de un parcial'
);

assert.equal( rail.resolvePct( [ { min: 3, pct: 10 }, { min: 6, pct: 15 } ], 5 ), 10 );
assert.equal( rail.formatMoney( 12340 ), '$12.340' );
assert.match( source, /wc_fragment_refresh/ );
assert.match( source, /akbCart\.refresh/ );
assert.match( source, /#completar-coleccion/ );
assert.match( source, /akb:pjax/ );
assert.match( source, /data\.added_ids/ );
assert.match(
	source,
	/akbSeriesSelectionTermId:[\s\S]*?window\.history\.back\(\);/,
	'cerrar consume la entrada de historial creada al abrir y no deja un hash fantasma'
);
assert.match(
	source,
	/restoreStartFocusAfterPjax = true;[\s\S]*?window\.history\.back\(\);[\s\S]*?akb:pjax[\s\S]*?restoreStartFocusAfterPjax = false;[\s\S]*?start\.focus\( \{ preventScroll: true \} \);/,
	'el cierre rescata el foco aun cuando popstate dispara un swap PJAX posterior'
);
assert.match(
	source,
	/function syncSelectionModeFromLocation\(\)[\s\S]*?setActive\( hashActive, false \);[\s\S]*?addEventListener\( 'popstate', syncSelectionModeFromLocation \);[\s\S]*?addEventListener\( 'hashchange', syncSelectionModeFromLocation \);/,
	'popstate cierra el modo antes de que el PJAX de Search pueda restaurar un hash obsoleto en WebKit'
);
assert.match(
	style,
	/\.woocommerce ul\.products li\.product \.akb-sb-card-select \{[\s\S]*?z-index: 3;[\s\S]*?touch-action: manipulation;/,
	'el selector queda por encima del enlace de card y responde sin demora táctil'
);
assert.match(
	source,
	/function syncProductLink[\s\S]*?removeAttribute\( 'href' \);[\s\S]*?data-akb-sb-card-toggle[\s\S]*?function toggleProductLinkSelection/,
	'en modo selección la superficie visual de la tarjeta deja de navegar'
);
assert.match(
	source,
	/a\[data-akb-sb-card-toggle=[\s\S]*?event\.preventDefault\(\);[\s\S]*?toggleProductLinkSelection\( link \);/,
	'tocar portada o título conmuta el tomo sin navegar'
);
assert.doesNotMatch(
	source,
	/productLink[\s\S]{0,240}setAttribute\( 'role', 'checkbox' \)/,
	'la tarjeta visual no duplica el checkbox nativo en el árbol accesible'
);
assert.match(
	source,
	/function currentChecks\(\)[\s\S]*?data-term-id=[\s\S]*?check\.getAttribute\( 'data-term-id' \) !== String\( termId \)/,
	'cada controlador procesa únicamente los tomos de su término'
);
assert.match(
	source,
	/var initializedTerms = \{\};[\s\S]*?initializedTerms\[ String\( termId \) \][\s\S]*?= true;/,
	'un término sólo puede instalar un controlador aunque el DOM llegue duplicado'
);
assert.match(
	source,
	/var isPartial = remainingIds\.length > 0;[\s\S]*?if \( isPartial \|\| notice \) \{[\s\S]*?akbCart\.refresh\( true \);[\s\S]*?return;/,
	'el builder mantiene el retry exacto en la ficha y no abre ni redirige el carrito tras un parcial, ni con un agotado que explicar'
);
assert.match( source, /data-term-id/ );
assert.match(
	source,
	/var eligibleQty = sel\.items\.filter[\s\S]*?item\.discountEligible[\s\S]*?resolvePct\( tiers, eligibleQty \)/,
	'la ficha resuelve el tramo sólo con tomos elegibles, igual que landing y carrito'
);
assert.match(
	source,
	/discountEligible: c\.getAttribute\( 'data-discount-eligible' \) === '1'[\s\S]*?pricedTotals\( sel\.items, pct \)/,
	'la ficha conserva los tomos no elegibles en el total pero no les aplica el ahorro visual'
);
assert.match(
	source,
	/Math\.round\( Math\.max\( 0, current - target \) \)[\s\S]*?finalPrice = current - contribution/,
	'la UI redondea el aporte por SKU igual que el motor CLP antes de mostrar el total'
);
assert.match(
	source,
	/function setActive[\s\S]*?classList\.toggle\( 'akb-sb-selection-active', active \);[\s\S]*?refresh\(\);/,
	'la reapertura por hash/filtro recalcula resumen, subtotal y botones'
);
assert.match(
	source,
	/function refresh\(\) \{[\s\S]*?reconcileCurrentItems\(\);[\s\S]*?var items = selectedItems\(\);/,
	'landing reconcilia el DOM vigente antes de calcular tramos, subtotal y ahorro'
);
assert.match(
	style,
	/@media \(max-width: 768px\)[\s\S]*?body\.akb-sb-selection-active \.joinchat \{[\s\S]*?visibility: hidden !important;[\s\S]*?pointer-events: none !important;/,
	'el chat flotante no puede tapar el CTA fijo de selección en móvil'
);
assert.match(
	style,
	/\.akb-sb-landing__collection a \{[\s\S]*?min-height: var\(--touch-target-min, 44px\);[\s\S]*?display: inline-flex;[\s\S]*?align-items: center;/,
	'el acceso de inicio de sesión mantiene un objetivo táctil de al menos 44 px'
);

process.stdout.write( 'series-bundles.js: ok\n' );

// Devolver el foco al botón «Elegir tomos» NUNCA puede mover la página.
//
// Ese botón vive por encima de la grilla. Cerrar el modo de selección —con el
// aspa o con Escape— devolvía el foco con un `focus()` pelado, y el navegador
// arrastra la página hasta el elemento enfocado: quien iba por el tomo 40 de 107
// aparecía de vuelta arriba del todo. Dos de los cuatro sitios ya pasaban
// `preventScroll: true`; los otros dos eran un olvido.
//
// Se fija la propiedad —ningún `focus()` sobre `start` sin `preventScroll`—, no
// el número de llamadas: añadir un quinto sitio correcto no debe romper esto.
assert.equal(
	( source.match( /start\.focus\(\s*\)/g ) || [] ).length,
	0,
	'devolver el foco al botón de inicio nunca puede arrastrar la página'
);
assert.ok(
	( source.match( /start\.focus\(\s*\{\s*preventScroll:\s*true\s*\}\s*\)/g ) || [] ).length >= 4,
	'los cuatro caminos que devuelven el foco lo hacen sin mover el scroll'
);

// El control real del selector NO puede quedar fuera del rectángulo de su etiqueta.
//
// Historia del defecto, medida: el `<input type="checkbox">` vive dentro de un
// `<label>`, y un clic en cualquier hijo de la etiqueta traslada el foco al
// control — el navegador entonces lleva a la vista el control enfocado. Mientras
// se ocultaba con el patrón `screen-reader-text` (1×1 px, `clip-path: inset(50%)`,
// `margin: -1px`), ese rectángulo caía fuera de la etiqueta y la página SALTABA:
// gate del selector de series, chromium 1440×900, `/serie/one-piece/`,
// scrollY 1293 → 918 con el foco en el INPUT. Intermitente, 1 de cada 6 corridas.
//
// Se fija la propiedad —el control ocupa su etiqueta y se oculta por opacidad,
// no por recorte—, no el bloque de CSS concreto.
const reglaControl = style.match( /\.akb-sb-card-select input \{[^}]*\}/ );
assert.ok( reglaControl, 'el control del selector tiene que tener su propia regla' );
assert.match( reglaControl[0], /opacity:\s*0/, 'se oculta por opacidad: un elemento recortado conserva su geometría fuera de sitio' );
assert.doesNotMatch( reglaControl[0], /clip-path/, 'el recorte es justo lo que dejaba el control fuera de la etiqueta' );
assert.doesNotMatch( reglaControl[0], /width:\s*1px/, 'un control de 1px no comparte rectángulo con su etiqueta' );
assert.match( reglaControl[0], /inset:\s*0/, 'el control ocupa la etiqueta entera' );

// Y esa etiqueta tiene que ser el bloque contenedor SIEMPRE, no sólo dentro de
// la grilla de WooCommerce: si no, `inset: 0` se resolvería contra un ancestro
// cualquiera y el control volvería a caer lejos.
assert.match(
	style,
	/\.akb-sb-card-select \{\s*position:\s*relative;/,
	'sin `position: relative` en la propia etiqueta, `inset: 0` apunta a otro sitio'
);

/*
 * Cabecera del selector: UNA barra (revisión B3-R1, 2026-09-23).
 *
 * Medido en la landing de Ranma 1/2 a 375px: con los tramos en la misma columna
 * que el botón, «Desde 7 tomos · 10% dto.» —el argumento más fuerte para sumar
 * tomos— caía 93px fuera de vista detrás de un arrastre horizontal. Después, en
 * dos filas (título + botón, tramos + «Cómo funciona…») el riel seguía en 204 px
 * en 390 px y la primera portada quedaba bajo la barra inferior.
 *
 * Ahora: título y disparador del desplegable arriba; chips y botón en la fila
 * de abajo (móvil) o todo en una fila (≥ 1024 px). Los tramos viven DENTRO del
 * desplegable y envuelven; el disparador no se mueve al abrir (B3-R7) porque
 * nada de la barra se centra en vertical.
 */
const movilCss = style.slice( style.indexOf( '@media (max-width: 768px)' ) );

assert.match(
	style,
	/\.akb-sb-landing__head\s*\{[^}]*grid-template-areas:\s*"copy copy"\s*"filters cta";[^}]*align-items:\s*start;/,
	'en móvil: título a todo el ancho y chips + botón debajo, sin centrar en vertical'
);
assert.match(
	style,
	/@media \(min-width: 1024px\)\s*\{\s*\.akb-sb-landing__head\s*\{[^}]*grid-template-areas:\s*"copy filters cta";/,
	'en escritorio las tres piezas van en una fila, en el orden del DOM'
);
assert.doesNotMatch(
	style,
	/\.akb-sb-landing__terms/,
	'la fila aparte de tramos no existe: ni el marcado ni su regla'
);
assert.doesNotMatch(
	style,
	/\.akb-sb-landing__rules\[open\]\s*\{[^}]*flex-basis/,
	'abrir el desplegable no cambia el tamaño de su caja en la barra (el disparador saltaba)'
);
assert.doesNotMatch(
	movilCss,
	/\.akb-sb-landing__tiers\s*\{[^}]*overflow-x:\s*auto/,
	'los tramos de descuento no viven detrás de un arrastre horizontal'
);
assert.match(
	style,
	/\.akb-sb-landing__tiers\s*\{[^}]*flex-wrap:\s*wrap/,
	'los tramos envuelven en vez de desbordar'
);
assert.doesNotMatch(
	style,
	/\.akb-sb-landing__(tiers|rules-toggle|hook|rules-label)\s*\{[^}]*(white-space:\s*nowrap|overflow-x:\s*(auto|scroll))/,
	'ni los tramos ni los dos textos del dueño del disparador se esconden detrás de un arrastre ni empujan la página'
);
assert.doesNotMatch(
	movilCss,
	/\.akb-sb-landing__intro\s*\{[^}]*-webkit-line-clamp/,
	'la frase de condiciones no se recorta: la mitad oculta explicaba cuándo aplica'
);
assert.match(
	style,
	/\.akb-sb-landing__rules-toggle::-webkit-details-marker\s*\{[^}]*display:\s*none/,
	'Safari pinta su triángulo propio si no se quita: dos marcas en el disparador'
);

/*
 * El botón que BORRA la selección no se disfraza del que cierra.
 *
 * En móvil «Limpiar» se pintaba con `font-size: 0` y una × por ::before — el
 * mismo glifo que el botón de cerrar del panel, en la misma bandeja y a 200px de
 * distancia. Uno descarta el panel y el otro borra los tomos elegidos.
 */
const reglaLimpiar = movilCss.match( /\.akb-sb-landing__clear\s*\{[^}]*\}/ );

assert.ok( reglaLimpiar, 'la regla móvil de «Limpiar» existe' );
assert.doesNotMatch( reglaLimpiar[0], /font-size:\s*0/, '«Limpiar» no oculta su etiqueta' );
assert.doesNotMatch(
	movilCss,
	/\.akb-sb-landing__clear::before\s*\{[^}]*content:\s*"\\00d7"/,
	'«Limpiar» no se sustituye por la × de cerrar'
);

/*
 * El enlace «Inicia sesión» del riel de landing NO toma su destino de la
 * respuesta AJAX.
 *
 * `handle_collection_state()` corre sobre `admin-ajax.php`, así que cualquier
 * destino resuelto ahí es el propio endpoint: quien pulsaba el enlace
 * aterrizaba en `/wp-admin/admin-ajax.php`. El destino bueno lo hornea el PHP
 * del render, donde la URL de la petición SÍ es la landing, y viaja en
 * `cfg.loginUrl`.
 */
assert.doesNotMatch(
	source,
	/data\.login_url/,
	'el destino de vuelta no puede venir de la respuesta del endpoint AJAX'
);
assert.match(
	source,
	/login\.href = cfg\.loginUrl \|\| login\.href;/,
	'el enlace conserva el destino que resolvió el render de la página'
);

/*
 * La respuesta de compra múltiple enumera lo agregado y lo que no entra por un
 * motivo que reintentar no cambia (`rejected_ids`: ya en el carrito o agotado).
 * El cliente deja marcado sólo el resto. Una lista de TODOS los descartados
 * sería el complemento de lo que envió: nadie la leía.
 */
assert.doesNotMatch( source, /data\.skipped_ids/, 'el JS nunca leyó skipped_ids' );

/*
 * Deuda previa (verificación del 2026-09-25): volver a elegir tomos que ya están
 * en el carrito respondía un error fijo y los dejaba marcados; reintentar daba
 * lo mismo. Lo resuelto (lo que entró y lo que no entra) se desmarca.
 */
assert.deepEqual( rail.settledIds( { rejected_ids: [ 2 ] }, [ 1 ] ), [ 1, 2 ] );
assert.deepEqual(
	rail.reconcileSelectedIds( [ 1, 2, 3 ], rail.settledIds( { rejected_ids: [ 2 ] }, [ 1 ] ) ),
	[ 3 ],
	'queda marcado sólo lo que un reintento podría sumar'
);
assert.deepEqual( rail.settledIds( { success: false, message: 'x' }, [] ), [], 'sin rejected_ids (la red, un 500), nada se da por resuelto' );
assert.deepEqual( rail.settledIds( null, [ 4 ] ), [ 4 ] );
assert.equal(
	( source.match( /failed\.settledIds = settledIds\( data, \[\] \);/g ) || [] ).length,
	2,
	'el armador de la ficha y «Elegir tomos» de la landing llevan lo resuelto también cuando nada entró'
);
assert.match( source, /keepChecked\( reconcileSelectedIds\( ids, error\.settledIds \) \);/, 'el armador desmarca lo resuelto en un fallo' );
assert.match( source, /state\.items = reconcileLandingItems\( state\.items, error\.settledIds \);/, 'la landing lo saca de la selección en un fallo' );
assert.doesNotMatch( source, /No se pudo agregar/, 'el texto de un fallo es del dueño (akbSbBuilder.i18n.error), no un literal del JS' );

// «Agregamos X de Y» cuenta lo que entró más lo que sigue marcado (verificación del
// lote de UX del tema, 2026-09-26): con un tomo ya en el carrito (se desmarca) y un
// fallo sin motivo (sigue marcado), decía «1 de 3» con un solo tomo marcado.
{
	const tpl = 'Agregamos %1$d de %2$d tomos. Los que no se pudieron agregar siguen seleccionados para que lo intentes otra vez.';
	const elegidos = [ 11, 12, 13 ];
	const marcados = rail.reconcileSelectedIds( elegidos, rail.settledIds( { rejected_ids: [ 12 ] }, [ 11 ] ) );
	assert.deepEqual( marcados, [ 13 ], 'sólo sigue marcado el que puede entrar al reintentar' );
	assert.equal(
		rail.partialMessage( tpl, 1, marcados.length ),
		'Agregamos 1 de 2 tomos. Los que no se pudieron agregar siguen seleccionados para que lo intentes otra vez.',
		'el total del parcial no cuenta el tomo que se desmarcó'
	);
	assert.equal( rail.partialMessage( 'Agregamos %1$d de %2$d tomos.', 2, 1 ), 'Agregamos 2 de 3 tomos.', 'sin desmarcados, el total es lo elegido' );
	assert.equal( ( source.match( /partialMessage\( i18n\.partial \|\|/g ) || [] ).length, 2, 'la ficha y la landing dicen el parcial igual' );
	assert.doesNotMatch( source, /format\( i18n\.partial[^;]*ids\.length/, 'ninguna superficie vuelve a contar todo lo elegido' );
}
