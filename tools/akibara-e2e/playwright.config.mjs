// @ts-check
import { defineConfig, devices } from '@playwright/test';
import { enSecoDeLaLinea, guardiaAlCargar } from './specs/soporte/destino.mjs';
import { soloLectura } from './specs/soporte/tienda.mjs';

// Lo primero, antes de que Playwright vacíe `test-results` o cree ningún reporter
// (REV2-SE-2): fuera del espejo, sólo en sólo lectura, y en sólo lectura con el
// presupuesto de cargas aunque la línea de comandos traiga su propio `--reporter`.
guardiaAlCargar();

/**
 * En seco (`--list`) el informe HTML no se toca (REV-SE-2f-1).
 *
 * Playwright 1.56 construye también en `--list` todos los reporters de la config
 * y llama a su `onEnd`, y el del html empieza por borrar su carpeta: un
 * `npm run test:prod -- --list` lanzado después de una corrida de verdad dejaba
 * `npm run report` con las pruebas listadas y ningún resultado.
 *
 * «En seco» es lo que Playwright lee como `--list` (REV-C-E-1o-3): tras «--» o
 * como valor de `--grep` la corrida es de verdad y escribe su informe.
 */
const enSeco = enSecoDeLaLinea();

/**
 * Dos proyectos porque esta tienda se rompe distinto en cada uno: el armador de
 * packs y el cajón de facetas son de escritorio, y el buscador móvil es una
 * hoja aparte con su propio foco.
 *
 * `AKB_BASE_URL` apunta al espejo por defecto. Contra producción se corre con
 * `AKB_SOLO_LECTURA=1`, que apaga todo lo que escribe (carrito, cupones,
 * sesiones): en la tienda de verdad sólo se mira. Contra cualquier sitio que no
 * sea el espejo, sin esa variable la suite ni arranca (`soporte/destino.mjs`),
 * salvo en seco (`--list`): listar no le pide nada al sitio.
 */
const baseURL = process.env.AKB_BASE_URL || 'http://localhost:8080';

/**
 * Navegador ya instalado en esta máquina.
 *
 * Playwright pide el Chromium de SU revisión exacta y lo descarga al vuelo. En
 * esta máquina esa descarga se queda colgada —medido el 2026-09-18: dos horas y
 * media y 624 KB de 150 MB—, así que se apunta al que ya está en la caché. Si
 * `npx playwright install chromium` llega a funcionar algún día, basta con
 * borrar `AKB_CHROMIUM` del entorno y esta constante.
 */
/** Specs que escriben en el sitio y por eso no pueden correr junto a nadie. */
// `canje-de-puntos` también siembra (un cliente con saldo, por Docker): corriendo junto a
// los demás le quitaba el espejo a quien estaba agregando al carrito y daba un rojo de
// carga, verde al correrlo solo (medido el 2026-09-19).
const SIEMBRA = /(vocabulario|canje-de-puntos|checkout-direccion-guardada)\.spec\.mjs$/;

/**
 * Lo que no depende del tamaño de pantalla corre UNA vez.
 *
 * `cabeceras` mira códigos de estado y cabeceras HTTP: repetirlo en móvil
 * comprueba exactamente lo mismo y duplica la parte más lenta de la suite
 * (medido: 31 min en total, la mitad aquí).
 */
const SOLO_UNA_VEZ = /cabeceras\.spec\.mjs$/;

const cache = `${ process.env.HOME }/Library/Caches/ms-playwright`;

/** El «headless shell» recortado: rápido, y suficiente para escritorio. */
const chromium = process.env.AKB_CHROMIUM
	|| `${ cache }/chromium_headless_shell-1234/chrome-headless-shell-mac-arm64/chrome-headless-shell`;

/**
 * Móvil SIN el preset de dispositivo de Playwright.
 *
 * `devices['iPhone 13']` pide `deviceScaleFactor: 3`, y en esta máquina eso
 * cuelga el arranque: el binario responde
 * `CVDisplayLinkCreateWithCGDisplay failed` y el `launch` agota sus 180 s. Seis
 * pruebas «fallaban» sin un solo mensaje y cada corrida pasaba de diez minutos
 * (medido el 2026-09-18). El Chromium completo tampoco sirve: ése se queda
 * esperando a los servicios de Google (GCM) al arrancar.
 *
 * Lo que de verdad hace falta para probar la tienda en móvil es el ANCHO —el
 * buscador se vuelve una hoja aparte, la barra inferior aparece— y el táctil.
 * La densidad de píxeles no cambia ninguna de las dos cosas.
 */
