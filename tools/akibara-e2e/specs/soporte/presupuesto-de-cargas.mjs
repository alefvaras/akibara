// @ts-check
/**
 * Presupuesto de cargas de una corrida en sólo lectura (reporter).
 *
 * Contra producción cada navegación es una carga real de la tienda, con su PHP y
 * su caché. La verificación del despliegue del 2026-09-26 pasó de 500 con la
 * suite entera (el tope acordado por lente era de unas 80), y nadie lo supo
 * hasta contar a mano. Aquí se cuenta lo que la suite PIDE —`page.goto`,
 * `reload`, atrás/adelante y cada petición de `request`—, se imprime al final y,
 * si se pasa del tope, la corrida sale en rojo.
 *
 * Es una cota por abajo: un clic que navega (un enlace, un PJAX) no pasa por aquí.
 * Por eso el tope de `npm run test:prod` deja margen (README, «Contra producción»).
 *
 * Y en sólo lectura una petición que no sea GET/HEAD es un rojo aunque la prueba
 * pase: es exactamente lo que `AKB_SOLO_LECTURA` promete que no ocurre (E2E-2).
 *
 * El tope lo lee este reporter de `AKB_TOPE_CARGAS`, no de la config (REV-SE-1):
 * `--reporter` en la línea de comandos REEMPLAZA la lista de la config, y el
 * reporter que llega por ahí no trae opciones. Con el tope como opción, añadirlo a
 * mano a `--reporter` lo cargaba «sin tope». Que la lista lo traiga lo exige
 * `destino.mjs` antes de correr nada.
 */

import { methodMetainfo } from 'playwright-core/lib/utils';

