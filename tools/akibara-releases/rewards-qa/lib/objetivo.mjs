/**
 * objetivo.mjs — argumentos de las sondas de Playwright, validados antes de
 * gastar un navegador.
 *
 * El 2026-09-11 una variable sin dividir en zsh (`set -- $spec` no parte por
 * espacios) le pasó a `qa-barras-fijas.mjs` una sola cadena «https://… 375 812»
 * como URL. La sonda la cargó igual (el navegador codificó los espacios), midió
 * 16 controles de una página de error y reportó números que parecían de verdad.
 * Desde entonces:
 *   - la URL debe ser http(s) y no traer espacios;
 *   - ancho y alto deben ser enteros entre 200 y 5000;
 *   - la navegación debe responder 2xx; si no, la sonda sale con 2 y no mide.
 *
 * Un error de uso sale con 2, nunca con 1: el 1 es «medí y está mal».
 */

export class ErrorDeUso extends Error {}

/**
 * Lee `<url> [ancho] [alto] [...resto]` de `process.argv`.
 *
 * @returns {{ url: string, ancho: number, alto: number, resto: string[] }}
 */
export function leerObjetivo( argv, { anchoPorDefecto = 375, altoPorDefecto = 812 } = {} ) {
	const [ , , url, anchoArg, altoArg, ...resto ] = argv;

	if ( ! url ) {
		throw new ErrorDeUso( 'Falta la URL.' );
	}
	if ( /\s/.test( url ) ) {
		throw new ErrorDeUso( 'La URL trae espacios («' + url + '»): casi seguro una variable sin dividir (en zsh, ${=var} o un bucle while read).' );
	}

	let objetivo;
	try {
		objetivo = new URL( url );
	} catch {
		throw new ErrorDeUso( `URL no válida: «${url}».` );
	}
	if ( objetivo.protocol !== 'http:' && objetivo.protocol !== 'https:' ) {
		throw new ErrorDeUso( `La URL debe ser http(s), no «${objetivo.protocol}».` );
	}

	return {
		url: objetivo.href,
		ancho: entero( anchoArg, 'ancho', anchoPorDefecto ),
		alto: entero( altoArg, 'alto', altoPorDefecto ),
		resto,
	};
}

function entero( valor, nombre, porDefecto ) {
	if ( valor === undefined ) {
		return porDefecto;
	}
	const n = Number( valor );
	if ( ! Number.isInteger( n ) || n < 200 || n > 5000 ) {
		throw new ErrorDeUso( `${nombre} debe ser un entero entre 200 y 5000, no «${valor}».` );
	}
	return n;
}

/** Lee `--bandera=0.6` de los argumentos restantes; entre 0 y 1. */
export function leerFraccion( resto, bandera, porDefecto ) {
	const cruda = resto.find( ( a ) => a.startsWith( `${bandera}=` ) );
	if ( cruda === undefined ) {
		return porDefecto;
	}
	const n = Number( cruda.slice( bandera.length + 1 ) );
	if ( ! Number.isFinite( n ) || n < 0 || n > 1 ) {
		throw new ErrorDeUso( `${bandera} debe estar entre 0 y 1, no «${cruda}».` );
	}
	return n;
}

/** Navega y exige un 2xx: una página de error no se mide. */
export async function cargar( pagina, url ) {
	let respuesta;
	try {
		respuesta = await pagina.goto( url, { waitUntil: 'domcontentloaded' } );
	} catch ( error ) {
		throw new ErrorDeUso( `No se pudo cargar ${url}: ${error.message}` );
	}
	if ( ! respuesta ) {
		throw new ErrorDeUso( `No se pudo cargar ${url}: sin respuesta HTTP.` );
	}
	if ( ! respuesta.ok() ) {
		throw new ErrorDeUso( `${url} respondió ${respuesta.status()}: no hay página que medir.` );
	}
	return respuesta;
}

/** Imprime el error de uso con la línea de uso y sale con 2. */
export function salirPorUso( error, uso ) {
	console.error( `${error.message}\n${uso}` );
	process.exit( 2 );
}