const MOVIL = {
	viewport: { width: 390, height: 844 },
	hasTouch: true,
	isMobile: true,
	userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 AkibaraQA/e2e',
};

/**
 * Escritorio: el Chrome de `devices`, con la marca de la suite en el user-agent.
 *
 * El qlog de producción es la voz del cliente: estas visitas se marcan para poder
 * separarlas. La marca iba en el `use` de arriba, pero el `devices['Desktop
 * Chrome']` de cada proyecto trae su propio user-agent y la pisaba: el
 * escritorio llegaba como un Chrome de Windows cualquiera, al espejo y a
 * producción (medido en el log del espejo el 2026-09-26). Va en el proyecto.
 */
const ESCRITORIO = {
	...devices[ 'Desktop Chrome' ],
	userAgent: `${ devices[ 'Desktop Chrome' ].userAgent } AkibaraQA/e2e`,
	viewport: { width: 1440, height: 900 },
	launchOptions: { executablePath: chromium },
};

export default defineConfig( {
	testDir: './specs',
	// La misma guardia, como segunda red, sobre la lista de reporters ya resuelta (ver el fichero).
	globalSetup: './specs/soporte/destino.mjs',
	// Un fallo intermitente en una tienda con caché es una señal, no ruido:
	// no se reintenta para no esconderlo.
	retries: 0,
	workers: process.env.CI ? 2 : 4,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	reporter: [
		[ 'list' ],
		// La misma carpeta abre `npm run report` (package.json; lo vigila instrumento.spec.mjs).
		...( enSeco ? [] : [ [ 'html', { outputFolder: '../../output/playwright/e2e-report', open: 'never' } ] ] ),
		// En sólo lectura, el tope de cargas y el detector de escrituras. El tope lo lee
		// el reporter de `AKB_TOPE_CARGAS` (120 sin ella; 0, sin tope, sólo con permiso
		// del dueño). `--reporter` reemplaza esta lista: `soporte/destino.mjs` no deja
		// correr si la que queda no lo trae.
		...( soloLectura ? [ [ './specs/soporte/presupuesto-de-cargas.mjs' ] ] : [] ),
	],
	use: {
		baseURL,
		screenshot: 'only-on-failure',
		// Sin trace (RZN3F-1 / REV3-ZN-1, 2026-09-25): en esta máquina, con Node 26 y
		// Playwright 1.56.1, juntar las trazas de una prueba que falla (`mergeTraceFiles`)
		// se cuelga en cuanto una entrada del zip pasa de ~64 KB —una captura, el HTML
		// de una página—: el yauzl 3.2.0 que trae Playwright contra los streams de
		// Node ≥ 24.16 (yauzl #169). Con 'retain-on-failure', CADA fallo real de
		// cualquier spec sumaba un «Test timeout of 60000ms exceeded.» que no era
		// suyo, 60 s de espera y un trace.zip roto; y toda opción que grabe pasa por
		// esa fusión. La captura y el error-context.md siguen diciendo dónde falló.
		// Se puede volver a encender con Playwright ≥ 1.60 (microsoft/playwright#40747)
		// o con Node 22; `--trace on` desde la CLI también se cuelga.
		trace: 'off',
		video: 'off',
	},
	projects: [
		{
			name: 'escritorio',
			testIgnore: SIEMBRA,
			use: ESCRITORIO,
		},
		{
			name: 'movil',
			testIgnore: [ SIEMBRA, SOLO_UNA_VEZ ],
			use: { ...devices[ 'Desktop Chrome' ], ...MOVIL, launchOptions: { executablePath: chromium } },
		},
		{
			// Lo que SIEMBRA datos del sitio va aparte, de a uno y AL FINAL.
			//
			// Renombrar «A pedido» es una option GLOBAL: mientras está puesta,
			// cualquier otra prueba que mire un badge lee la palabra sembrada y
			// falla sin que nada esté roto. Medido el 2026-09-18: tres pruebas
			// en rojo por esto, y las tres verdes al correrlas solas — el peor
			// tipo de fallo, el que hace desconfiar de la suite entera.
			name: 'siembra',
			testMatch: SIEMBRA,
			dependencies: [ 'escritorio', 'movil' ],
			fullyParallel: false,
			use: ESCRITORIO,
		},
	],
} );
