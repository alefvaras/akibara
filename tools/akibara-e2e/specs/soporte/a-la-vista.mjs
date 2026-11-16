// @ts-check
/**
 * «A la vista»: lo que el cliente ve de verdad, medido en los píxeles.
 *
 * `toBeVisible()` sólo pide una caja no vacía y que no haya visibility:hidden.
 * Da por visible un texto con opacity:0, con el color transparente, sacado de
 * la pantalla o recortado a 1x1 px como los textos que sólo leen los lectores
 * de pantalla, e innerText lo devuelve igual (REV-F-R-rev2-1).
 *
 * Enumerar en el CSS las formas de esconder un texto siempre dejaba otra fuera:
 * la promesa en un <strong> recortado por el aviso o como los textos para
 * lectores de pantalla, subido 9999 px, con letra de 1 px o con opacidad .01
 * seguía en verde (REV-F-R-3-1, REV-M-R-2-1, 2-2 y 2-3). Así que ya no se lee
 * el CSS: se mide la caja en la ventana y se miran los píxeles.
 *
 * Qué exige, además de `toBeVisible()`:
 *
 *  1. La caja, con la ventana desplazada hasta ella, y antes los contenedores
 *     que el cliente desplaza con el dedo o la rueda (overflow auto o scroll):
 *     con html,body{height:100%;overflow-x:hidden} la página la desplaza el
 *     <body>, y mover sólo la ventana daba rojo en móvil con el aviso a un
 *     gesto (REV-Z-R-2o-3). Ésos sólo: un scrollIntoView() también desplaza los
 *     contenedores con overflow:hidden y destapa justo lo que recortan.
 *     - Al menos una línea: 14 px de alto y 24 de ancho.
 *     - Dentro de la ventana y sin recortar: cada trozo de la caja (una por
 *       línea, si el texto se parte) se cruza con la ventana y con cada
 *       ancestro que recorta —overflow distinto de visible, o clip en
 *       absolute/fixed, también el suyo—, y lo que queda tiene que ser al
 *       menos el 90 % de la caja. La de un elemento en línea, sin el relleno
 *       ni el borde de arriba y de abajo, que no ocupan sitio en la línea y
 *       asoman de ella: un overflow:hidden del aviso se comía el relleno de
 *       «Ver programa» con todas sus letras a la vista, y con line-height 1.3
 *       daba rojo (REV-Z-R-2f-1). Cada ancestro con overflow recorta con 4 px
 *       de holgura; la ventana, sin ella. El overflow del <body> no cuenta si
 *       el de <html> es visible: entonces pasa a la ventana y el body no
 *       recorta nada (html,body{height:100%} body{overflow-x:hidden} daba rojo
 *       en móvil con el aviso entero a la vista, REV-Z-R-1o-2).
 *     - Con `dentroDe`, que no se salga del contenedor más cercano que case
 *       con ese selector (un enlace que su contenedor deja fuera con height:0),
 *       medido igual: sin ese relleno y con la misma holgura.
 *
 *  2. Las letras, en dos capturas del elemento (locator.screenshot): tal cual,
 *     y con el texto suyo y de sus hijos en transparente. Lo que cambia de una
 *     a otra es lo que pintan sus letras, y lo que queda detrás de cada píxel
 *     es su fondo real: el de la página o un resaltado del propio elemento.
 *     - Cada letra, una a una (una caja por letra de cada nodo de texto; los
 *       blancos no cuentan). Con una caja por línea bastaba que asomara
 *       «480 Punt…» para dar la línea por leída (REV-Z-R-1o-1, REV-Z-R-1f-1):
 *       - Tiene en su sitio al menos un píxel con el contraste de WCAG AA
 *         frente al de detrás: 4,5:1, o 3:1 si es texto grande (24 px, o
 *         18,66 en negrita). Medido en el espejo, Chromium y WebKit, escritorio
 *         y móvil: la peor letra del aviso llega a 5,6:1 («Ver programa») y las
 *         de la promesa a 13,4. Una letra cortada por un clip-path, en un gris
 *         de 3,7:1 (REV-Z-R-1o-5) o desenfocada 4 px no llega.
 *       - Su centro queda dentro de lo que la recorta, desde su padre hasta el
 *         elemento, los dos incluidos: overflow distinto de visible, como un
 *         inline-block con max-width, un text-overflow:ellipsis o un
 *         line-clamp. Las letras que corta un <strong> así caen donde sigue
 *         el texto del aviso y tomarían su tinta.
 *       - Tiene ancho: WebKit deja sin él, en el sitio de los puntos
 *         suspensivos, las que corta un text-overflow:ellipsis.
 *       - No va montada sobre otra letra a la vista, suya o de otro texto de
 *         la página: su caja no pisa la de ésa más del 25 % de la menor. En la
 *         segunda captura se borran todas sus letras, también las que pisan
 *         otras, y lo de debajo pasa por su fondo: montada sobre otro texto,
 *         una letra tiene tinta aunque no se lea. Así pasaban la promesa
 *         bajada una línea, un word-spacing:-1em o un line-height:.5, que
 *         apila las líneas (REV-Z-R-2o-2). Las cajas de las letras de una línea
 *         sólo se tocan (el píxel que se pisan en WebKit, que las redondea, no
 *         cuenta), y con el interlineado del tema las de dos líneas no se
 *         pisan.
 *     - Las letras de cada texto miden de media al menos 0,3 em de ancho (sin
 *       la puntuación, y con tres o más): con letter-spacing:-6px o un
 *       scaleX(.15) se amontonan en una mancha que tiene tinta y colores
 *       (REV-Z-R-1o-4). La promesa da 0,6.
 *     - Letras de 7 px de alto o más: la racha más larga de filas con tinta.
 *       Con la letra de 14 px del aviso, la promesa y «Ver programa» miden 13.
 *     - Al menos 8 colores distintos con contraste de 3:1 o más frente al
 *       píxel de detrás: el suavizado de las letras da decenas (medido en el
 *       espejo, Chromium y WebKit, escritorio y móvil: la promesa de 111 a 142,
 *       «Ver programa» de 36 a 69, el aviso entero de 163 a 223) y una raya o
 *       un bloque de un color, uno.
 *     Por eso un texto sólo para lectores de pantalla dentro del elemento daría
 *     rojo: se mide cada letra que ocupa sitio. Lo que tapa las letras
 *     —opacidad baja, un color como el del fondo, una capa encima o un
 *     recorte— deja las dos capturas iguales. Un texto encima no las tapa: se
 *     mezcla con ellas, y eso lo mira el solape. Los ::before y ::after del
 *     propio elemento y de sus hijos salen igual en las dos capturas, con el
 *     color que tengan: las letras de una capa así no son tinta de lo que tapa
 *     (REV-Z-R-1o-3, REV-Z-R-2o-1).
 *
 * No sirve para una letra en degradado (-webkit-text-fill-color transparente
 * con background-clip:text): la segunda captura no la borra y da rojo. Si
 * alguna vez se usa, hay que mirarla aparte. Tampoco para un elemento más alto
 * que la ventana, ni para uno absolute o fixed que se escapa de un ancestro con
 * overflow: se cruza con todos y daría rojo de más (el aviso no los usa).
 *
 * Tampoco para un ::first-line con color propio: esa línea sale igual en las
 * dos capturas, sin tinta, y da rojo (la sonda no puede tocarlo: en WebKit una
 * regla en ::first-line, aunque sólo cambie el color, rehace la primera línea
 * y cambia su alto).
 *
 * Lo que NO ve: las letras de un ::before o ::after montadas sobre las suyas
 * sin un fondo que las tape (el texto generado no tiene caja de letra que
 * medir, y en la segunda captura las suyas asoman entre las de la capa), ni la
 * nitidez más allá del contraste: un desenfoque de 1 px deja letras con 6:1 y
 * pasa.
 *
 * Se espera como cualquier otra aserción, 10 s, intento tras intento: un aviso
 * que entra con una transición pasa en cuanto termina —una captura que no llega
 * a tiempo es otro intento, no un rojo en el acto (REV-Z-R-1f-2)—, y uno que se
 * queda escondido da rojo con la lista de lo que falla en el último intento.
 */

