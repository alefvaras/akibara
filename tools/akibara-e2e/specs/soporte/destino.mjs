// @ts-check
/**
 * Contra un sitio que no es el espejo local, la suite corre SÓLO en sólo lectura;
 * y en sólo lectura, SÓLO con el presupuesto de cargas. No le pide nada al sitio.
 *
 * Hasta el 2026-09-26 aquí se hacía un POST a `guest.vary.php` de LiteSpeed para
 * que la suite entrara como visitante que vuelve: Guest Mode recargaba la página
 * de todo visitante nuevo y la recarga pisaba las pruebas. Guest Mode está
 * apagado en producción desde el 2026-09-19 (lo vigila `primera-visita.spec.mjs`,
 * que sale en rojo si alguien lo vuelve a encender), así que el saludo ya no
 * servía de nada; y era la única petición que escribía en `npm run test:prod` sin
 * que ninguna prueba pudiera saltársela (E2E-2, verificación del 2026-09-26).
 *
 * El presupuesto (REV-SE-1): el tope de cargas y el detector de escrituras viven
 * en un reporter, y `--reporter` en la línea de comandos REEMPLAZA entera la lista
 * de la config. La verificación de producción del 2026-09-26 corrió con
 * `--reporter=list,json` y, así, sin tope y sin detector, sin que nada lo dijera.
 *
 * Se decide DOS veces:
 *
 *  - `guardiaAlCargar()`, desde `playwright.config.mjs`, al cargar la config
 *    (REV2-SE-2, REV-SE-2f-1). Es lo primero que hace Playwright: una corrida
 *    rechazada aquí no ha borrado nada ni creado ningún reporter. Rechazada en el
 *    globalSetup, en cambio, Playwright ya había vaciado `test-results` —su tarea
 *    «clear output» va antes que el globalSetup—, con las capturas y el
 *    `error-context.md` de la última corrida de verdad; y el reporter html ya
 *    había reescrito el informe con uno vacío.
 *  - `guardiaDeDestino()`, el globalSetup, como segunda red: mira la lista de
 *    reporters ya resuelta por Playwright, la que de verdad va a correr, por si
 *    llegó por un camino que la línea de comandos no dice.
 *
 * La línea de comandos la lee el commander de Playwright, no un `includes()`
 * (REV-C-E-1o-3): un `--list` tras «--» es un filtro de ficheros y tras `--grep`
 * es su valor, y en los dos casos la corrida es de verdad. Leída en crudo, la
 * guardia la daba por «en seco», la dejaba pasar sin el presupuesto y la
 * rechazaba el globalSetup con `test-results` ya vaciado.
 */
import { existsSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { soloLectura } from './tienda.mjs';

const requerir = createRequire( import.meta.url );

/** El espejo de Docker (compose.yaml): lo único contra lo que se puede escribir. */
const ESPEJO = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?(\/|$)/;

/** El reporter del presupuesto de cargas, por su ruta real. */
const PRESUPUESTO = realpathSync( fileURLToPath( new URL( './presupuesto-de-cargas.mjs', import.meta.url ) ) );

/** Reporters de Playwright que se piden por su nombre (program.js, `builtInReporters`). */
const DE_PLAYWRIGHT = new Set( [ 'list', 'line', 'dot', 'json', 'junit', 'null', 'github', 'html', 'blob' ] );

const SIN_PRESUPUESTO = 'En sólo lectura la corrida lleva el presupuesto de cargas (el tope y el detector de escrituras), y --reporter reemplaza la lista de la config: así correría sin los dos. Añádelo a la lista, desde tools/akibara-e2e: --reporter=list,json,./specs/soporte/presupuesto-de-cargas.mjs';

/**
 * ¿Es este reporter el presupuesto de cargas?
 *
 * Playwright entrega los reporters de fichero con su ruta absoluta, vengan de la
 * config o de `--reporter`; los suyos («list», «json»…), por su nombre.
 *
 * @param {string} nombre Primer elemento de una entrada de `config.reporter`.
 * @returns {boolean} Si es este fichero.
 */
function esElPresupuesto( nombre ) {
	return isAbsolute( nombre ) && existsSync( nombre ) && PRESUPUESTO === realpathSync( nombre );
}

/** Contra un sitio que no es el espejo, sólo en sólo lectura. */
function comprobarSitio() {
	const sitio = process.env.AKB_BASE_URL || 'http://localhost:8080';
	if ( ! ESPEJO.test( sitio ) && ! soloLectura ) {
		throw new Error( `${ sitio } no es el espejo local: contra él la suite corre sólo en sólo lectura (AKB_SOLO_LECTURA=1, o npm run test:prod).` );
	}
}

/**
 * Las opciones de `playwright test` en esta línea de comandos, leídas como las
 * lee Playwright: con su commander y su tabla de opciones (`playwright/lib/program`).
 *
 * Una lectura propia, con su propia lista de las opciones que llevan valor, se
 * desfasaría en cuanto Playwright añadiera otra. En el proceso de la CLI ese
 * módulo ya está cargado, y la línea ya pasó por el mismo commander sin error.
 * Fuera de `playwright test` no hay opciones: un worker se lanza sin argumentos.
 *
 * @param {string[]} argv La línea de comandos (`node`, el guion, la orden…).
 * @returns {{ list?: boolean, reporter?: string, ui?: boolean, uiHost?: string, uiPort?: string }} Las opciones, con los nombres de commander.
 */
function opcionesDeLaLinea( argv ) {
	const [ , , orden, ...resto ] = argv;
	if ( 'test' !== orden ) {
		return {};
	}
	const { program } = requerir( 'playwright/lib/program' );
	const test = program.commands.find( ( /** @type {{ name: () => string }} */ c ) => 'test' === c.name() );
	if ( ! test ) {
		throw new Error( 'playwright/lib/program ya no trae la orden «test»: soporte/destino.mjs no sabe leer la línea de comandos. Revisarlo tras actualizar Playwright.' );
	}
	const lector = program.createCommand( 'test' ).copyInheritedSettings( test ).exitOverride();
	for ( const opcion of test.options ) {
		lector.addOption( opcion );
	}
	lector.parseOptions( resto );
	return lector.opts();
}

/**
 * ¿Es una corrida en seco (`--list`), tal como lo lee Playwright?
 *
 * La interfaz (`--ui`, `--ui-host`, `--ui-port`) y el modo vigilancia
 * (`PWTEST_WATCH`) no miran `--list`: toman otro camino en `runTests`
 * (program.js) y corren pruebas —la interfaz pone `cliListOnly = false` al
 * correrlas (runner/testRunner.js)—, con la config cargada en este mismo proceso.
 *
 * @param {string[]} [argv] La línea de comandos.
 * @returns {boolean} Si sólo lista, sin correr nada.
 */
export function enSecoDeLaLinea( argv = process.argv ) {
	const { list, ui, uiHost, uiPort } = opcionesDeLaLinea( argv );
	return true === list && ! ui && ! uiHost && ! uiPort && ! process.env.PWTEST_WATCH;
}

/**
 * Los reporters que pide `--reporter`, resueltos como los resuelve Playwright
 * (program.js, `resolveReporter`): los suyos por su nombre y el resto por su ruta
 * desde donde se lanzó. Como en su línea de comandos, si se repite vale el último;
 * y vacío (`--reporter=`) no pide nada: Playwright corre con la lista de la config
 * (`resolveReporterOption`, REV-CE-1f-2).
 *
 * @param {string[]} argv La línea de comandos.
 * @param {string} cwd Desde dónde se lanzó.
 * @returns {string[]|undefined} Los reporters pedidos; undefined sin `--reporter`.
 */
export function reportersDeLaLinea( argv, cwd ) {
	const { reporter } = opcionesDeLaLinea( argv );
	if ( ! reporter ) {
		return undefined;
	}
	return reporter.split( ',' ).map( ( r ) => ( DE_PLAYWRIGHT.has( r ) ? r : resolve( cwd, r ) ) );
}

/**
 * La guardia al cargar la config: antes de que Playwright borre o escriba nada.
 *
 * En seco (`--list`) no se corre ninguna prueba ni se toca el sitio: ni hace
 * falta el presupuesto ni importa a qué sitio apunte (REV-CE-1f-1). Así,
 * `npm run test:prod -- --list --reporter=list` vale, y un `--list` con
 * `AKB_BASE_URL` fuera del espejo lista aunque falte `AKB_SOLO_LECTURA`, como
 * antes de que esta guardia existiera.
 *
 * @param {string[]} [argv] La línea de comandos.
 * @param {string} [cwd] Desde dónde se lanzó.
 */
export function guardiaAlCargar( argv = process.argv, cwd = process.cwd() ) {
	if ( enSecoDeLaLinea( argv ) ) {
		return;
	}
	comprobarSitio();
	if ( ! soloLectura ) {
		return;
	}
	const pedidos = reportersDeLaLinea( argv, cwd );
	if ( pedidos && ! pedidos.some( esElPresupuesto ) ) {
		throw new Error( SIN_PRESUPUESTO );
	}
}

/**
 * El globalSetup: la segunda red, con la lista de reporters ya resuelta.
 *
 * @param {import('@playwright/test').FullConfig} config La config ya resuelta, con la línea de comandos aplicada.
 */
export default function guardiaDeDestino( config ) {
	comprobarSitio();
	if ( soloLectura && ! config.reporter.some( ( [ nombre ] ) => esElPresupuesto( nombre ) ) ) {
		throw new Error( SIN_PRESUPUESTO );
	}
}