/** Pasos de la API que cargan una página. */
const NAVEGA = /^(Navigate to "|Reload$|Go back$|Go forward$)/;

/**
 * Pasos de la API que hacen una petición HTTP (`request` y `page.request`).
 *
 * Playwright los titula `{method} "{url}"` con el método TAL COMO LO ESCRIBIÓ la
 * prueba —`post`, `Delete`— y es el servidor el que lo pasa a mayúsculas al
 * enviarlo; sin método, el título se queda con el molde `{method} "…"` y sale un
 * GET (REV2-SE-1). Con `[A-Z]+`, un `{ method: 'post' }` salía como POST de
 * verdad y no contaba ni como carga ni como escritura: la corrida en sólo
 * lectura, en verde. Medido con Playwright 1.56.1 en una corrida real.
 */
const PIDE = /^(\S+) "/;

/**
 * Lo que este reporter supone del catálogo con que Playwright titula los pasos
 * (`protocolMetainfo`). Si una versión nueva cambia uno de estos títulos, el
 * fichero no carga —y la corrida en sólo lectura no arranca— en vez de seguir
 * sin contar nada: un `fetch` titulado `Fetch "{url}"` metería «Fetch» en
 * `NO_PIDEN` y callaría al detector sin que nada lo dijera.
 */
const SUPONE = {
	'APIRequestContext.fetch': '{method} "{url}"',
	'Frame.goto': 'Navigate to "{url}"',
	'Page.reload': 'Reload',
	'Page.goBack': 'Go back',
	'Page.goForward': 'Go forward',
};
for ( const [ llamada, titulo ] of Object.entries( SUPONE ) ) {
	const tiene = methodMetainfo.get( llamada )?.title;
	if ( tiene !== titulo ) {
		throw new Error( `presupuesto-de-cargas.mjs: Playwright titula ${ llamada } ${ JSON.stringify( tiene ) } y este reporter cuenta con ${ JSON.stringify( titulo ) }. Sin eso no ve lo que la suite pide: revisarlo tras actualizar Playwright.` );
	}
}

/**
 * Los métodos de HTTP. Al frente de un título `Palabra "…"` son una petición
 * aunque el catálogo use esa palabra para otra cosa: `tracing.group()` se titula
 * `Trace "{name}"`, igual que un `fetch` con `method: 'Trace'`, que sale como
 * TRACE (REV-C-E-1o-1, REV-CE-1f-3). El reporter no recibe más que el título, y
 * ante la duda cuenta una escritura: una falsa alarma se ve, una escritura muda
 * no. La suite no usa `tracing.group()`.
 */
const HTTP = new Set( [ 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT' ] );

/**
 * Primeras palabras de los pasos que tienen esa misma forma y NO piden nada:
 * `Fill "hola"`, `Press "Enter"`, `Type "…"`, `Dispatch "click"`… Aceptar
 * cualquier palabra sin quitarlas contaba cada `fill` como una carga y una
 * escritura. Salen del mismo catálogo: una versión nueva que añada otro paso
 * así no se cuela como petición.
 *
 * El límite: un `fetch` con `method: 'Fill'` —o `Press`, `Type`…, con esa
 * grafía exacta— se titula igual que el paso y no se ve. Ninguno es un método de
 * HTTP; si una prueba lo escribe tal cual, sale en rojo `instrumento.spec.mjs`.
 */
export const NO_PIDEN = new Set(
	[ ...methodMetainfo.values() ]
		.map( ( { title } ) => /^([^\s{]+) "/.exec( title || '' )?.[ 1 ] )
		.filter( ( palabra ) => palabra && ! HTTP.has( palabra.toUpperCase() ) )
);

/** Métodos que sólo leen. */
const LEE = new Set( [ 'GET', 'HEAD' ] );

/**
 * El método HTTP que envía un paso de la API, o null si el paso no pide nada.
 *
 * @param {string} titulo Título del paso.
 * @returns {string|null} El método en mayúsculas, como sale al sitio.
 */
function metodoDelPaso( titulo ) {
	const pide = PIDE.exec( titulo );
	if ( ! pide || NO_PIDEN.has( pide[ 1 ] ) ) {
		return null;
	}
	return '{method}' === pide[ 1 ] ? 'GET' : pide[ 1 ].toUpperCase();
}

/** Pruebas más caras que se listan al final. */
const LAS_MAS_CARAS = 10;

/** Tope sin `AKB_TOPE_CARGAS`: la prueba de humo `@prod` se mide por debajo (README, «Contra producción»). */
const TOPE = 120;

/**
 * El tope de la corrida.
 *
 * Sin la variable, 120. `0` lo quita, y eso se hace sólo con permiso del dueño.
 * Cualquier otra cosa que no sea un entero ≥ 0 detiene la corrida: `Number()` da
 * NaN con «abc» y 0 con una variable vacía heredada del entorno, y los dos
 * apagaban el tope sin que nadie lo notara, igual que el `0` del permiso.
 *
 * @param {string|undefined} crudo El valor de `AKB_TOPE_CARGAS`.
 * @returns {number} Cargas permitidas; 0, sin tope.
 */
function topeDeCargas( crudo ) {
	if ( undefined === crudo ) {
		return TOPE;
	}
	if ( ! /^\d+$/.test( crudo ) ) {
		throw new Error( `AKB_TOPE_CARGAS=${ JSON.stringify( crudo ) } no es un número de cargas. Sin la variable el tope es ${ TOPE }; un entero lo cambia, y 0 lo quita (sólo con permiso del dueño).` );
	}
	return Number( crudo );
}

export default class PresupuestoDeCargas {
	constructor() {
		this.tope = topeDeCargas( process.env.AKB_TOPE_CARGAS );
		/** @type {Map<string, number>} */
		this.porPrueba = new Map();
		/** @type {string[]} */
		this.escrituras = [];
	}

	/**
	 * @param {import('@playwright/test/reporter').TestCase} prueba
	 * @param {import('@playwright/test/reporter').TestResult} _resultado
	 * @param {import('@playwright/test/reporter').TestStep} paso
	 */
	onStepBegin( prueba, _resultado, paso ) {
		if ( 'pw:api' !== paso.category ) {
			return;
		}
		const metodo = metodoDelPaso( paso.title );
		if ( ! metodo && ! NAVEGA.test( paso.title ) ) {
			return;
		}
		const nombre = `[${ prueba.parent.project()?.name }] ${ prueba.titlePath().slice( 2 ).join( ' › ' ) }`;
		this.porPrueba.set( nombre, ( this.porPrueba.get( nombre ) || 0 ) + 1 );
		if ( metodo && ! LEE.has( metodo ) ) {
			const sale = paso.title.startsWith( `${ metodo } ` ) ? '' : ` (sale como ${ metodo })`;
			this.escrituras.push( `${ nombre }: ${ paso.title }${ sale }` );
		}
	}

	/**
	 * @returns {{ status: 'failed' } | undefined}
	 */
	onEnd() {
		if ( ! this.porPrueba.size ) {
			return undefined; // `--list`, o nada corrió
		}
		const total = [ ...this.porPrueba.values() ].reduce( ( a, b ) => a + b, 0 );
		const caras = [ ...this.porPrueba ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] ).slice( 0, LAS_MAS_CARAS );
		const pasado = this.tope > 0 && total > this.tope;

		console.log( `\nCargas pedidas al sitio: ${ total }${ this.tope > 0 ? ` (tope ${ this.tope })` : ' (sin tope: AKB_TOPE_CARGAS=0)' } en ${ this.porPrueba.size } pruebas.` );
		for ( const [ nombre, n ] of caras ) {
			console.log( `  ${ String( n ).padStart( 4 ) }  ${ nombre }` );
		}
		if ( pasado ) {
			console.log( `ROJO: la corrida pidió ${ total } cargas y el tope es ${ this.tope }. Quita la etiqueta @prod a lo más caro o pide permiso antes de subir el tope.` );
		}
		if ( this.escrituras.length ) {
			console.log( `ROJO: ${ this.escrituras.length } peticiones que escriben en una corrida de sólo lectura:` );
			for ( const e of this.escrituras ) {
				console.log( `  ${ e }` );
			}
		}
		return pasado || this.escrituras.length ? { status: 'failed' } : undefined;
	}

	printsToStdio() {
		return false;
	}
}