import { expect } from '@playwright/test';
import { inflateSync } from 'node:zlib';

/** Alto de una línea de texto, en px. */
const ALTO_MINIMO = 14;

/** Ancho mínimo, el de un blanco de toque, en px. */
const ANCHO_MINIMO = 24;

/** Parte de la caja que tiene que quedar a la vista tras los recortes. */
const FRACCION_VISIBLE = 0.9;

/**
 * Cuánto puede asomar una caja (la de un elemento en línea, sin su relleno
 * vertical) fuera de su contenedor, o de un ancestro que la recorta, sin darla
 * por recortada: las letras de una línea apretada asoman de ella (con la del
 * aviso, de 17 px, y line-height:1, 1,5 px arriba y abajo), y WebKit redondea
 * las cajas. La ventana no la tiene.
 */
const HOLGURA = 4;

/** Alto mínimo de las letras pintadas, en px: la mitad de una línea. */
const ALTO_DE_LETRA_MINIMO = 7;

/**
 * Contraste de un píxel con el de detrás para contarlo como tinta de letra.
 * No es el de WCAG: sólo separa lo que pintan las letras de lo demás, para
 * medir su alto y sus colores. El de cada letra es CONTRASTE_AA.
 */
const CONTRASTE_DE_TINTA = 3;

/** WCAG AA: 4,5:1 para el texto normal, 3:1 para el grande (24 px, o 18,66 en negrita). */
const CONTRASTE_AA = { normal: 4.5, grande: 3 };

/**
 * Una letra más estrecha, en px, no se pinta: así deja WebKit, en el sitio de
 * los puntos suspensivos, las que corta un text-overflow:ellipsis.
 */
const LETRA_SIN_ANCHO = 0.5;

/**
 * Ancho medio de las letras de un texto, en em de su tamaño, sin contar la
 * puntuación. Medido en la promesa: 0,60 en Chromium y 0,67 en WebKit, que
 * redondea cada caja; con letter-spacing:-6px, 0,20 y 0,25; con -3px, 0,39 y
 * 0,45; con scaleX(.15), 0,09 y 0,10.
 */
const ANCHO_MEDIO_MINIMO = 0.3;

/** Letras (sin la puntuación) que hacen falta para sacar la media: un «.» suelto mide 0,25 em. */
const LETRAS_PARA_LA_MEDIA = 3;

