/**
 * Candados del sistema de diseño (lote T6, 2026-09-23).
 *
 * Cada prueba fija una decisión que antes vivía repetida —y contradicha— en
 * varias hojas. Todas fallan contra el tema 2.1.142 (producción del 2026-09-22)
 * y pasan con el cambio. Leen el CSS FUENTE sin comentarios: el `.min` servido
 * lo vigila min-assets.test.js.
 */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );
const cssDir = path.join( themeRoot, 'assets/css' );
const read = ( rel ) => fs.readFileSync( path.join( themeRoot, rel ), 'utf8' );
const sinComentarios = ( css ) => css.replace( /\/\*[\s\S]*?\*\//g, '' );

/** Hojas fuente del tema (sin las generadas: core.css y los .min). */
const FUENTES = fs.readdirSync( cssDir )
	.filter( ( f ) => f.endsWith( '.css' ) && ! f.endsWith( '.min.css' ) && 'core.css' !== f )
	.sort();
const css = Object.fromEntries( FUENTES.map( ( f ) => [ f, sinComentarios( read( `assets/css/${ f }` ) ) ] ) );

const theme = JSON.parse( read( 'theme.json' ) );
/** Hoja de un plugin hermano, sin comentarios, o null si no está en el árbol. */
const leerPlugin = ( rel ) => {
	const ruta = path.join( themeRoot, '../../plugins', rel );
	return fs.existsSync( ruta ) ? sinComentarios( fs.readFileSync( ruta, 'utf8' ) ) : null;
};
const paleta = Object.fromEntries( theme.settings.color.palette.map( ( p ) => [ p.slug, p.color.toLowerCase() ] ) );
const tokens = css[ 'tokens.css' ];
const definidos = new Map( [ ...tokens.matchAll( /(--aki-[\w-]+)\s*:\s*([^;]+);/g ) ].map( ( m ) => [ m[ 1 ], m[ 2 ].trim() ] ) );

/**
 * Reglas de una hoja: [{ selector, cuerpo, media }]. Suficiente para el CSS del
 * tema (respeta cadenas y paréntesis; los @media se aplanan con su preludio).
 */
function reglas( hoja ) {
	const out = [];
	const recorrer = ( s, media ) => {
		let i = 0;
		while ( i < s.length ) {
			const abre = s.indexOf( '{', i );
			if ( abre < 0 ) {
				break;
			}
			const preludio = s.slice( i, abre ).trim();
			let prof = 1;
			let k = abre + 1;
			let comilla = null;
			while ( k < s.length && prof > 0 ) {
				const c = s[ k ];
				if ( comilla ) {
					if ( c === comilla ) {
						comilla = null;
					}
				} else if ( '"' === c || "'" === c ) {
					comilla = c;
				} else if ( '{' === c ) {
					prof++;
				} else if ( '}' === c ) {
					prof--;
				}
				k++;
			}
			const cuerpo = s.slice( abre + 1, k - 1 );
			if ( /^@(media|supports)/.test( preludio ) ) {
				recorrer( cuerpo, preludio );
			} else if ( ! preludio.startsWith( '@' ) ) {
				out.push( { selector: preludio.replace( /\s+/g, ' ' ), cuerpo, media } );
			}
			i = k;
		}
	};
	recorrer( hoja, '' );
	return out;
}

/** Selectores de una lista, partiendo sólo por las comas de primer nivel (`:is(a, b)` es uno). */
function selectores( lista ) {
	const out = [];
	let prof = 0;
	let desde = 0;
	for ( let i = 0; i < lista.length; i++ ) {
		const c = lista[ i ];
		if ( '(' === c ) {
			prof++;
		} else if ( ')' === c ) {
			prof--;
		} else if ( ',' === c && 0 === prof ) {
			out.push( lista.slice( desde, i ).trim() );
			desde = i + 1;
		}
	}
	out.push( lista.slice( desde ).trim() );
	return out;
}

const luminancia = ( hex ) => {
	const c = [ 1, 3, 5 ].map( ( i ) => parseInt( hex.slice( i, i + 2 ), 16 ) / 255 )
		.map( ( v ) => ( v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4 ) );
	return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
};
const contraste = ( a, b ) => {
	const [ x, y ] = [ luminancia( a ), luminancia( b ) ].sort( ( p, q ) => q - p );
	return ( x + 0.05 ) / ( y + 0.05 );
};
const mezclaConBlanco = ( hex, pct ) => '#' + [ 1, 3, 5 ].map( ( i ) => Math.round( parseInt( hex.slice( i, i + 2 ), 16 ) * pct + 255 * ( 1 - pct ) ).toString( 16 ).padStart( 2, '0' ) ).join( '' );

// ─── DS-6 ────────────────────────────────────────────────────────────────

test( 'DS-6: ninguna hoja del tema usa un token --aki-* que no existe', () => {
	const fantasmas = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		const locales = new Set( [ ...hoja.matchAll( /(--aki-[\w-]+)\s*:/g ) ].map( ( m ) => m[ 1 ] ) );
		for ( const m of hoja.matchAll( /var\((--aki-[\w-]+)/g ) ) {
			if ( ! definidos.has( m[ 1 ] ) && ! locales.has( m[ 1 ] ) ) {
				fantasmas.push( `${ f }: ${ m[ 1 ] }` );
			}
		}
	}
	// Eran --aki-focus (loop.css) y --aki-amber (woocommerce.css): caían siempre
	// a su respaldo y --aki-amber pintaba «Quedan N unidades» fuera de la paleta.
	assert.deepEqual( [ ...new Set( fantasmas ) ], [] );
} );

test( 'DS-6: las hojas que cargan tras core.css no llevan respaldos var(--aki-*, …)', () => {
	// Todas dependen de akibara-base (core.css, donde viven los tokens): el
	// respaldo nunca se pinta y el 39 % contradecía al token (#000 para un
	// --aki-black que vale #121212, #e8222b para el rojo #E60012…). critical.css
	// sí puede pintarse antes que core.css: ahí el respaldo es legítimo.
	const conRespaldo = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		if ( 'critical.css' === f || 'tokens.css' === f ) {
			continue;
		}
		for ( const m of hoja.matchAll( /var\((--aki-[\w-]+)\s*,/g ) ) {
			conRespaldo.push( `${ f }: ${ m[ 1 ] }` );
		}
	}
	assert.deepEqual( conRespaldo, [] );
} );

// ─── DS-12 ───────────────────────────────────────────────────────────────

test( 'DS-12: tokens.css no define colores hex: todo sale de la paleta de theme.json', () => {
	const hex = [ ...tokens.matchAll( /(--[\w-]+)\s*:\s*[^;]*#[0-9a-f]{3,8}\b/gi ) ].map( ( m ) => m[ 1 ] );
	assert.deepEqual( hex, [] );
	assert.equal( paleta[ 'primary-text' ], '#ff4756' );
	assert.equal( paleta[ 'input-border' ], '#707070' );
	assert.match( tokens, /--aki-red-text:\s*var\(--wp--preset--color--primary-text\)/ );
	assert.match( tokens, /--aki-input-border:\s*var\(--wp--preset--color--input-border\)/ );
} );

// ─── DS-5 ────────────────────────────────────────────────────────────────

test( 'DS-5: el foco de la casa es BLANCO editorial brutalista, en anillo o en outline', () => {
	assert.match( theme.settings.custom.focus.outline, /var\(--wp--preset--color--white\)/ );
	assert.match( theme.settings.custom[ 'focus-ring' ], /var\(--wp--preset--color--white\)/ );
	assert.match( tokens, /--aki-focus-ring:\s*var\(--wp--custom--focus-ring\)/ );
	assert.match( tokens, /--aki-focus-outline:\s*var\(--wp--custom--focus--outline\)/ );
	// --aki-focus-shadow se retiró (lote X1) cuando su último consumidor
	// (account-collection.css de business) pasó al outline de la casa: un token
	// sin nadie que lo lea es código muerto, y uno que vuelve a aparecer suma un
	// segundo indicador de foco (la sombra desplazada) sobre el de la casa.
	assert.doesNotMatch( tokens.replace( /\/\*[\s\S]*?\*\//g, '' ), /--aki-focus-shadow\s*:/, 'tokens.css vuelve a definir --aki-focus-shadow' );
	const wpContent = path.join( __dirname, '..', '..', '..' );
	const hojasCss = ( dir ) => ( fs.existsSync( dir ) ? fs.readdirSync( dir, { recursive: true } ) : [] )
		.map( String ).filter( ( f ) => f.endsWith( '.css' ) ).map( ( f ) => path.join( dir, f ) );
	for ( const hoja of [
		...hojasCss( path.join( wpContent, 'themes/akibara-gp/assets/css' ) ),
		...[ 'akibara-business', 'akibara-search', 'akibara-rewards', 'akibara-cart-checkout-blocks' ]
			.flatMap( ( p ) => hojasCss( path.join( wpContent, 'plugins', p, 'assets' ) ) ),
	] ) {
		assert.doesNotMatch( fs.readFileSync( hoja, 'utf8' ), /var\(\s*--aki-focus-shadow/, `${ path.relative( wpContent, hoja ) } consume --aki-focus-shadow, que ya no existe` );
	}
} );

test( 'DS-5: ninguna regla de foco pinta un indicador rojo o blanco', () => {
	const rojos = /var\(--aki-red[\w-]*\)|#e60012|#ff0a1d|#cc0010|rgba\(\s*(230|217)\s*,\s*0\s*,/i;
	const malos = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		for ( const r of reglas( hoja ) ) {
			if ( ! /:focus/.test( r.selector ) ) {
				continue;
			}
			for ( const decl of r.cuerpo.split( ';' ) ) {
				const [ prop, ...resto ] = decl.split( ':' );
				const p = ( prop || '' ).trim();
				const v = resto.join( ':' ).trim();
				if ( /^outline(-color)?$/.test( p ) && v && ! /^none/.test( v ) ) {
					// Sólo el token o el color del sistema en alto contraste.
					if ( ! /var\(--aki-focus-outline\)|Highlight/.test( v ) ) {
						malos.push( `${ f }: ${ r.selector } → ${ p }: ${ v }` );
					}
				}
				if ( 'box-shadow' === p ) {
					// Las partes `inset` son decoración (la franja roja del buscador).
					const exteriores = v.split( /,(?![^(]*\))/ ).filter( ( s ) => ! /inset/.test( s ) ).join( ',' );
					if ( rojos.test( exteriores ) ) {
						malos.push( `${ f }: ${ r.selector } → box-shadow: ${ v }` );
					}
				}
			}
		}
	}
	assert.deepEqual( malos, [] );
} );

test( 'DS-5: una regla de foco con sombra usa el anillo de la casa (o el control lleva el outline de la casa)', () => {
	// T6-R3: la paginación enfocaba con la firma del hover —bloque rojo y sombra
	// #121212 4px— que tapaba el anillo global de `a:focus-visible`. La prueba de
	// arriba no lo veía porque la sombra no era roja. Aquí manda la forma: el foco
	// de la casa es el anillo (--aki-focus-ring) o, en controles recortados con
	// clip-path, el outline (--aki-focus-outline) en una regla del mismo selector.
	// El realce de un CONTENEDOR (`li:has(:focus-visible)`) no es el indicador: lo es el del control.
	const esFoco = ( sel ) => /:focus(-visible)?(?![-\w])/.test( sel.replace( /:(not|has)\((?:[^()]|\([^()]*\))*\)/g, '' ) );
	const conOutline = new Set();
	for ( const hoja of Object.values( css ) ) {
		for ( const r of reglas( hoja ) ) {
			if ( /outline:\s*var\(--aki-focus-outline\)/.test( r.cuerpo ) ) {
				selectores( r.selector ).forEach( ( x ) => conOutline.add( x ) );
			}
		}
	}
	const malos = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		for ( const r of reglas( hoja ) ) {
			const sombra = r.cuerpo.match( /(?:^|;)\s*box-shadow:\s*([^;]+)/ );
			if ( ! sombra || /^none/.test( sombra[ 1 ].trim() ) || /var\(--aki-focus-ring\)/.test( sombra[ 1 ] ) ) {
				continue;
			}
			// Las partes `inset` son decoración (la franja roja del buscador).
			if ( sombra[ 1 ].split( /,(?![^(]*\))/ ).every( ( x ) => /inset/.test( x ) ) ) {
				continue;
			}
			for ( const sel of selectores( r.selector ).filter( esFoco ) ) {
				if ( ! conOutline.has( sel ) ) {
					malos.push( `${ f }: ${ sel } → box-shadow: ${ sombra[ 1 ].trim() }` );
				}
			}
		}
	}
	assert.deepEqual( malos, [] );
} );

test( 'DS-5: la paginación separa el hover del foco de teclado (T6-R3)', () => {
	const pag = reglas( css[ 'woocommerce.css' ] ).filter( ( r ) => /woocommerce-pagination ul li a:/.test( r.selector ) );
	const hover = pag.find( ( r ) => /a:hover$/.test( r.selector ) );
	const foco = pag.find( ( r ) => /a:focus-visible$/.test( r.selector ) );
	assert.ok( hover && foco, 'faltan las reglas de hover o de foco de la paginación' );
	assert.doesNotMatch( hover.selector, /:focus/, 'el hover no puede ser también la regla de foco' );
	assert.match( foco.cuerpo, /box-shadow:\s*var\(--aki-focus-ring\)/ );
	assert.ok( pag.indexOf( foco ) > pag.indexOf( hover ), 'el anillo va después del hover: con los dos a la vez, manda el anillo' );
	// `:focus` a secas sólo neutraliza el gris/morado de WooCommerce (0,3,4).
	const neutro = pag.find( ( r ) => /a:focus$/.test( r.selector ) );
	assert.ok( neutro );
	assert.doesNotMatch( neutro.cuerpo, /box-shadow|--aki-red/ );
} );

test( 'DS-5 (REV-T6-2-1): en colores forzados todo control enfocado conserva un contorno', () => {
	// El anillo es un box-shadow y los colores forzados anulan box-shadow: sin este
	// respaldo, 84 de 91 paradas de Tab quedaban sin indicador (cabecera, menú, pie,
	// barra inferior, sellos, barra de compra).
	const respaldo = reglas( tokens ).filter( ( r ) => /forced-colors:\s*active/.test( r.media ) && /^:focus-visible(:where\(:not\(#main\)\))?$/.test( r.selector ) );
	assert.equal( respaldo.length, 1, 'falta el contorno de respaldo global en tokens.css' );
	assert.match( respaldo[ 0 ].cuerpo, /outline:\s*2px solid Highlight !important/ );
	// REV-T6-3-2: salvo #main, el destino del «Saltar al contenido» (base.css le
	// quita el recuadro a propósito: sería del tamaño del contenido). Con `:where()`
	// la excepción no suma especificidad: un `#main` suelto dentro de `:not()` la
	// subía a (1,1,0) y le ganaba el `outline-offset` a campos y pestañas.
	assert.equal( respaldo[ 0 ].selector, ':focus-visible:where(:not(#main))', 'el salto al contenido no se enmarca en colores forzados' );
	assert.match( sinComentarios( read( 'assets/css/base.css' ) ), /#main:focus\s*\{\s*outline:\s*none;/, 'la decisión que respeta vive en base.css' );
	// Y nada le gana: el respaldo es !important y sin especificidad, así que sólo lo
	// tapa otro `outline: none !important` que pueda casar con el foco de teclado.
	// Vale excluir el teclado (`:not(:focus-visible)`), vivir sólo fuera de colores
	// forzados, o devolver el contorno en colores forzados en la misma hoja, después.
	const malos = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		const rs = reglas( hoja );
		rs.forEach( ( r, i ) => {
			if ( /forced-colors:\s*none/.test( r.media ) || ! /outline(-style|-width)?:\s*(none|0)[^;]*!important/.test( r.cuerpo ) ) {
				return;
			}
			for ( const sel of selectores( r.selector ) ) {
				if ( /:not\(:focus-visible\)/.test( sel ) ) {
					continue;
				}
				const devuelto = rs.slice( i + 1 ).some( ( q ) => /forced-colors:\s*active/.test( q.media ) && selectores( q.selector ).includes( sel ) && /outline:[^;]*Highlight[^;]*!important/.test( q.cuerpo ) );
				if ( ! devuelto ) {
					malos.push( `${ f }: ${ sel }` );
				}
			}
		} );
	}
	assert.deepEqual( malos, [] );
} );

test( 'DS-5 (REV-T6-2-2): con el puntero encima, el foco de teclado sigue a la vista', () => {
	// (1) Un `:hover`/`:active` que apaga sombra u outline con !important le gana a
	// cualquier foco: debe excluir el teclado o dejar que una regla POSTERIOR del
	// mismo control devuelva el foco de la casa con !important (#place_order).
	const devuelveFoco = /(box-shadow:\s*var\(--aki-focus-ring\)|outline:\s*var\(--aki-focus-outline\))\s*!important/;
	const malos = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		const rs = reglas( hoja );
		rs.forEach( ( r, i ) => {
			if ( ! /(box-shadow|outline)\s*:\s*(none|0)\s*!important/.test( r.cuerpo ) ) {
				return;
			}
			for ( const sel of selectores( r.selector ) ) {
				if ( ! /:(hover|active)/.test( sel ) || /:not\(:focus-visible\)/.test( sel ) ) {
					continue;
				}
				const foco = sel.replace( /:(hover|active)/, ':focus-visible' );
				if ( ! rs.slice( i + 1 ).some( ( q ) => selectores( q.selector ).includes( foco ) && devuelveFoco.test( q.cuerpo ) ) ) {
					malos.push( `${ f }: ${ sel }` );
				}
			}
		} );
	}
	assert.deepEqual( malos, [] );
	// (2) Los controles cuyo foco es el anillo GLOBAL (tokens.css, `.x:focus-visible`)
	// no pueden declarar sombra ni outline en su `:hover`: las demás hojas van
	// después y, a la misma especificidad, el hover le gana al anillo (el menú de
	// escritorio: 524 px amarillos antes de T6, 0 después).
	const global = reglas( tokens ).find( ( r ) => '' === r.media && /box-shadow:\s*var\(--aki-focus-ring\)/.test( r.cuerpo ) && /a:focus-visible/.test( r.selector ) );
	assert.ok( global, 'falta la regla global del anillo en tokens.css' );
	const clases = selectores( global.selector ).filter( ( s ) => /^\.[\w-]+:focus-visible$/.test( s ) ).map( ( s ) => s.replace( ':focus-visible', '' ) );
	assert.ok( clases.includes( '.mainnav__link' ) );
	const pisan = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		if ( 'tokens.css' === f ) {
			continue;
		}
		for ( const r of reglas( hoja ) ) {
			if ( '' !== r.media || ! /(^|;)\s*(box-shadow|outline)\s*:/.test( r.cuerpo ) ) {
				continue;
			}
			for ( const c of clases ) {
				if ( selectores( r.selector ).includes( `${ c }:hover` ) ) {
					pisan.push( `${ f }: ${ c }:hover` );
				}
			}
		}
	}
	assert.deepEqual( pisan, [] );
} );

test( 'colores forzados: las líneas de la hamburguesa no desaparecen', () => {
	// Son fondos: en alto contraste el navegador los reemplazaba y el botón del
	// menú móvil quedaba vacío (medido con Chromium forcedColors, 2026-09-23).
	const r = reglas( css[ 'header.css' ] ).find( ( x ) => /forced-colors:\s*active/.test( x.media ) && '.hamburger span' === x.selector );
	assert.ok( r, 'falta la regla de colores forzados de las líneas' );
	assert.match( r.cuerpo, /forced-color-adjust:\s*none/ );
	assert.match( r.cuerpo, /background:\s*ButtonText/ );
} );

test( 'DS-5: los campos de texto enfocados llevan el anillo, no la sombra roja', () => {
	const base = reglas( css[ 'base.css' ] ).find( ( r ) => /input\[type="text"\]:focus/.test( r.selector ) );
	assert.ok( base, 'falta la regla de foco de los campos en base.css' );
	assert.match( base.cuerpo, /box-shadow:\s*var\(--aki-focus-ring\)/ );
	assert.doesNotMatch( base.cuerpo, /--aki-red/ );
} );

// ─── DS-14 ───────────────────────────────────────────────────────────────

test( 'DS-14: las capas globales salen de la escala de theme.json, no de literales', () => {
	const z = theme.settings.custom.z;
	assert.ok( +z[ 'skip-link' ] > +z.cart && +z.cart > +z.progress && +z.progress > +z.chat && +z.chat > +z.modal );
	// Cada capa de la escala llega a CSS como --z-*: una capa sin variable es un
	// token que no lee nadie. `cart` se retiró en T6-R5 porque nadie la leía (el
	// carrito lateral de akibara-business calculaba --z-modal + 10000) y volvió
	// en el lote de integración con su lector en el MISMO cambio: native-cart-v2.css
	// la consume, y esta prueba lo exige. Que el enlace quede encima del carrito
	// lo sigue midiendo el e2e con el carrito abierto.
	const carrito = leerPlugin( 'akibara-business/assets/css/native-cart-v2.css' );
	if ( null !== carrito ) {
		assert.match( carrito, /--akb-cart-z:\s*var\(--z-cart,\s*10400\)/, 'el carrito lateral lee la capa del tema' );
		assert.equal( String( z.cart ), '10400', 'el respaldo del plugin es el valor del token' );
	}
	for ( const capa of Object.keys( z ) ) {
		assert.match( tokens, new RegExp( `--z-${ capa }:\\s*var\\(--wp--custom--z--${ capa }\\)` ), `capa «${ capa }» sin variable --z-${ capa }` );
	}
	const literales = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		for ( const m of hoja.matchAll( /z-index:\s*(-?\d+)/g ) ) {
			// Apilamientos locales dentro de un componente (-1…99) sí son literales.
			if ( Math.abs( +m[ 1 ] ) >= 100 ) {
				literales.push( `${ f }: ${ m[ 0 ] }` );
			}
		}
		for ( const m of hoja.matchAll( /var\(--z-[\w-]+,\s*(\d+)\)/g ) ) {
			const nombre = m[ 0 ].match( /--z-([\w-]+)/ )[ 1 ];
			assert.equal( m[ 1 ], String( z[ nombre ] ), `${ f }: respaldo de --z-${ nombre } distinto del token` );
		}
	}
	assert.deepEqual( literales, [] );
	assert.match( css[ 'footer.css' ], /\.skip-link\s*\{[^}]*z-index:\s*var\(--z-skip-link\)/ );
} );

// ─── DS-13 ───────────────────────────────────────────────────────────────

/**
 * Selectores de una hoja que a 768 px exactos reciben a la vez una regla de
 * `max-width: 768px` y otra de `min-width: 768px`.
 */
function solapes768( hoja ) {
	const rs = reglas( hoja );
	const movil = new Set( rs.filter( ( r ) => /max-width:\s*768px/.test( r.media ) ).flatMap( ( r ) => selectores( r.selector ) ) );
	return rs.filter( ( r ) => /min-width:\s*768px/.test( r.media ) )
		.flatMap( ( r ) => selectores( r.selector ).filter( ( x ) => movil.has( x ) ).map( ( x ) => `${ r.media } → ${ x }` ) );
}

test( 'DS-13: ningún min-width de 768 solapa una regla de móvil (≤768) del MISMO selector', () => {
	// A 768 px valen los dos rangos, pero sólo es un conflicto si las dos reglas
	// apuntan al mismo selector. Un `min-width: 768px` sobre algo que el rango móvil
	// no toca es un corte de tablet: prohibirlo sin mirar el selector movió el
	// resumen del pedido a 769 y el iPad vertical perdió su rejilla (REV-T6-2-3).
	const solapes = Object.entries( css ).flatMap( ( [ f, hoja ] ) => solapes768( hoja ).map( ( s ) => `${ f }: ${ s }` ) );
	assert.deepEqual( solapes, [] );
	// El detector sí ve un solape de verdad.
	assert.deepEqual(
		solapes768( '@media (max-width: 768px) { .a, .b { x: 1; } } @media (min-width: 768px) { .b { x: 2; } .c { x: 3; } }' ),
		[ '@media (min-width: 768px) → .b' ]
	);
} );

test( 'REV-T6-2-3: el resumen del pedido pasa a rejilla desde 768 px (iPad vertical)', () => {
	const r = reglas( css[ 'woocommerce.css' ] ).filter( ( x ) => '.woocommerce-order ul.woocommerce-order-overview' === x.selector && /grid-template-columns:\s*repeat\(auto-fit/.test( x.cuerpo ) );
	assert.equal( r.length, 1 );
	assert.equal( r[ 0 ].media, '@media (min-width: 768px)' );
	// El clearfix de WooCommerce no puede ocupar la primera celda de la rejilla.
	const clearfix = reglas( css[ 'woocommerce.css' ] ).find( ( x ) => '' === x.media && selectores( x.selector ).includes( '.woocommerce-order ul.woocommerce-order-overview::before' ) );
	assert.ok( clearfix, 'falta anular el ::before del clearfix de WooCommerce en el resumen' );
	assert.ok( selectores( clearfix.selector ).includes( '.woocommerce-order ul.woocommerce-order-overview::after' ) );
	assert.match( clearfix.cuerpo, /content:\s*none/ );
	// Y la última tarjeta conserva su marco (WooCommerce se lo quita con
	// `li:last-of-type`), antes de la regla del total para que su amarillo gane.
	const rs = reglas( css[ 'woocommerce.css' ] );
	const ultima = rs.findIndex( ( x ) => '.woocommerce-order ul.woocommerce-order-overview > li:last-of-type' === x.selector );
	const total = rs.findIndex( ( x ) => '.woocommerce-order ul.woocommerce-order-overview > li.total' === x.selector );
	assert.ok( ultima >= 0 && total > ultima, 'el marco de la última tarjeta va antes del amarillo del total' );
	assert.match( rs[ ultima ].cuerpo, /border:\s*1px solid var\(--aki-border\)/ );
} );

// ─── DS-4 ────────────────────────────────────────────────────────────────

test( 'DS-4: la tarjeta de producto se define en UNA hoja y su sombra se ve', () => {
	// Nadie más declara la tarjeta clásica.
	for ( const f of [ 'base.css', 'woocommerce.css' ] ) {
		const intrusas = reglas( css[ f ] ).filter( ( r ) => /(^|,\s*)(\.woocommerce\s+)?ul\.products li\.product(:hover)?\s*($|,)/.test( r.selector ) );
		assert.deepEqual( intrusas.map( ( r ) => r.selector ), [], `${ f } vuelve a declarar la tarjeta` );
	}
	const loop = reglas( css[ 'loop.css' ] );
	const salto = loop.filter( ( r ) => /\.woocommerce ul\.products li\.product:hover/.test( r.selector ) && /transform:\s*translate\(-6px,\s*-6px\)/.test( r.cuerpo ) );
	assert.equal( salto.length, 1 );
	assert.match( salto[ 0 ].media, /hover:\s*hover/, 'el salto sólo con puntero real' );
	assert.doesNotMatch( salto[ 0 ].selector, /body\.woocommerce/, 'en la portada el body no lleva .woocommerce' );
	assert.match( salto[ 0 ].cuerpo, /box-shadow:\s*var\(--shadow-glow-red\)/ );
	// T6-R2: con teclado, el mismo salto por `:has(:focus-visible)`, en cualquier
	// dispositivo; y NADA de la tarjeta cuelga de `:focus-within`, que también casa
	// tras un clic y dejaba la tarjeta levantada con el puntero lejos.
	const teclado = loop.filter( ( r ) => '.woocommerce ul.products li.product:has(:focus-visible)' === r.selector );
	assert.equal( teclado.length, 1 );
	assert.equal( teclado[ 0 ].media, '', 'el foco de teclado no depende de tener ratón' );
	assert.match( teclado[ 0 ].cuerpo, /transform:\s*translate\(-6px,\s*-6px\)/ );
	assert.match( teclado[ 0 ].cuerpo, /box-shadow:\s*var\(--shadow-glow-red\)/ );
	assert.doesNotMatch( css[ 'loop.css' ], /li\.product:focus-within/ );
	// `:has()` no es tolerante: en una lista con coma, un navegador que no lo
	// entienda tiraría también el `:hover`. Junto al hover, sólo dentro de `:is()`.
	for ( const r of loop ) {
		if ( /:has\(:focus-visible\)/.test( r.selector ) && /:hover/.test( r.selector ) ) {
			for ( const x of selectores( r.selector ) ) {
				assert.ok( ! /:has\(:focus-visible\)/.test( x ) || /:is\(:hover, :has\(:focus-visible\)\)/.test( x ), `${ x }: :has() suelto junto a :hover` );
			}
		}
	}
	// La tinta de la sombra NO puede ser el color del fondo (#121212).
	const glow = theme.settings.custom.shadow[ 'glow-red' ];
	assert.doesNotMatch( glow, /18,\s*18,\s*18|#121212/i );
	assert.notEqual( paleta.black, glow );
} );

// ─── DS-2 ────────────────────────────────────────────────────────────────

test( 'DS-2: un solo botón de compra — mismo rojo y mismo hover en tarjeta, ficha y barra', () => {
	assert.match( tokens, /--aki-buy-bg:\s*var\(--aki-red-readable\)/ );
	assert.match( tokens, /--aki-buy-bg-hover:\s*var\(--aki-red-dark\)/ );
	const wc = css[ 'woocommerce.css' ];
	const loop = css[ 'loop.css' ];
	assert.match( loop, /\.woocommerce ul\.products li\.product \.button \{[^}]*background:\s*var\(--aki-buy-bg\)/ );
	assert.match( wc, /\.akb-sticky-atc__btn \{[^}]*background:\s*var\(--aki-buy-bg\)/ );
	assert.match( wc, /\.single_add_to_cart_button:hover \{[^}]*background:\s*var\(--aki-buy-bg-hover\)/ );
	assert.match( wc, /\.akb-sticky-atc__btn:hover,\s*\.akb-sticky-atc__btn:active \{[^}]*background:\s*var\(--aki-buy-bg-hover\)/ );
	// Sin segundo rojo para la preventa, sin barber-pole, sin degradado.
	assert.doesNotMatch( wc + loop, /:has\(\.akb-badge--preorder\)[^{]*\{[^}]*background/ );
	assert.doesNotMatch( wc, /repeating-linear-gradient\(\s*-45deg/ );
	assert.doesNotMatch( wc, /linear-gradient\(135deg,\s*var\(--aki-red-dark\)/ );
	assert.doesNotMatch( wc, /akb-sticky-atc__btn--preorder/ );
	assert.doesNotMatch( read( 'template-parts/woocommerce/sticky-atc.php' ), /--preorder/ );
	// Los otros tres papeles tienen consumidores (un token sin uso sería código muerto).
	for ( const papel of [ '--aki-btn-secondary-border', '--aki-btn-ghost-border', '--aki-btn-link-color' ] ) {
		const usos = Object.entries( css ).filter( ( [ f, h ] ) => 'tokens.css' !== f && h.includes( `var(${ papel })` ) );
		assert.ok( usos.length > 0, `${ papel } no lo usa nadie` );
	}
	// El estado de la ficha se decide por SU resumen, no por cualquier tarjeta de la página.
	assert.doesNotMatch( wc, /\.woocommerce \.product:has\(/ );
	// En vuelo conserva su rojo porque NUNCA pasa a `:disabled` (QA-ESC-2): va con
	// `aria-disabled`, que WooCommerce no pinta. Con `disabled`, su
	// `button.alt:disabled` lo teñía de morado #7f54b3 y además el foco caía al
	// <body>. La regla que le devolvía el rojo sobre el morado ya no tiene a quién
	// aplicarse: no queda.
	const pdp = read( 'assets/js/pdp-ajax-atc.js' ).replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' );
	assert.doesNotMatch( pdp, /\.disabled\s*=\s*true/ );
	assert.match( pdp, /setAttribute\(\s*'aria-disabled',\s*'true'\s*\)/ );
	assert.doesNotMatch( wc, /single_add_to_cart_button\.button\.alt:disabled/ );
	const enVuelo = reglas( wc ).find( ( r ) => /(^|,\s*)\.single_add_to_cart_button\.is-adding(,|$)/.test( r.selector ) );
	assert.ok( enVuelo, 'falta el estado en vuelo del botón de compra' );
	assert.match( enVuelo.cuerpo, /pointer-events:\s*none/ );
} );

// ─── DS-7 ────────────────────────────────────────────────────────────────

test( 'DS-7: «Quedan N unidades» gana a WooCommerce y sale en el ámbar de la paleta', () => {
	const r = reglas( css[ 'woocommerce.css' ] ).find( ( x ) => /akb-stock-low$/.test( x.selector ) );
	assert.ok( r );
	// WooCommerce: `.woocommerce:where(…) div.product p.stock` = (0,3,2).
	assert.equal( r.selector, '.woocommerce div.product p.stock.akb-stock-low' );
	assert.match( r.cuerpo, /color:\s*var\(--aki-warning\)/ );
	assert.ok( contraste( paleta.warning, paleta.black ) >= 4.5 );
} );

// ─── DS-1 / DS-8 ─────────────────────────────────────────────────────────

test( 'DS-1: los títulos de sección de la ficha tienen papel propio, bajo el H1', () => {
	const r = reglas( css[ 'woocommerce.css' ] ).find( ( x ) => /\.related > h2/.test( x.selector ) );
	assert.ok( r, 'falta la regla de títulos de sección de la ficha' );
	assert.match( r.selector, /\.up-sells > h2/ );
	assert.match( r.selector, /\.section-header__title/ );
	assert.match( r.cuerpo, /font-size:\s*var\(--aki-type-section\)/ );
	// theme.json NO se «corrige» a ciegas (un h2 sin clase pasaría a 56 px).
	assert.equal( theme.styles.elements.h2.typography.fontSize, 'var(--wp--preset--font-size--4xl)' );
} );

test( 'DS-8: H1 de página con un solo papel y el H2 del carrito vacío por debajo', () => {
	const wc = css[ 'woocommerce.css' ];
	assert.match( wc, /\.woocommerce-page h1\.entry-title \{[^}]*font-size:\s*var\(--aki-type-page\)/ );
	assert.match( wc, /body\.woocommerce-account \.entry-header \.entry-title \{[^}]*font-size:\s*var\(--aki-type-page\)/ );
	assert.match( css[ 'loop.css' ], /\.akb-archive-hero__title \{[^}]*font-size:\s*var\(--aki-type-page\)/ );
	assert.match( css[ 'empty-states.css' ], /\.akb-cart-empty \.akb-empty__title \{[^}]*font-size:\s*var\(--aki-type-section\)/ );
	assert.match( tokens, /--aki-type-section:\s*var\(--text-2xl\)/ );
} );

test( 'DS-8: ninguna regla pide negrita a Bebas sin prohibir la síntesis', () => {
	const falsas = [];
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		for ( const r of reglas( hoja ) ) {
			if ( ! /font-family:\s*var\(--font-heading\)/.test( r.cuerpo ) ) {
				continue;
			}
			const peso = r.cuerpo.match( /font-weight:\s*(var\(--weight-(bold|black|semibold)\)|[6-9]00)/ );
			if ( peso && ! /font-synthesis:\s*none/.test( r.cuerpo ) ) {
				falsas.push( `${ f }: ${ r.selector }` );
			}
		}
	}
	assert.deepEqual( falsas, [] );
} );

// ─── DS-9 ────────────────────────────────────────────────────────────────

test( 'DS-9: los estados usan los tonos de la paleta con contraste AA sobre el fondo', () => {
	const fondo = paleta.black;
	const ok = mezclaConBlanco( paleta.success, 0.6 );
	assert.ok( contraste( ok, fondo ) >= 4.5, `ok ${ ok }` );
	assert.ok( contraste( paleta.warning, fondo ) >= 4.5 );
	assert.ok( contraste( paleta[ 'primary-text' ], fondo ) >= 4.5 );
	assert.doesNotMatch( css[ 'woocommerce.css' ], /#f87171|#4ade80|#fbbf24/i );
	assert.match( css[ 'loop.css' ], /\.akb-badge--preorder \{[^}]*var\(--aki-status-preorder\)/ );
} );

// ─── DS-15 / DS-11 ───────────────────────────────────────────────────────

test( 'DS-15: reseñas acotadas a #reviews, sin !important y con el título real', () => {
	const hoja = css[ 'reviews-p5-v4.css' ];
	assert.doesNotMatch( hoja, /!important/ );
	const sueltas = reglas( hoja ).filter( ( r ) => ! /#reviews/.test( r.selector ) );
	assert.deepEqual( sueltas.map( ( r ) => r.selector ), [], 'todo debe colgar de #reviews' );
	assert.doesNotMatch( hoja, /visibility:\s*hidden/, 'el título no se esconde para pintar otro encima' );
	assert.doesNotMatch( hoja, /content:\s*"Voces del Distrito"/ );
	// Un solo bloque por tarjeta (había dos con márgenes contradictorios).
	assert.equal( reglas( hoja ).filter( ( r ) => /li\.review$/.test( r.selector ) ).length, 1 );
} );

test( 'DS-11: trinquete de !important — cada hoja puede bajar, nunca subir', () => {
	// Recuento del 2026-09-23 tras el lote T6 (sin comentarios). Antes: 202 en el
	// tema; reviews-p5-v4.css tenía 39 en 4,5 KB y woocommerce.css 95.
	// REV-T6-2 (2026-09-23): tokens.css sube a 9 por UN motivo, el contorno de
	// respaldo en colores forzados, que tiene que ganar a los `outline: none` de
	// cada :focus-visible local; footer.css baja a 12.
	const TECHO = {
		'base.css': 19,
		'blog.css': 0,
		'brands-home.css': 1,
		'critical.css': 3,
		'empty-states.css': 2,
		'footer.css': 12,
		'header.css': 1,
		'loop.css': 14,
		'reviews-p5-v4.css': 0,
		'tokens.css': 9,
		'trust.css': 1,
		'woocommerce.css': 80,
	};
	for ( const f of FUENTES ) {
		if ( ! ( f in TECHO ) ) {
			assert.doesNotMatch( css[ f ], /!important/, `${ f }: hoja sin techo, no puede traer !important` );
		}
	}
	for ( const [ f, techo ] of Object.entries( TECHO ) ) {
		const n = ( css[ f ].match( /!important/g ) || [] ).length;
		assert.ok( n <= techo, `${ f }: ${ n } !important (techo ${ techo })` );
	}
} );

// ─── UM-6 ────────────────────────────────────────────────────────────────

test( 'UM-6: sólo el sello que es enlace lleva el marco rojo; texto de 12 px', () => {
	const hoja = css[ 'trust.css' ];
	const movil = reglas( hoja ).filter( ( r ) => /max-width:\s*768px/.test( r.media ) );
	const item = movil.find( ( r ) => '.akb-trust-item' === r.selector );
	assert.match( item.cuerpo, /border:\s*2px solid var\(--aki-border\)/ );
	assert.ok( movil.find( ( r ) => 'a.akb-trust-item' === r.selector && /--aki-red/.test( r.cuerpo ) ) );
	for ( const r of reglas( hoja ).filter( ( x ) => /__label (strong|small)/.test( x.selector ) && x.media ) ) {
		const fs = r.cuerpo.match( /font-size:\s*([\d.]+)rem/ );
		if ( fs ) {
			assert.ok( +fs[ 1 ] >= 0.75, `${ r.media } ${ r.selector }: ${ fs[ 1 ] }rem < 12 px` );
		}
	}
	assert.match( hoja, /@media \(max-width: 380px\)\s*\{\s*\.akb-trust-item__icon\s*\{\s*display:\s*none/ );
} );

/*
 * PROD-COMP-2 (verificación de producción del 2026-09-26): UM-6 sólo subió el móvil,
 * y por encima de 768 px el small de los sellos medía 11,48 px (0.82em de un
 * --text-sm de 14 px), en Chromium y WebKit. La geometría servida se mide en
 * tools/akibara-e2e/specs/tema-seguimiento-sellos-y-avisos.spec.mjs.
 */
test( 'PROD-COMP-2: las dos líneas de los sellos miden ≥ 12 px en TODO ancho, también por encima de 768 px', () => {
	const hoja = css[ 'trust.css' ];
	// El peor caso de un `em`: el mínimo del clamp del --text-sm que hereda la tarjeta.
	const sm = theme.settings.typography.fontSizes.find( ( f ) => 'sm' === f.slug ).size;
	const minimoSm = parseFloat( /clamp\(\s*([\d.]+)rem/.exec( sm )[ 1 ] ) * 16;
	assert.match( reglas( hoja ).find( ( r ) => '.akb-trust-item' === r.selector && ! r.media ).cuerpo, /font-size:\s*var\(--text-sm\)/, 'la premisa: la tarjeta hereda el --text-sm' );
	const px = ( valor ) => {
		const m = /^([\d.]+)(rem|em|px)$/.exec( valor.trim() );
		assert.ok( m, `unidad inesperada: ${ valor }` );
		return +m[ 1 ] * ( { rem: 16, em: minimoSm, px: 1 } )[ m[ 2 ] ];
	};
	const general = reglas( hoja ).filter( ( r ) => ! r.media && /^\.akb-trust-item__label (strong|small)$/.test( r.selector ) );
	assert.equal( general.length, 2, 'las dos líneas tienen regla general (sin @media)' );
	for ( const r of reglas( hoja ).filter( ( x ) => /__label (strong|small)$/.test( x.selector ) ) ) {
		const tam = /font-size:\s*([^;]+);/.exec( r.cuerpo );
		if ( tam ) {
			assert.ok( px( tam[ 1 ] ) >= 12, `${ r.media || 'sin @media' } ${ r.selector }: ${ tam[ 1 ] } = ${ px( tam[ 1 ] ).toFixed( 2 ) } px < 12` );
		}
	}
	const small = general.find( ( r ) => / small$/.test( r.selector ) );
	assert.match( small.cuerpo, /font-size:\s*0?\.75rem/, 'en rem: en em sigue al --text-sm, que es fluido' );
} );

/*
 * REV-ST1o-1: con esos 12 px, «1 punto = $1 en tu próxima compra» pasaba a dos
 * líneas entre 1076 y 1109 px (Chromium) y 1090 y 1123 (WebKit), con «compra»
 * sola: la tira crecía de 85 a 104 px y la portada bajaba 18 px. La geometría se
 * mide en tools/akibara-e2e/specs/tema-seguimiento-sellos-y-avisos.spec.mjs.
 */
test( 'REV-ST1o-1: en 1025-1140 px la etiqueta de los sellos gana ancho sin bajar la letra, y donde se parte no deja una palabra sola', () => {
	const r = reglas( css[ 'trust.css' ] );
	// El small va EN LÍNEA en escritorio: `text-wrap` sólo actúa sobre una caja de
	// bloque, así que tiene que ir en la etiqueta, que lo hereda a sus dos líneas.
	const etiqueta = r.find( ( x ) => '.akb-trust-item__label' === x.selector && ! x.media );
	assert.ok( etiqueta, 'la etiqueta tiene regla general' );
	assert.match( etiqueta.cuerpo, /text-wrap:\s*balance;/ );
	assert.ok( ! r.some( ( x ) => / small$/.test( x.selector ) && ! x.media && /display:\s*block/.test( x.cuerpo ) ), 'la premisa: en escritorio el small no es de bloque (y pasarlo a bloque cambia el alto de su línea)' );
	const franja = r.filter( ( x ) => /^@media\s*\(min-width:\s*1025px\)\s*and\s*\(max-width:\s*1140px\)$/.test( x.media ) );
	const cuerpo = ( sel ) => ( franja.find( ( x ) => sel === x.selector ) || {} ).cuerpo || '';
	assert.match( cuerpo( '.akb-trust-item' ), /padding-inline:\s*var\(--space-2\);/, 'un escalón menos de relleno a los lados (12 → 8 px)' );
	assert.match( cuerpo( '.akb-trust-grid' ), /gap:\s*var\(--space-2\);/, 'y del hueco entre tarjetas (12 → 8 px)' );
	assert.ok( franja.every( ( x ) => ! /font-size|letter-spacing/.test( x.cuerpo ) ), 'el ancho no se gana achicando la letra: los 12 px de PROD-COMP-2 se quedan' );
	assert.ok( ! franja.some( ( x ) => /__icon/.test( x.selector ) || /(?:^|;)\s*gap:/.test( cuerpo( '.akb-trust-item' ) ) ), 'el icono y su distancia al texto no cambian' );
} );

/*
 * REV-T6-3-1: en colores forzados las preguntas frecuentes de la ficha no
 * enseñaban foco. El contorno de respaldo (tokens.css) se dibujaba 2 px por
 * FUERA del summary y .product-faq lo recorta con overflow:hidden: 0 píxeles
 * de foco en las 4 preguntas. Fuera de colores forzados el contorno es none y
 * el desplazamiento no pinta nada.
 */
test( 'REV-T6-3-1: el foco de las preguntas frecuentes va hacia dentro (overflow:hidden lo recortaba)', () => {
	const w = reglas( css[ 'woocommerce.css' ] );
	const faq = w.find( ( r ) => '.product-faq' === r.selector );
	assert.match( faq.cuerpo, /overflow:\s*hidden/, 'la premisa: la caja recorta' );
	const foco = w.find( ( r ) => '.product-faq__q:focus-visible' === r.selector );
	assert.ok( foco );
	assert.match( foco.cuerpo, /outline-offset:\s*-2px/ );
	assert.match( foco.cuerpo, /outline:\s*none;/, 'fuera de colores forzados sigue sin contorno: el foco es el anillo interior' );
} );

/*
 * REV-T6-3-3: FiboSearch no está instalado y nada del tema ni de los plugins
 * propios pinta `.dgwt-wcas-search-input` ni `.header-search-input` (medido en
 * el espejo y en producción): eran selectores muertos.
 */
test( 'REV-T6-3-3: sin selectores de buscadores que no existen', () => {
	for ( const [ f, hoja ] of Object.entries( css ) ) {
		assert.doesNotMatch( hoja, /dgwt-wcas|header-search-input/, f );
	}
} );

/*
 * R2-T5-V1: con la flecha centrada en un botón de 32 px, cada flecha quedaba
 * 3 px más lejos de su etiqueta que antes de T5 y el menú entero se corría
 * (3.411 px distintos en la franja de la cabecera a 1440 y a 1366). Con 34 px y
 * la flecha a 8 px del borde vuelve exactamente a las columnas de antes.
 */
test( 'R2-T5-V1: el botón del submenú deja la flecha donde estaba cuando era parte del enlace', () => {
	const h = reglas( css[ 'header.css' ] );
	const boton = h.find( ( r ) => '.mainnav__toggle' === r.selector );
	assert.match( boton.cuerpo, /width:\s*34px/ );
	assert.match( boton.cuerpo, /justify-content:\s*flex-start/ );
	assert.match( boton.cuerpo, /padding:\s*0 0 0 8px/ );
	const enlace = h.find( ( r ) => '.mainnav__item--has-sub > .mainnav__link' === r.selector );
	assert.match( enlace.cuerpo, /padding-right:\s*2px/, '2 + 8 = los 10 px de antes entre el texto y la flecha' );
	const sub = h.find( ( r ) => '.mainnav__toggle--sub' === r.selector );
	assert.match( sub.cuerpo, /justify-content:\s*center/, 'el «›» del tercer nivel sigue centrado' );
	assert.match( sub.cuerpo, /padding:\s*0;/ );
	assert.match( sub.cuerpo, /width:\s*32px/ );
} );