/**
 * Cuánto puede pisar la caja de una letra la de otra a la vista, en parte de la
 * menor de las dos. Las de una línea van una tras otra y sólo se tocan; las de
 * dos líneas, con la letra de 14 px del aviso (cajas de 17 px), no se pisan con
 * su interlineado de 22,4 y se pisan 3 px con line-height:1 (0,18). Medido en
 * el espejo, Chromium y WebKit, escritorio y móvil, en tres fichas: ninguna
 * letra del aviso pisa otra. La promesa bajada una línea, word-spacing:-1em o
 * line-height:.5 las montan enteras o casi.
 */
const SOLAPE_MAXIMO = 0.25;

/** Colores distintos que dan unas letras suavizadas; una raya da uno. */
const COLORES_MINIMOS = 8;

/** Cuánto se espera a que quede a la vista, en ms: lo de cualquier aserción (expect.timeout). */
const ESPERA = 10_000;

/** Pausa entre un intento y el siguiente, en ms. */
const PAUSA = 250;

/**
 * Lo que recorta la caja, medido en la página tras desplazar la ventana.
 *
 * @param {Element} el Elemento.
 * @param {{dentroDe: string|null, alto: number, ancho: number, fraccion: number, holgura: number}} o Umbrales.
 * @returns {{fallos: string[], y: number}} Fallos (vacío si está a la vista) y dónde queda la caja.
 */
function queLoRecorta( el, o ) {
	const html = document.documentElement;
	// Con el overflow de <html> visible, el del <body> pasa a la ventana (CSS
	// Overflow 3) y el body no recorta ni desplaza nada: es la ventana.
	const h = getComputedStyle( html );
	const bodyALaVentana = 'visible' === h.overflowX && 'visible' === h.overflowY;
	// Los contenedores que el cliente desplaza con el dedo o la rueda (overflow
	// auto o scroll, con algo que desplazar), del más interior al más exterior,
	// y la ventana al final: cada uno deja el elemento en su centro. Con
	// html,body{height:100%;overflow-x:hidden} el que desplaza la página es el
	// <body>, y mover sólo la ventana no movía nada (REV-Z-R-2o-3).
	for ( let e = el.parentElement; e && e !== html; e = e.parentElement ) {
		const cs = getComputedStyle( e );
		const desplaza = ( v ) => [ 'auto', 'scroll', 'overlay' ].includes( v ) && ! ( e === document.body && bodyALaVentana );
		const x = desplaza( cs.overflowX ) && e.scrollWidth > e.clientWidth;
		const y = desplaza( cs.overflowY ) && e.scrollHeight > e.clientHeight;
		if ( x || y ) {
			const a = el.getBoundingClientRect();
			const c = e.getBoundingClientRect();
			e.scrollBy( {
				left: x ? Math.round( a.left + ( a.width / 2 ) - ( c.left + e.clientLeft + ( e.clientWidth / 2 ) ) ) : 0,
				top: y ? Math.round( a.top + ( a.height / 2 ) - ( c.top + e.clientTop + ( e.clientHeight / 2 ) ) ) : 0,
				behavior: 'instant',
			} );
		}
	}
	const antes = el.getBoundingClientRect();
	window.scrollBy( 0, Math.round( antes.top + ( antes.height / 2 ) - ( innerHeight / 2 ) ) );
	const r = el.getBoundingClientRect();
	const fallos = [];
	if ( r.height < o.alto || r.width < o.ancho ) {
		fallos.push( `caja de ${ Math.round( r.width ) }x${ Math.round( r.height ) } px, menos de una línea (${ o.ancho }x${ o.alto })` );
	}
	// Lo que ocupa en su línea: un elemento en línea no le suma el relleno ni el
	// borde de arriba y de abajo, que asoman de ella (4 px en «Ver programa»).
	// Contarlos daba rojo con todas sus letras a la vista: un overflow:hidden
	// del aviso se los come (REV-Z-R-2f-1), y con un interlineado apretado
	// —line-height 1, o 1.2 en WebKit— se salían del aviso más de la holgura.
	const propio = getComputedStyle( el );
	const enLinea = 'inline' === propio.display;
	const arriba = enLinea ? parseFloat( propio.paddingTop ) + parseFloat( propio.borderTopWidth ) : 0;
	const abajo = enLinea ? parseFloat( propio.paddingBottom ) + parseFloat( propio.borderBottomWidth ) : 0;
	const trozos = [ ...el.getClientRects() ].map( ( t ) => ( { left: t.left, right: t.right, top: t.top + arriba, bottom: t.bottom - abajo } ) );

	const recortes = [ { quien: 'la ventana', left: 0, top: 0, right: html.clientWidth, bottom: html.clientHeight } ];
	for ( let e = el; e && e !== html; e = e.parentElement ) {
		const cs = getComputedStyle( e );
		const c = e.getBoundingClientRect();
		const nombre = e.tagName.toLowerCase() + ( e.classList[ 0 ] ? `.${ e.classList[ 0 ] }` : '' );
		// El overflow de un ancestro; el del propio elemento sólo recorta a sus
		// hijos, y eso se mira letra a letra (letrasDe). Con la holgura, como
		// dentroDe: las letras de una línea apretada asoman de ella.
		const recorta = e !== el && ! ( e === document.body && bodyALaVentana );
		const x = recorta && 'visible' !== cs.overflowX;
		const y = recorta && 'visible' !== cs.overflowY;
		if ( x || y ) {
			recortes.push( {
				quien: `${ nombre } (overflow ${ cs.overflowX } ${ cs.overflowY })`,
				left: x ? c.left - o.holgura : -Infinity,
				right: x ? c.right + o.holgura : Infinity,
				top: y ? c.top - o.holgura : -Infinity,
				bottom: y ? c.bottom + o.holgura : Infinity,
			} );
		}
		const clip = /^rect\((.*)\)$/.exec( cs.clip );
		if ( clip && ( 'absolute' === cs.position || 'fixed' === cs.position ) ) {
			const [ arriba, derecha, abajo, izquierda ] = clip[ 1 ].split( /[\s,]+/ ).map( ( v ) => ( 'auto' === v ? null : parseFloat( v ) ) );
			recortes.push( {
				quien: `${ nombre } (clip ${ cs.clip })`,
				left: c.left + ( izquierda ?? 0 ),
				top: c.top + ( arriba ?? 0 ),
				right: null === derecha ? c.right : c.left + derecha,
				bottom: null === abajo ? c.bottom : c.top + abajo,
			} );
		}
	}

	let total = 0;
	let visible = 0;
	const culpables = new Set();
	for ( const t of trozos ) {
		total += Math.max( 0, t.right - t.left ) * Math.max( 0, t.bottom - t.top );
		const v = { ...t };
		for ( const k of recortes ) {
			if ( t.left < k.left - 0.5 || t.top < k.top - 0.5 || t.right > k.right + 0.5 || t.bottom > k.bottom + 0.5 ) {
				culpables.add( k.quien );
			}
			v.left = Math.max( v.left, k.left );
			v.top = Math.max( v.top, k.top );
			v.right = Math.min( v.right, k.right );
			v.bottom = Math.min( v.bottom, k.bottom );
		}
		visible += Math.max( 0, v.right - v.left ) * Math.max( 0, v.bottom - v.top );
	}
	if ( total > 0 && visible < o.fraccion * total ) {
		fallos.push( `a la vista sólo el ${ Math.round( ( 100 * visible ) / total ) } % de su caja (en ${ Math.round( r.left ) },${ Math.round( r.top ) }), `
			+ `hace falta el ${ Math.round( 100 * o.fraccion ) } %: la recorta ${ [ ...culpables ].join( ', ' ) }` );
	}

	if ( o.dentroDe ) {
		const caja = el.parentElement ? el.parentElement.closest( o.dentroDe ) : null;
		if ( ! caja ) {
			fallos.push( `no está dentro de ${ o.dentroDe }` );
		} else {
			const c = caja.getBoundingClientRect();
			const u = {
				left: Math.min( ...trozos.map( ( t ) => t.left ) ),
				top: Math.min( ...trozos.map( ( t ) => t.top ) ),
				right: Math.max( ...trozos.map( ( t ) => t.right ) ),
				bottom: Math.max( ...trozos.map( ( t ) => t.bottom ) ),
			};
			if ( u.top < c.top - o.holgura || u.bottom > c.bottom + o.holgura || u.left < c.left - o.holgura || u.right > c.right + o.holgura ) {
				const px = ( n ) => Math.round( n );
				fallos.push( `se sale de ${ o.dentroDe }: ${ px( u.left ) },${ px( u.top ) }-${ px( u.right ) },${ px( u.bottom ) } `
					+ `en ${ px( c.left ) },${ px( c.top ) }-${ px( c.right ) },${ px( c.bottom ) }` );
			}
		}
	}

	return { fallos, y: r.top };
}

/**
 * Pone o quita las letras transparentes del elemento y de sus hijos.
 *
 * Va en una capa de cascada declarada la primera, y así su !important gana al
 * de cualquier hoja. Al de un atributo style no, pero pone también
 * -webkit-text-fill-color, que es lo que pinta las letras: sólo uno de ésos
 * con !important en línea las dejaría iguales, y daría rojo. También su
 * ::first-letter, que puede llevar color propio: esa letra saldría igual en las
 * dos capturas y sin tinta.
 *
 * Sus ::before y ::after, y los de sus hijos, se pintan igual en las dos: si
 * cambiaran, las letras de una capa así contarían como tinta de lo que tapa
 * (REV-Z-R-1o-3). Heredarían el transparente, y darles el color del elemento
 * tampoco bastaba: una capa con `color` propio y sin -webkit-text-fill-color
 * tomaba el de éste sólo en la segunda captura (REV-Z-R-2o-1). Así que se lee
 * el `color` y el -webkit-text-fill-color con que se pinta cada una y se fijan
 * con !important en la misma capa. Un seudoelemento no tiene atributo style que
 * pueda ganarle, y ni un `color: inherit` explícito hereda ya el transparente.
 *
 * Al quitarla, primero vuelve el color con las transiciones aún apagadas y sólo
 * después se van: si no, el texto volvería fundiéndose y la siguiente captura
 * lo vería a medias.
 *
 * @param {Element} el Elemento.
 * @param {boolean} poner true para borrarlas, false para devolverlas.
 * @returns {number} Dónde queda la caja: si se movió, las capturas no casan.
 */
function sinLetras( el, poner ) {
	const suyas = ':is([data-akb-sonda-letras], [data-akb-sonda-letras] *)';
	const capa = ( reglas ) => `@layer akb-sonda-letras { ${ reglas } }`;
	const borrar = '{ color: transparent !important; -webkit-text-fill-color: transparent !important; '
		+ 'text-decoration-color: transparent !important; transition: none !important; }';
	const todos = [ el, ...el.querySelectorAll( '*' ) ];
	if ( poner ) {
		const fijos = todos.flatMap( ( e, i ) => {
			e.setAttribute( 'data-akb-sonda-capa', String( i ) );
			return [ '::before', '::after' ].map( ( seudo ) => {
				const cs = getComputedStyle( e, seudo );
				return `[data-akb-sonda-capa="${ i }"]${ seudo } { color: ${ cs.color } !important; `
					+ `-webkit-text-fill-color: ${ cs.getPropertyValue( '-webkit-text-fill-color' ) } !important; }`;
			} );
		} );
		const hoja = document.createElement( 'style' );
		hoja.id = 'akb-sonda-letras';
		// Reglas separadas: un selector que un motor no entienda no tumba las demás.
		hoja.textContent = capa( `${ suyas } ${ borrar } ${ suyas }::first-letter ${ borrar } ${ fijos.join( ' ' ) }` );
		document.head.prepend( hoja );
		el.setAttribute( 'data-akb-sonda-letras', '' );
	} else {
		const hoja = /** @type {HTMLStyleElement} */ ( document.getElementById( 'akb-sonda-letras' ) );
		hoja.textContent = capa( `${ suyas } { transition: none !important; }` );
		void document.documentElement.offsetWidth;
		el.removeAttribute( 'data-akb-sonda-letras' );
		todos.forEach( ( e ) => e.removeAttribute( 'data-akb-sonda-capa' ) );
		hoja.remove();
	}
	return el.getBoundingClientRect().top;
}

/**
 * Píxeles de un PNG de 8 bits, RGB o RGBA, sin entrelazar: lo que devuelve
 * `screenshot()` en Chromium y en WebKit.
 *
 * @param {Buffer} png Imagen.
 * @returns {{ancho: number, alto: number, canales: number, px: Buffer}} Píxeles, fila a fila.
 */
function leerPng( png ) {
	let ancho = 0;
	let alto = 0;
	let formato = '';
	const datos = [];
	for ( let i = 8; i < png.length; i += 12 + png.readUInt32BE( i ) ) {
		const trozo = png.subarray( i + 8, i + 8 + png.readUInt32BE( i ) );
		const tipo = png.toString( 'ascii', i + 4, i + 8 );
		if ( 'IHDR' === tipo ) {
			ancho = trozo.readUInt32BE( 0 );
			alto = trozo.readUInt32BE( 4 );
			formato = `${ trozo[ 8 ] }-${ trozo[ 9 ] }-${ trozo[ 12 ] }`;
		} else if ( 'IDAT' === tipo ) {
			datos.push( trozo );
		}
	}
	// Profundidad-color-entrelazado: 8 bits, RGB (2) o RGBA (6), sin entrelazar.
	const canales = { '8-2-0': 3, '8-6-0': 4 }[ formato ];
	if ( ! canales ) {
		throw new Error( `captura en un PNG que no se sabe leer (${ formato })` );
	}
	const crudo = inflateSync( Buffer.concat( datos ) );
	const fila = ancho * canales;
	const px = Buffer.alloc( alto * fila );
	for ( let y = 0; y < alto; y++ ) {
		const filtro = crudo[ y * ( fila + 1 ) ];
		for ( let x = 0; x < fila; x++ ) {
			const a = x >= canales ? px[ ( y * fila ) + x - canales ] : 0;
			const b = y > 0 ? px[ ( ( y - 1 ) * fila ) + x ] : 0;
			const c = x >= canales && y > 0 ? px[ ( ( y - 1 ) * fila ) + x - canales ] : 0;
			const p = a + b - c;
			const paeth = Math.abs( p - a ) <= Math.abs( p - b ) && Math.abs( p - a ) <= Math.abs( p - c ) ? a : ( Math.abs( p - b ) <= Math.abs( p - c ) ? b : c );
			px[ ( y * fila ) + x ] = crudo[ ( y * ( fila + 1 ) ) + 1 + x ] + [ 0, a, b, ( a + b ) >> 1, paeth ][ filtro ];
		}
	}
	return { ancho, alto, canales, px };
}

/**
 * Luminancia relativa (WCAG) del píxel que empieza en `i`.
 *
 * @param {Buffer} px Píxeles.
 * @param {number} i Índice del canal rojo.
 * @returns {number} De 0 a 1.
 */
function luz( px, i ) {
	const lineal = ( c ) => ( c <= 10 ? c / 255 / 12.92 : ( ( ( c / 255 ) + 0.055 ) / 1.055 ) ** 2.4 );
	return ( 0.2126 * lineal( px[ i ] ) ) + ( 0.7152 * lineal( px[ i + 1 ] ) ) + ( 0.0722 * lineal( px[ i + 2 ] ) );
}

/**
 * Cada letra que pinta el elemento, con lo que necesita para leerse: su caja
 * (un Range por letra de cada nodo de texto; los blancos y los acentos que se
 * combinan no cuentan), relativa al origen de la captura —la esquina de la caja
 * redondeada hacia abajo, como la recorta Playwright—; el contraste que le pide
 * WCAG AA por su tamaño; su tamaño de letra; lo que la deja fuera, si su centro
 * no cae dentro de algún elemento con overflow distinto de visible entre su
 * padre y el elemento, los dos incluidos (su caja de relleno); y la letra sobre
 * la que va montada, si la hay. Una letra que no ocupa sitio (display:none,
 * font-size:0) no pinta nada y no cuenta.
 *
 * Montada: su caja pisa la de otra letra a la vista, suya o de cualquier otro
 * texto de la página, en más de `o.solape` de la menor de las dos
 * (SOLAPE_MAXIMO). Otra letra está a la vista si su texto lo está
 * (checkVisibility, también la opacidad) y ningún ancestro con overflow deja
 * fuera su centro: las de un texto sólo para lectores de pantalla no pisan nada.
 *
 * @param {Element} el Elemento.
 * @param {{aa: {normal: number, grande: number}, solape: number, sinAncho: number}} o Contraste de WCAG AA, solape máximo y ancho de una letra que no se pinta.
 * @returns {Array<{texto: number, frase: string, letra: string, x0: number, y0: number, x1: number, y1: number, contraste: number, px: number, puntuacion: boolean, fuera: string|null, montada: string|null}>} Letras.
 */
function letrasDe( el, o ) {
	const r = el.getBoundingClientRect();
	const rango = document.createRange();
	// Lo que recorta un texto: la caja de relleno de cada elemento con overflow
	// distinto de visible, desde su padre hasta `hasta` incluido, o hasta el
	// <body> sin incluirlo si `hasta` es null.
	const recortesDe = ( padre, hasta ) => {
		const recortes = [];
		for ( let e = padre; e && e !== document.body; e = e === hasta ? null : e.parentElement ) {
			const c = getComputedStyle( e );
			const x = 'visible' !== c.overflowX;
			const y = 'visible' !== c.overflowY;
			if ( x || y ) {
				const b = e.getBoundingClientRect();
				recortes.push( {
					quien: `${ e.tagName.toLowerCase() }${ e.classList[ 0 ] ? `.${ e.classList[ 0 ] }` : '' } (overflow ${ c.overflowX } ${ c.overflowY })`,
					left: x ? b.left + parseFloat( c.borderLeftWidth ) : -Infinity,
					right: x ? b.right - parseFloat( c.borderRightWidth ) : Infinity,
					top: y ? b.top + parseFloat( c.borderTopWidth ) : -Infinity,
					bottom: y ? b.bottom - parseFloat( c.borderBottomWidth ) : Infinity,
				} );
			}
		}
		return recortes;
	};
	// Las letras de un nodo de texto, con su caja en la ventana y el recorte
	// que deja fuera su centro, si lo hay.
	const cajasDe = ( t, recortes ) => {
		const cajas = [];
		let i = 0;
		for ( const letra of t.data ) {
			if ( /[\p{L}\p{N}\p{P}\p{S}]/u.test( letra ) ) {
				rango.setStart( t, i );
				rango.setEnd( t, i + letra.length );
				const l = rango.getBoundingClientRect();
				if ( l.height > 0 ) {
					const cx = ( l.left + l.right ) / 2;
					const cy = ( l.top + l.bottom ) / 2;
					const k = recortes.find( ( c ) => cx < c.left || cx > c.right || cy < c.top || cy > c.bottom );
					cajas.push( { letra, l, fuera: k ? k.quien : null } );
				}
			}
			i += letra.length;
		}
		return cajas;
	};
	// Cuánto pisa una caja a otra, en parte de la menor. Sin un píxel de cada
	// lado del cruce: WebKit redondea hacia fuera la caja de cada letra, y las
	// de dos letras seguidas se pisan hasta 1 px (en una «r» o una «,», hasta
	// el 20 % de su caja).
	const solape = ( a, b ) => {
		const ancho = Math.min( a.right, b.right ) - Math.max( a.left, b.left ) - 1;
		const alto = Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top ) - 1;
		const menor = Math.min( a.width * a.height, b.width * b.height );
		return ancho > 0 && alto > 0 && menor > 0 ? ( ancho * alto ) / menor : 0;
	};

	const letras = [];
	const suyas = [];
	const textos = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
	let n = 0;
	for ( let t = textos.nextNode(); t; t = textos.nextNode(), n++ ) {
		const padre = /** @type {Element} */ ( t.parentElement );
		const cs = getComputedStyle( padre );
		const px = parseFloat( cs.fontSize );
		const contraste = px >= 24 || ( px >= 18.66 && Number( cs.fontWeight ) >= 700 ) ? o.aa.grande : o.aa.normal;
		const frase = /** @type {Text} */ ( t ).data.trim().slice( 0, 40 );
		for ( const c of cajasDe( t, recortesDe( padre, el ) ) ) {
			const letra = {
				texto: n,
				frase,
				letra: c.letra,
				x0: c.l.left - Math.floor( r.left ),
				y0: c.l.top - Math.floor( r.top ),
				x1: c.l.right - Math.floor( r.left ),
				y1: c.l.bottom - Math.floor( r.top ),
				contraste,
				px,
				puntuacion: /\p{P}/u.test( c.letra ),
				fuera: c.fuera,
				montada: null,
			};
			letras.push( letra );
			suyas.push( { ...c, frase, suya: letra } );
		}
	}

	// Las letras a la vista que pueden pisar las suyas: las suyas mismas y las
	// de cada texto de la página que se cruce con ellas.
	const pinta = ( c ) => ! c.fuera && c.l.width >= o.sinAncho;
	const propias = suyas.filter( pinta );
	if ( propias.length ) {
		const zona = {
			left: Math.min( ...propias.map( ( c ) => c.l.left ) ),
			top: Math.min( ...propias.map( ( c ) => c.l.top ) ),
			right: Math.max( ...propias.map( ( c ) => c.l.right ) ),
			bottom: Math.max( ...propias.map( ( c ) => c.l.bottom ) ),
		};
		const otras = [ ...propias ];
		const todos = document.createTreeWalker( document.body, NodeFilter.SHOW_TEXT );
		for ( let t = todos.nextNode(); t; t = todos.nextNode() ) {
			const padre = t.parentElement;
			if ( ! padre || el.contains( t ) || ! /\S/.test( /** @type {Text} */ ( t ).data ) ) {
				continue;
			}
			rango.selectNodeContents( t );
			const b = rango.getBoundingClientRect();
			if ( b.right <= zona.left || b.left >= zona.right || b.bottom <= zona.top || b.top >= zona.bottom
				|| ! padre.checkVisibility( { opacityProperty: true, visibilityProperty: true } ) ) {
				continue;
			}
			const frase = /** @type {Text} */ ( t ).data.trim().slice( 0, 40 );
			otras.push( ...cajasDe( t, recortesDe( padre, null ) ).filter( pinta ).map( ( c ) => ( { ...c, frase } ) ) );
		}
		for ( const a of propias ) {
			const b = otras.find( ( c ) => c !== a && solape( a.l, c.l ) > o.solape );
			if ( b ) {
				a.suya.montada = `«${ b.letra }» de «${ b.frase }»`;
			}
		}
	}
	return letras;
}

/**
 * Lo que impide leer las letras, comparando la captura tal cual con la de las
 * letras transparentes.
 *
 * @param {ReturnType<typeof leerPng>} con Tal cual.
 * @param {ReturnType<typeof leerPng>} sin Con las letras transparentes.
 * @param {ReturnType<typeof letrasDe>} letras Dónde va cada letra y qué necesita.
 * @returns {string[]} Vacío si se leen.
 */
function queNoSeLee( con, sin, letras ) {
	if ( con.ancho !== sin.ancho || con.alto !== sin.alto ) {
		return [ `las dos capturas no casan: ${ con.ancho }x${ con.alto } y ${ sin.ancho }x${ sin.alto }` ];
	}
	const contraste = new Float32Array( con.ancho * con.alto );
	const colores = new Set();
	const conTinta = new Array( con.alto ).fill( false );
	for ( let y = 0; y < con.alto; y++ ) {
		for ( let x = 0; x < con.ancho; x++ ) {
			const i = ( ( y * con.ancho ) + x ) * con.canales;
			const j = ( ( y * sin.ancho ) + x ) * sin.canales;
			const l1 = luz( con.px, i );
			const l2 = luz( sin.px, j );
			const c = ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
			contraste[ ( y * con.ancho ) + x ] = c;
			if ( c >= CONTRASTE_DE_TINTA ) {
				conTinta[ y ] = true;
				colores.add( con.px.readUIntBE( i, 3 ) );
			}
		}
	}
	let racha = 0;
	let altoDeLetra = 0;
	for ( const hay of conTinta ) {
		racha = hay ? racha + 1 : 0;
		altoDeLetra = Math.max( altoDeLetra, racha );
	}

	const fallos = [];
	// Los píxeles enteros de una caja, sin los que comparte con la vecina; en
	// una de menos de un píxel, el que la contiene.
	const dentro = ( a, b ) => ( Math.floor( b ) > Math.ceil( a ) ? [ Math.ceil( a ), Math.floor( b ) ] : [ Math.floor( a ), Math.ceil( b ) ] );
	// Cada letra en su sitio, agrupadas por texto para el mensaje: el aviso no
	// se lee porque asome «Ver programa», ni la promesa porque asome «480 Punt».
	const textos = new Map();
	for ( const l of letras ) {
		const t = textos.get( l.texto ) || { frase: l.frase, contraste: l.contraste, total: 0, quien: '', fuera: '', sinAncho: '', sinTinta: '', montadas: '', sobre: '', ems: 0, cuenta: 0 };
		textos.set( l.texto, t );
		t.total++;
		if ( l.montada ) {
			t.montadas += l.letra;
			t.sobre = t.sobre || l.montada;
		}
		if ( l.fuera ) {
			t.quien = l.fuera;
			t.fuera += l.letra;
			continue;
		}
		if ( l.x1 - l.x0 < LETRA_SIN_ANCHO ) {
			t.sinAncho += l.letra;
			continue;
		}
		if ( ! l.puntuacion ) {
			t.ems += ( l.x1 - l.x0 ) / l.px;
			t.cuenta++;
		}
		const [ x0, x1 ] = dentro( l.x0, l.x1 );
		const [ y0, y1 ] = dentro( l.y0, l.y1 );
		let maximo = 0;
		for ( let y = Math.max( 0, y0 ); y < Math.min( con.alto, y1 ); y++ ) {
			for ( let x = Math.max( 0, x0 ); x < Math.min( con.ancho, x1 ); x++ ) {
				maximo = Math.max( maximo, contraste[ ( y * con.ancho ) + x ] );
			}
		}
		if ( maximo < l.contraste ) {
			t.sinTinta += l.letra;
		}
	}
	const cuantas = ( cuales, t ) => `${ [ ...cuales ].length } de ${ t.total } letras («${ [ ...cuales ].slice( 0, 30 ).join( '' ) }»)`;
	for ( const t of textos.values() ) {
		if ( t.fuera ) {
			fallos.push( `el texto «${ t.frase }» lo recorta ${ t.quien }: ${ cuantas( t.fuera, t ) } quedan fuera` );
		}
		if ( t.sinAncho ) {
			fallos.push( `el texto «${ t.frase }» tiene ${ cuantas( t.sinAncho, t ) } sin ancho, que no se pintan (un text-overflow:ellipsis las deja así en WebKit)` );
		}
		if ( t.sinTinta ) {
			fallos.push( `el texto «${ t.frase }» no se lee: ${ cuantas( t.sinTinta, t ) } sin un píxel con contraste de ${ t.contraste }:1 (WCAG AA) donde van` );
		}
		if ( t.montadas ) {
			fallos.push( `el texto «${ t.frase }» tiene ${ cuantas( t.montadas, t ) } montadas sobre otras letras (la primera, sobre ${ t.sobre }): `
				+ `su caja pisa la otra más del ${ Math.round( 100 * SOLAPE_MAXIMO ) } %` );
		}
		if ( t.cuenta >= LETRAS_PARA_LA_MEDIA && t.ems / t.cuenta < ANCHO_MEDIO_MINIMO ) {
			fallos.push( `el texto «${ t.frase }» tiene las letras amontonadas o aplastadas: miden de media ${ ( t.ems / t.cuenta ).toFixed( 2 ) } em `
				+ `de ancho (hacen falta ${ ANCHO_MEDIO_MINIMO })` );
		}
	}
	if ( colores.size < COLORES_MINIMOS ) {
		fallos.push( `sus letras no se distinguen de lo que tienen detrás: ${ colores.size } colores con contraste de ${ CONTRASTE_DE_TINTA }:1 o más `
			+ `(hacen falta ${ COLORES_MINIMOS }; unas letras suavizadas dan decenas)` );
	}
	if ( altoDeLetra < ALTO_DE_LETRA_MINIMO ) {
		fallos.push( `letras pintadas de ${ altoDeLetra } px de alto (hacen falta ${ ALTO_DE_LETRA_MINIMO })` );
	}
	return fallos;
}

/**
 * Exige que el elemento esté a la vista del cliente, no sólo «visible».
 *
 * @param {import('@playwright/test').Locator} locator Un solo elemento.
 * @param {string} que Qué se espera ver: es el mensaje del rojo.
 * @param {{dentroDe?: string}} [opciones] Selector del contenedor del que no puede salirse.
 * @returns {Promise<void>}
 */
export async function aLaVista( locator, que, opciones = {} ) {
	await expect( locator, que ).toBeVisible();
	// Con la fuente web aún cargando, el texto cambiaría entre las dos capturas.
	await locator.evaluate( () => document.fonts.ready.then( () => null ) );
	const umbrales = { dentroDe: opciones.dentroDe || null, alto: ALTO_MINIMO, ancho: ANCHO_MINIMO, fraccion: FRACCION_VISIBLE, holgura: HOLGURA };
	const captura = async () => leerPng( await locator.screenshot( { scale: 'css', timeout: 5_000 } ) );
	const intento = async () => {
		// La caja primero: la captura desplaza también los contenedores y
		// destaparía lo que recortan.
		const caja = await locator.evaluate( queLoRecorta, umbrales );
		if ( caja.fallos.length ) {
			return caja.fallos;
		}
		const letras = await locator.evaluate( letrasDe, { aa: CONTRASTE_AA, solape: SOLAPE_MAXIMO, sinAncho: LETRA_SIN_ANCHO } );
		let con;
		let sin;
		let y = caja.y;
		try {
			con = await captura();
			await locator.evaluate( sinLetras, true );
			try {
				sin = await captura();
			} finally {
				y = await locator.evaluate( sinLetras, false );
			}
		} catch ( e ) {
			// Una captura que no llega a tiempo (el elemento aún se mueve, un
			// fotograma lento) es otro intento: con expect.poll, lanzarla cortaba
			// la espera en el acto (REV-Z-R-1f-2).
			return [ `no se pudo capturar: ${ String( e instanceof Error ? e.message : e ).split( '\n' )[ 0 ] }` ];
		}
		if ( Math.abs( y - caja.y ) > 0.5 ) {
			return [ `se movió mientras se medía (de ${ Math.round( caja.y ) } a ${ Math.round( y ) } px)` ];
		}
		return queNoSeLee( con, sin, letras );
	};
	// Un intento tras otro, nunca dos a la vez: al agotar su plazo, expect.poll
	// deja corriendo el intento en curso, que seguía borrando y devolviendo las
	// letras después del rojo. La medida siguiente leía entonces el color
	// transparente y daba verde (medido en WebKit, con una capa con letras sobre
	// la promesa medida antes que el aviso: el aviso salía en verde de 2 a 6
	// veces de 16; en este bucle, ninguna).
	const fin = Date.now() + ESPERA;
	let fallos = await intento();
	while ( fallos.length && Date.now() < fin ) {
		await new Promise( ( listo ) => setTimeout( listo, PAUSA ) );
		fallos = await intento();
	}
	expect( fallos, `${ que }: está en la página, pero el cliente no lo ve` ).toEqual( [] );
}
