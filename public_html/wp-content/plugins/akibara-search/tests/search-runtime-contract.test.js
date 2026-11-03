'use strict';

const assert = require( 'node:assert/strict' );
const { readFileSync, statSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const pluginRoot = join( __dirname, '..' );

function asset( relativePath ) {
	return readFileSync( join( pluginRoot, relativePath ), 'utf8' );
}

test( 'PJAX valida HTTP y forma antes de reemplazar el catálogo', () => {
	const source = asset( 'assets/js/akb-search.js' );

	// Candado por INVARIANTE, no por literal (la versión anterior fijaba la
	// línea exacta y se ponía roja ante cualquier reescritura legítima):
	// 1) un status no-OK que NO sea 404 aborta el swap;
	// 2) el 404 pasa, porque es la respuesta propia del catálogo para una
	//    colección filtrada vacía (FilteredUrlsPolicy) y trae el empty-state;
	// 3) el contrato de content-type y el de FORMA siguen corriendo — son los
	//    que atajan un 404 ajeno que no sea catálogo;
	// 4) el fallback duro sigue siendo la navegación nativa.
	assert.match( source, /! r\.ok && 404 !== r\.status/ );
	assert.match( source, /throw new Error\( 'pjax_http_' \+ r\.status \)/ );
	assert.match( source, /content-type/ );
	assert.match( source, /pjax_shape/ );
	assert.match( source, /window\.location\.assign\( url \)/ );
} );

test( 'El prefetch tampoco descarta el 404 del catálogo', () => {
	const source = asset( 'assets/js/akb-search.js' );

	// Sin esto el prefetch cacheaba sólo los caminos con resultados y la
	// combinación vacía —justo la que el cliente necesita corregir rápido—
	// llegaba siempre en frío.
	assert.match( source, /! r\.ok && 404 !== r\.status \) \{ throw new Error\( String\( r\.status \) \); \}/ );
} );

test( 'PJAX ignora popstate de fragmento y conserva la ubicación del DOM visible', () => {
	const source = asset( 'assets/js/akb-search.js' );
	const update = source.indexOf( 'renderedLocation = navigationKey( url )' );
	const dispatch = source.indexOf( "document.dispatchEvent( new Event( 'akb:pjax' ) )" );

	assert.match( source, /var renderedLocation = location\.pathname \+ location\.search;/ );
	assert.match(
		source,
		/function navigationKey\( url \)[\s\S]*?new URL\( url, location\.href \)[\s\S]*?return parsed\.pathname \+ parsed\.search;/
	);
	assert.match(
		source,
		/window\.addEventListener\( 'popstate',[\s\S]*?if \( renderedLocation === location\.pathname \+ location\.search \) \{[\s\S]*?return;[\s\S]*?\}[\s\S]*?go\( location\.href, false \);/
	);
	assert.ok( update >= 0 && dispatch > update, 'La clave visible se actualiza antes de publicar akb:pjax.' );
} );

test( 'el bundle PJAX publicado ignora hash-only pero navega otro pathname', () => {
	const listeners = new Map();
	let fetches = 0;
	const location = {
		href: 'https://akibara.test/serie/one-piece/',
		pathname: '/serie/one-piece/',
		search: '',
	};
	const sandbox = {
		AbortController,
		Event,
		URL,
		AKB_FACETS: {},
		addEventListener: (type, listener) => {
			listeners.set( type, (listeners.get( type ) || []).concat( listener ) );
		},
		removeEventListener: ( type, listener ) => {
			listeners.set( type, ( listeners.get( type ) || [] ).filter( ( l ) => l !== listener ) );
		},
		clearTimeout: () => {},
		document: {
			addEventListener: () => {},
			body: { classList: { add: () => {}, remove: () => {} } },
			querySelector: () => null,
			readyState: 'loading',
		},
		fetch: () => {
			fetches += 1;
			return new Promise( () => {} );
		},
		history: { pushState: () => {} },
		location,
		setTimeout: () => 1,
	};
	sandbox.window = sandbox;

	vm.runInNewContext( asset( 'assets/js/akb-search.min.js' ), sandbox );
	const popstate = listeners.get( 'popstate' );
	assert.equal( popstate && popstate.length, 1, 'El bundle publicado registra un solo handler popstate.' );

	location.href = 'https://akibara.test/serie/one-piece/#completar-coleccion';
	popstate[0]( {} );
	assert.equal( fetches, 0, 'Cambiar sólo el fragmento no abre PJAX.' );

	location.href = 'https://akibara.test/serie/one-piece/page/2/';
	location.pathname = '/serie/one-piece/page/2/';
	popstate[0]( {} );
	assert.equal( fetches, 1, 'Cambiar el pathname sigue navegando por PJAX.' );
} );

test( 'autocomplete conserva cancelación, fallback y contrato APG', () => {
	const source = asset( 'assets/js/search-suggest.js' );

	assert.match( source, /new AbortController\(\)/ );
	assert.match( source, /role', 'combobox'/ );
	assert.match( source, /aria-activedescendant/ );
	assert.match( source, /suggest_http_/ );
	assert.match( source, /Presiona Enter para buscar igual/ );
} );

test( 'el grupo de series va arriba, rotulado, y sin romper el recorrido del listbox', () => {
	const source = asset( 'assets/js/search-suggest.js' );

	// Grupo propio con head NO interactivo: los heads son role=presentation,
	// así el listbox sigue teniendo sólo options planos (patrón APG ya usado
	// por el vacío-enfocado) aunque ahora haya dos grupos.
	assert.match( source, /akb-suggest-head" role="presentation">' \+\s*\( series\.length === 1 \? 'Serie' : 'Series' \)/ );
	assert.match( source, /akb-suggest-head" role="presentation">Productos/ );
	assert.doesNotMatch( source, /akb-suggest-head" role="presentation">Tomos/ );
	assert.match( source, /class="akb-suggest-item akb-suggest-serie" role="option" aria-selected="false"/ );

	// El recorrido con flechas lee el DOM: state.items tiene que arrancar por
	// las series y los productos correrse ese mismo offset, o Enter navegaría
	// a otra cosa que la resaltada.
	assert.match( source, /state\.items = series\.concat\( items \)/ );
	assert.match( source, /var idx = series\.length \+ i;/ );
	assert.match( source, /var moreIdx = series\.length \+ items\.length;/ );
	assert.match( source, /state\.items = series\.concat\( items, \[ \{ url: moreUrl \} \] \)/ );

	// Una serie sola (sin productos) todavía abre el panel: el vacío es vacío
	// de verdad sólo cuando no hay NADA.
	assert.match( source, /if \( ! items\.length && ! series\.length \)/ );

	// El contrato con el server: la clave propia `series` del /suggest.
	assert.match( source, /data\.series \|\| \[\]/ );
} );

test( 'el catálogo usa copy inclusivo para productos y serie o edición', () => {
	const registry = asset( 'src/FacetRegistry.php' );
	const renderer = asset( 'frontend/facets-render.php' );

	assert.match( registry, /'serie'\s*=>\s*array\(\s*'label'\s*=>\s*'Serie o edición'/ );
	assert.match( registry, /'serie' === \$name && 'Serie' === \( \$def\['label'\] \?\? null \)/ );
	assert.match( renderer, /\$search_label = mb_strtolower\( \(string\) \$facet\['label'\] \);/ );
	assert.match( renderer, /placeholder="Buscar %s…" aria-label="Buscar %s"/ );
	assert.match( renderer, /_n\( '%s producto', '%s productos', \$total, 'akibara-search' \)/ );
	assert.doesNotMatch( renderer, /'%s tomos?'/ );

	// El dominio de texto es el del PLUGIN, no el del tema: la cabecera declara
	// `akibara-search` y usar `akibara-gp` dejaba estas dos cadenas —las únicas
	// traducibles del plugin— colgando de un dominio ajeno.
	assert.doesNotMatch( renderer, /'akibara-gp'/ );

	// `data-akb-count` y `data-akb-unit` los borramos: no los leía ningún JS y
	// el segundo estaba clavado en singular. Lo que sí importa es que el nodo
	// del conteo siga siendo el target estable del swap PJAX.
	assert.match( renderer, /<p class="akb-result-count">/ );
} );

test( 'admin exige nonce y credenciales same-origin al consultar el rebuild', () => {
	const source = asset( 'assets/js/admin-search.js' );

	assert.match( source, /'X-WP-Nonce': nonce/ );
	assert.match( source, /credentials: 'same-origin'/ );
	assert.match( source, /data-state.*reconstruyendo/ );
} );

test( 'los bundles minificados publicados existen y no están vacíos', () => {
	for ( const relativePath of [
		'assets/js/akb-search.min.js',
		'assets/js/search-suggest.min.js',
	] ) {
		assert.ok( statSync( join( pluginRoot, relativePath ) ).size > 100 );
	}
} );

test( 'el minificado servido lee la pista de «sin resultados» de la configuración', () => {
	// La superficie SERVIDA es el `.min`: editar el fuente y olvidar
	// `npx esbuild … --outfile=…min.js` deja al cliente con el bundle viejo, que
	// ignora la option y sigue diciendo la frase de fábrica. Este candado falla
	// justamente en ese olvido.
	assert.match( asset( 'assets/js/search-suggest.min.js' ), /emptyHint/ );
} );

test( 'P0 usa outbox durable y fence antes del writer live', () => {
	const plugin = asset( 'akibara-search.php' );
	const indexer = asset( 'src/Indexer.php' );
	const work = asset( 'src/WorkRepository.php' );
	const fence = asset( 'src/WriteFence.php' );

	// Los assets del buscador se sirven con `cache-control: public,
	// max-age=31557600` (un año, medido en prod con cf-cache-status HIT) y su
	// `?ver=` sale de esta versión: si el header y la constante se separan, una
	// mitad del release quedaría cacheada para siempre en los navegadores de los
	// visitantes recurrentes, y un purge de Cloudflare arregla el borde, no el
	// navegador. Lo que se fija es esa INVARIANTE, no un número: congelado en un
	// literal (`0.1.17`) el candado caducaba al primer bump y llevaba dos
	// releases en rojo sin frenar nada [QA 2026-08-16].
	const header = plugin.match( /Version:\s+(\d+\.\d+\.\d+)/ );
	assert.ok( header, 'El plugin debe declarar Version en la cabecera.' );
	assert.match( plugin, new RegExp( "AKIBARA_SEARCH_VERSION', '" + header[ 1 ].replace( /\./g, '\\.' ) + "'" ) );
	assert.match( indexer, /WorkRepository/ );
	assert.match( indexer, /WriteFence/ );
	assert.doesNotMatch( indexer, /note_live_write/ );
	assert.match( work, /wanted_seq/ );
	assert.match( work, /lease_seq/ );
	assert.match( work, /scope/ );
	assert.match( fence, /GET_LOCK/ );
	assert.match( fence, /IS_USED_LOCK/ );
} );

test( 'P0 publica sólo con cobertura exacta y fases recuperables', () => {
	const rebuild = asset( 'src/RebuildJob.php' );

	for ( const phase of [
		'PREPARING',
		'BUILDING',
		'CATCHING_UP',
		'SWAP_INTENT',
		'SWAPPED',
		'PUBLISHING',
		'CLEANUP',
		'DONE',
		'BLOCKED',
	] ) {
		assert.match( rebuild, new RegExp( phase ) );
	}

	assert.doesNotMatch( rebuild, /shadow\s*<\s*\(int\) ceil\(\s*\$expected\s*\*\s*self::COVERAGE_TOLERANCE/ );
	assert.match( rebuild, /eligible[^\n]*(?:minus|missing|diff)|missing[^\n]*eligible/i );
	assert.match( rebuild, /orphan/i );
} );

test( 'P0 conserva old hasta terminar la publicación', () => {
	const install = asset( 'src/Install.php' );

	// El RENAME vive en rename_keep_old_result(); el envoltorio booleano
	// rename_keep_old() no tenía llamador y se retiró en 0.1.64.
	assert.match( install, /function rename_keep_old_result\(/ );
	assert.doesNotMatch( install, /function rename_keep_old\(/ );
	assert.match( install, /function cleanup_old/ );
	assert.match( install, /function table_topology/ );

	const renameStart = install.indexOf( 'function rename_keep_old_result(' );
	const cleanupStart = install.indexOf( 'function cleanup_old' );
	assert.ok( renameStart >= 0 && cleanupStart > renameStart );
	assert.doesNotMatch( install.slice( renameStart, cleanupStart ), /drop_old_tables\s*\(/ );
} );

test( 'el bundle publicado cancela el PJAX en vuelo cuando el usuario pulsa Atrás', () => {
	// Defecto medido el 2026-08-24 (3 de 12 corridas instrumentadas del gate del
	// selector de series): el cliente pulsa «siguiente página», el fetch tarda, y
	// antes de que resuelva pulsa Atrás. El listener de popstate salía por su
	// return temprano —`renderedLocation` sigue apuntando a la página vieja
	// porque el swap nunca ocurrió— así que NADIE abortaba el fetch. Al aterrizar
	// tarde hacía el swap y un pushState: el usuario terminaba EN la página que
	// acababa de abandonar.
	//
	// Se ejercita el BUNDLE PUBLICADO, no el fuente: es el que sirve el sitio.
	const listeners = new Map();
	const señales = [];
	const clasesQuitadas = [];
	const location = {
		href: 'https://akibara.test/serie/one-piece/',
		pathname: '/serie/one-piece/',
		search: '',
	};
	const sandbox = {
		AbortController,
		Event,
		URL,
		AKB_FACETS: {},
		addEventListener: ( type, listener ) => {
			listeners.set( type, ( listeners.get( type ) || [] ).concat( listener ) );
		},
		removeEventListener: ( type, listener ) => {
			listeners.set( type, ( listeners.get( type ) || [] ).filter( ( l ) => l !== listener ) );
		},
		clearTimeout: () => {},
		document: {
			addEventListener: () => {},
			body: { classList: { add: () => {}, remove: ( c ) => clasesQuitadas.push( c ) } },
			querySelector: () => null,
			readyState: 'loading',
		},
		fetch: ( _url, opciones ) => {
			señales.push( opciones && opciones.signal );
			return new Promise( () => {} ); // nunca resuelve: el fetch sigue en vuelo.
		},
		history: { pushState: () => {} },
		location,
		setTimeout: () => 1,
	};
	sandbox.window = sandbox;

	vm.runInNewContext( asset( 'assets/js/akb-search.min.js' ), sandbox );
	const popstate = listeners.get( 'popstate' )[0];

	// 1) El usuario navega a la página 2: arranca un fetch que no resolverá.
	location.href = 'https://akibara.test/serie/one-piece/page/2/';
	location.pathname = '/serie/one-piece/page/2/';
	popstate( {} );

	assert.equal( señales.length, 1, 'La navegación tiene que haber abierto un fetch.' );
	assert.equal( señales[0].aborted, false, 'Todavía en vuelo.' );

	// 2) El usuario pulsa Atrás ANTES de que el swap ocurra. `renderedLocation`
	//    sigue en la página 1, así que este popstate cae en el return temprano —
	//    y aun así tiene que cancelar.
	location.href = 'https://akibara.test/serie/one-piece/';
	location.pathname = '/serie/one-piece/';
	popstate( {} );

	assert.equal(
		señales[0].aborted,
		true,
		'Atrás tiene que abortar el PJAX pendiente: si no, al llegar tarde pisa la navegación del usuario.'
	);
	assert.ok(
		clasesQuitadas.includes( 'akb-pjax-loading' ),
		'Y tiene que apagar el dim: el finally de go() sólo lo quita para el request vigente, así que la clase quedaba pegada.'
	);
} );

test( 'quitar un chip conserva el orden del catálogo', () => {
	// El form de ordenamiento del tema copia todos los `_*` al suyo; el drawer
	// de facetas no devolvía el favor y `urlWithout()` descartaba `orderby`
	// explícitamente. Ordenar por precio y quitar un chip devolvía el catálogo
	// al orden por defecto, sin aviso.
	const documentListeners = new Map();
	const urls = [];
	const location = {
		href: 'https://akibara.test/serie/one-piece/?_editorial=ivrea&orderby=price',
		pathname: '/serie/one-piece/',
		search: '?_editorial=ivrea&orderby=price',
	};
	const chip = {
		classList: { contains: () => false },
		getAttribute: ( name ) => ( 'data-facet' === name ? 'editorial' : 'ivrea' ),
	};
	const sandbox = {
		AbortController,
		Event,
		URL,
		URLSearchParams,
		AKB_FACETS: {},
		addEventListener: () => {},
		removeEventListener: () => {},
		clearTimeout: () => {},
		Date,
		document: {
			addEventListener: ( type, listener ) => {
				documentListeners.set(
					type,
					( documentListeners.get( type ) || [] ).concat( listener )
				);
			},
			body: { classList: { add: () => {}, remove: () => {} } },
			// Sin `<form data-akb-facets>` (los archives de atributo van sin
			// sidebar): es la rama que pasa por urlWithout().
			querySelector: () => null,
			querySelectorAll: () => [],
			readyState: 'loading',
		},
		history: { pushState: () => {} },
		location,
		setTimeout: () => 1,
		fetch: ( url ) => {
			urls.push( url );
			return new Promise( () => {} );
		},
	};
	sandbox.window = sandbox;

	vm.runInNewContext( asset( 'assets/js/akb-search.min.js' ), sandbox );

	const clickListeners = documentListeners.get( 'click' ) || [];
	assert.ok( clickListeners.length, 'El bundle publicado delega los chips en document.' );

	const event = {
		preventDefault: () => {},
		target: {
			closest: ( selector ) =>
				'[data-akb-chips] .akb-chip' === selector ? chip : null,
		},
	};
	clickListeners.forEach( ( listener ) => listener( event ) );

	assert.equal( urls.length, 1, 'Quitar el chip navega.' );
	assert.ok(
		urls[0].includes( 'orderby=price' ),
		'Quitar un filtro no puede reordenar el catálogo: ' + urls[0]
	);
	assert.ok(
		! urls[0].includes( '_editorial' ),
		'Y el filtro quitado sí se va: ' + urls[0]
	);
} );

test( 'expandir una faceta grande va con debounce y por ruta parcial', () => {
	const source = asset( 'assets/js/akb-search.js' );
	const renderer = asset( 'frontend/facets-render.php' );

	// Se disparaba con la PRIMERA tecla y otra vez con cada una siguiente:
	// escribir «one piece» abría una petición por letra desde el móvil.
	assert.match( source, /FACET_LOAD_DEBOUNCE = (\d+)/ );
	const debounce = Number( /FACET_LOAD_DEBOUNCE = (\d+)/.exec( source )[ 1 ] );
	assert.ok( debounce > 0 && debounce <= 500, 'la ventana existe y no vuelve la carga perezosa' );
	assert.match(
		source,
		/filterLargeFacet\( section, box\.value \);[\s\S]*?facetLoadTimer = window\.setTimeout\(/,
		'el filtrado de lo YA pintado sigue siendo inmediato: es local'
	);

	// Enter y «Ver todas» son declaraciones explícitas del usuario: inmediatas,
	// y cancelan el timer pendiente para no pedir la lista dos veces.
	assert.match( source, /'Enter' !== e\.key[\s\S]*?window\.clearTimeout\( facetLoadTimer \)/ );

	// Y el servidor responde con el grupo de la faceta, no con la página entera
	// (230 KB medidos para quedarse con un <ul> de 22 KB).
	assert.match( renderer, /function akibara_gp_akb_facet_options_fragment\(\)/ );
	// El fragmento se decide por un parámetro PROPIO de la URL, no por una
	// cabecera sobre la URL de la página: el borde no varía por cabeceras y
	// guardaba el fragmento para servirlo como página (medido en producción el
	// 2026-09-19, hallazgo S-127b9306). Las dos mitades tienen que coincidir.
	assert.match( renderer, /'1' !== \$_GET\['_akb_fragment'\]/ );
	assert.doesNotMatch( renderer, /'akb-facet-options' !== \$requested_with/, 'la cabecera sola no puede volver a cambiar la respuesta' );
	assert.match( source, /url\.searchParams\.set\( '_akb_fragment', '1' \)/ );
	assert.match( asset( 'assets/js/akb-search.min.js' ), /_akb_fragment/, 'y tiene que llegar a lo SERVIDO' );
	assert.match( renderer, /add_action\( 'template_redirect', 'akibara_gp_akb_render_facet_options_fragment', 0 \)/ );
} );

test( 'los bundles .min corresponden a su fuente: banner sha256 obligatorio (la trampa de la superficie servida)', ( t ) => {
	// El defecto PJAX del Atrás se arregló en akb-search.js y el catálogo
	// siguió sirviendo akb-search.min.js SIN el fix durante una pasada entera
	// del gate (2026-09-01): producción sirve el .min salvo SCRIPT_DEBUG.
	//
	// Historia de este candado [revisión de 0.1.64]. Primero comparaba mtime, y
	// lo engaña un `touch`. Después regeneraba con esbuild y se SALTABA sin
	// esbuild, que es justo la situación de las máquinas del repo: no comprobaba
	// nada y qa-search.sh contaba el skip como verde. Ahora cada .min lleva en su
	// primera línea el sha256 del fuente del que salió (`--banner` en el mismo
	// comando de esbuild, ver Search::enqueue_assets) y esto lo verifica SIEMPRE,
	// sin herramientas: un fuente editado sin regenerar su .min pone la suite en
	// rojo en cualquier máquina. NUNCA se salta.
	const { createHash } = require( 'node:crypto' );
	const { spawnSync } = require( 'node:child_process' );
	const bundles = [
		[ 'assets/js/search-suggest.js', 'assets/js/search-suggest.min.js', [ '--minify', '--target=es2017' ] ],
		[ 'assets/js/akb-search.js', 'assets/js/akb-search.min.js', [ '--minify' ] ],
		[ 'assets/css/search-suggest.css', 'assets/css/search-suggest.min.css', [ '--minify' ] ],
		[ 'assets/css/akb-search.css', 'assets/css/akb-search.min.css', [ '--minify' ] ],
	];
	const BANNER = /^\/\*! akb-src-sha256: ([0-9a-f]{64}) \*\/\n/;
	let esbuildLocal = null;

	for ( const [ fuente, min, flags ] of bundles ) {
		const sha = createHash( 'sha256' ).update( readFileSync( join( pluginRoot, fuente ) ) ).digest( 'hex' );
		const servido = asset( min );
		const banner = BANNER.exec( servido );
		assert.ok( banner, `${min} no lleva en su primera línea el banner akb-src-sha256: regenerarlo con el comando de Search::enqueue_assets` );
		assert.equal( banner[ 1 ], sha, `${min} no corresponde a ${fuente} (sha256 distinto): el fuente cambió y su .min no se regeneró` );

		// Capa extra, SÓLO si hay esbuild local (npx --no-install no descarga):
		// además del sha, los bytes son la salida exacta del comando documentado.
		// Su ausencia no quita la comprobación de arriba, que ya corrió.
		if ( null === esbuildLocal ) {
			const version = spawnSync( 'npx', [ '--no-install', 'esbuild', '--version' ], { encoding: 'utf8' } );
			esbuildLocal = ! version.error && 0 === version.status;
			if ( ! esbuildLocal ) {
				t.diagnostic( 'esbuild no disponible: se verificó el banner sha256 de los 4 bundles, no la salida byte a byte' );
			}
		}
		if ( esbuildLocal ) {
			const tipo = fuente.endsWith( '.css' ) ? 'css' : 'js';
			const salida = spawnSync(
				'npx',
				[ '--no-install', 'esbuild', join( pluginRoot, fuente ), ...flags, `--banner:${ tipo }=/*! akb-src-sha256: ${ sha } */` ],
				{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
			);
			assert.equal( salida.status, 0, `${fuente}: esbuild falló: ${salida.stderr}` );
			assert.equal( salida.stdout, servido, `${min} lleva el sha correcto pero no es la salida exacta de esbuild sobre ${fuente}` );
		}
	}
} );

/*
 * Reintento de la ventana TRANSITORIA del buscador [0.1.64]. Guardar un ajuste
 * editorial deja al servidor unos segundos en 503 `index_unavailable` (medido
 * en el espejo el 2026-09-14: 2 de 105 búsquedas entre +2 y +3 s del guardado).
 * Las pruebas de comportamiento viven en search-suggest-frontend.test.js; aquí
 * se fija lo que esas no pueden ver: que el otro bundle usa EL MISMO helper,
 * qué superficies lo aplican, que es coherente con el servidor y que llega a
 * lo SERVIDO (el `.min`).
 */
test( 'el helper de reintento es idéntico en los dos bundles', () => {
	const bloque = ( fuente ) => {
		const inicio = fuente.indexOf( '\tvar RETRY_BASE = 1000;' );
		const cierre = '\t\treturn intento( 0 );\n\t}';
		const fin = fuente.indexOf( cierre, inicio );
		assert.ok( inicio !== -1 && fin !== -1, 'el helper de reintento existe' );
		return fuente.slice( inicio, fin + cierre.length );
	};
	assert.equal(
		bloque( asset( 'assets/js/akb-search.js' ) ),
		bloque( asset( 'assets/js/search-suggest.js' ) ),
		'duplicado a propósito (dos bundles independientes), pero byte a byte: así las pruebas de uno cubren al otro'
	);
} );

test( 'sólo se reintenta el 503 index_unavailable, nunca un 429', () => {
	for ( const fichero of [ 'assets/js/search-suggest.js', 'assets/js/akb-search.js' ] ) {
		const fuente = asset( fichero );
		assert.match( fuente, /503 !== r\.status/, fichero );
		assert.match( fuente, /'index_unavailable' === body\.code/, fichero );
		assert.doesNotMatch( fuente, /429 === r\.status/, fichero + ': el rate-limit no se arregla insistiendo' );
	}
} );

test( 'count, expandir faceta y PJAX aplican la misma política', () => {
	const fuente = asset( 'assets/js/akb-search.js' );
	// /count: 503 JSON transitorio, cortado por la request más nueva.
	assert.match( fuente, /fetchReintentando\(\s*CFG\.countUrl \+ qs,[\s\S]*?function \(\) \{ return mine === countInflight; \}/ );
	// PJAX y expandir faceta reintentan SÓLO el fail-open que el servidor marca
	// (revisión de 0.1.64): «faltan facetas» también es un archivo vacío legítimo.
	assert.match( fuente, /FAIL_OPEN_MARCA = 'meta\[name="akb-search-facets"\]\[content="indisponible"\]'/ );
	assert.match( fuente, /if \( reintentarFailOpen\( doc, intento \) \) \{/ );
	assert.match( fuente, /! next && document\.contains\( section \) && reintentarFailOpen\( doc, intento \)/ );
	// El contrato de FORMA sí mira `.akb-facets` (grilla, vacío o facetas); lo que
	// no puede volver es reintentar por «esta página tiene facetas y la respuesta no».
	assert.doesNotMatch(
		fuente,
		/document\.querySelector\( '\.akb-facets' \) && ! doc\.querySelector\( '\.akb-facets' \)/,
		'la ausencia de facetas por sí sola no reintenta'
	);
	assert.match( fuente, /esperar\( RETRY_BASE \* Math\.pow\( 2, intento \), mine\.signal \)/ );
	// Disyuntor: un cierre largo no cuesta cuatro renders por clic.
	assert.match( fuente, /failOpenHasta = Date\.now\(\) \+ FAIL_OPEN_PAUSA;/ );
} );

test( 'el backoff del cliente es coherente con el Retry-After y el re-sellado del servidor', () => {
	const retryAfter = Number( /RETRY_AFTER_SECONDS = (\d+);/.exec( asset( 'src/Support/RestCache.php' ) )[ 1 ] );
	const reintentoResellado = Number( /RECONCILE_RETRY_SECONDS = (\d+);/.exec( asset( 'src/EditorialDiscountPublication.php' ) )[ 1 ] );
	for ( const fichero of [ 'assets/js/search-suggest.js', 'assets/js/akb-search.js' ] ) {
		const fuente = asset( fichero );
		const base = Number( /var RETRY_BASE = (\d+);/.exec( fuente )[ 1 ] );
		const maximo = Number( /var RETRY_MAX = (\d+);/.exec( fuente )[ 1 ] );
		assert.equal( base, retryAfter * 1000, fichero + ': el primer reintento es el Retry-After del servidor' );
		let hastaElUltimo = 0;
		for ( let n = 0; n < maximo; n++ ) {
			hastaElUltimo += base * 2 ** n;
		}
		assert.ok(
			hastaElUltimo > reintentoResellado * 1000,
			fichero + ': el último intento tiene que llegar después del reintento de re-sellado del servidor'
		);
	}
	assert.match( asset( 'src/SuggestController.php' ), /RestCache::retry_after\( \$response \)/ );
	assert.match( asset( 'src/FacetQueryIntegration.php' ), /RestCache::retry_after\( \$response \)/ );
} );

test( 'los bundles SERVIDOS llevan el reintento', () => {
	// Producción sirve el `.min`: editar el fuente sin regenerar dejaría al
	// cliente pintando el error en la ventana de guardado.
	for ( const fichero of [ 'assets/js/search-suggest.min.js', 'assets/js/akb-search.min.js' ] ) {
		const servido = asset( fichero );
		assert.match( servido, /index_unavailable/, fichero );
		assert.match( servido, /Retry-After/, fichero );
	}
} );

/*
 * Fail-open MARCADO [revisión de 0.1.64], sobre el BUNDLE PUBLICADO. Una página
 * sin facetas no basta para reintentar: el archivo vacío sin filtros omite el
 * form a propósito. Sólo se reintenta con la marca del servidor, y un cierre
 * largo abre el disyuntor.
 */
function montarPjax( respuestas ) {
	const listeners = new Map();
	const temporizadores = [];
	const pedidas = [];
	let secuencia = 0;
	let pintadas = 0;
	const location = { href: 'https://akibara.test/tienda/', pathname: '/tienda/', search: '' };
	// `closest()` existe en todo Element real: el PJAX lo usa para no tomar por
	// rejilla del catálogo la venta cruzada que el tema pinta dentro del aviso
	// de «sin resultados» (busqueda-sin-resultados-pjax.test.js). Aquí ningún
	// nodo vive dentro de ese aviso.
	const nodo = () => ( {
		id: '', className: '', textContent: '',
		setAttribute() {}, classList: { add() {}, remove() {} },
		closest: () => null,
	} );
	const sandbox = {
		AbortController, Event, URL, Date,
		AKB_FACETS: {},
		addEventListener: ( type, fn ) => listeners.set( type, ( listeners.get( type ) || [] ).concat( fn ) ),
		removeEventListener: ( type, fn ) => listeners.set( type, ( listeners.get( type ) || [] ).filter( ( f ) => f !== fn ) ),
		setTimeout: ( fn, ms ) => {
			secuencia += 1;
			temporizadores.push( { id: secuencia, fn, ms, vivo: true } );
			return secuencia;
		},
		clearTimeout: ( id ) => temporizadores.forEach( ( t ) => { if ( t.id === id ) { t.vivo = false; } } ),
		DOMParser: function () {
			this.parseFromString = ( html ) => ( {
				title: 'Tienda',
				querySelector: ( sel ) => {
					if ( 'ul.products' === sel ) { return html.includes( 'class="products"' ) ? nodo() : null; }
					if ( '.akb-facets' === sel ) { return html.includes( 'akb-facets' ) ? nodo() : null; }
					if ( 0 === sel.indexOf( 'meta[name="akb-search-facets"]' ) ) { return html.includes( 'name="akb-search-facets" content="indisponible"' ) ? nodo() : null; }
					return null;
				},
			} );
		},
		document: {
			addEventListener() {}, removeEventListener() {},
			body: { classList: { add() {}, remove() {} }, appendChild() {} },
			querySelector: () => null,
			querySelectorAll: () => [],
			getElementById: () => null,
			createElement: nodo,
			dispatchEvent: ( ev ) => { if ( 'akb:pjax' === ev.type ) { pintadas += 1; } },
			readyState: 'loading',
		},
		fetch: ( url ) => {
			pedidas.push( url );
			const html = respuestas.shift();
			return Promise.resolve( {
				ok: true, status: 200,
				headers: { get: () => 'text/html; charset=UTF-8' },
				text: () => Promise.resolve( html ),
			} );
		},
		history: { pushState() {} },
		location,
	};
	sandbox.window = sandbox;
	vm.runInNewContext( asset( 'assets/js/akb-search.min.js' ), sandbox );
	const popstate = listeners.get( 'popstate' )[ 0 ];
	return {
		navegar( pagina ) {
			location.href = 'https://akibara.test/tienda/page/' + pagina + '/';
			location.pathname = '/tienda/page/' + pagina + '/';
			popstate( {} );
		},
		esperas: () => temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).map( ( t ) => t.ms ),
		correrEsperas: () => temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).forEach( ( t ) => { t.vivo = false; t.fn(); } ),
		pedidas: () => pedidas.length,
		pintadas: () => pintadas,
	};
}

const pausa = async () => {
	for ( let i = 0; i < 4; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
	}
};
const PAGINA_SIN_FACETAS = '<html><head></head><body><ul class="products"></ul></body></html>';
const PAGINA_FAIL_OPEN = '<html><head><meta name="akb-search-facets" content="indisponible"></head><body><ul class="products"></ul></body></html>';
const PAGINA_BUENA = '<html><head></head><body><form class="akb-facets"></form><ul class="products"></ul></body></html>';

test( 'PJAX: una página legítimamente sin facetas se pinta al primer render, sin reintentos', async () => {
	const app = montarPjax( [ PAGINA_SIN_FACETAS ] );

	app.navegar( 2 );
	await pausa();

	assert.equal( app.pedidas(), 1 );
	assert.deepEqual( app.esperas(), [], 'sin la marca del servidor no hay reintento' );
	assert.equal( app.pintadas(), 1, 'se pinta lo que llegó' );
} );

test( 'PJAX: el fail-open MARCADO se reintenta y pinta la respuesta buena', async () => {
	const app = montarPjax( [ PAGINA_FAIL_OPEN, PAGINA_FAIL_OPEN, PAGINA_BUENA ] );

	app.navegar( 2 );
	const esperas = [];
	for ( let i = 0; i < 2; i++ ) {
		await pausa();
		assert.equal( app.pintadas(), 0, 'no se pinta el catálogo sin filtrar mientras se reintenta' );
		esperas.push( ...app.esperas() );
		app.correrEsperas();
	}
	await pausa();

	assert.deepEqual( esperas, [ 1000, 2000 ] );
	assert.equal( app.pedidas(), 3 );
	assert.equal( app.pintadas(), 1 );
} );

test( 'PJAX: un cierre largo abre el disyuntor y la navegación siguiente no insiste', async () => {
	const app = montarPjax( [ PAGINA_FAIL_OPEN, PAGINA_FAIL_OPEN, PAGINA_FAIL_OPEN, PAGINA_FAIL_OPEN, PAGINA_FAIL_OPEN ] );

	app.navegar( 2 );
	const esperas = [];
	for ( let i = 0; i < 3; i++ ) {
		await pausa();
		esperas.push( ...app.esperas() );
		app.correrEsperas();
	}
	await pausa();
	assert.deepEqual( esperas, [ 1000, 2000, 4000 ] );
	assert.equal( app.pedidas(), 4, 'un intento y tres reintentos' );
	assert.equal( app.pintadas(), 1, 'tras el último se pinta lo que llegó, como siempre' );

	app.navegar( 3 );
	await pausa();
	assert.equal( app.pedidas(), 5 );
	assert.deepEqual( app.esperas(), [], 'con el disyuntor abierto, un clic cuesta un render, no cuatro' );
	assert.equal( app.pintadas(), 2 );
} );

/*
 * «Ver todas las opciones» (`loadLargeFacet()` → `cargar()`), sobre el BUNDLE
 * PUBLICADO arrancado de verdad: el clic llega por el listener que `bindForm()`
 * engancha en el form. Misma regla que el PJAX [revisión de 0.1.64]: una faceta
 * ausente sólo se reintenta si el servidor marcó el fail-open.
 */
function montarFaceta( respuestas ) {
	const temporizadores = [];
	const oyentesForm = new Map();
	let pedidas = 0;
	let secuencia = 0;
	const atributos = { 'data-facet': 'autor' };
	const estado = { textContent: '' };
	const lista = { children: [], replaceChildren() {}, appendChild() {} };
	const boton = {
		disabled: false,
		textContent: 'Ver las 40 opciones',
		setAttribute() {},
		getAttribute: () => 'Ver las 40 opciones',
		remove() {},
		closest: ( sel ) => ( '.akb-facet' === sel ? seccion : null ),
	};
	const seccion = {
		getAttribute: ( k ) => ( k in atributos ? atributos[ k ] : null ),
		setAttribute: ( k, v ) => { atributos[ k ] = String( v ); },
		removeAttribute: ( k ) => { delete atributos[ k ]; },
		querySelector: ( sel ) => ( { '[data-akb-facet-expand]': boton, '[data-akb-facet-status]': estado, '.akb-facet-list': lista }[ sel ] || null ),
		querySelectorAll: () => [],
	};
	const form = {
		dataset: {},
		querySelectorAll: () => [],
		addEventListener: ( type, fn ) => oyentesForm.set( type, ( oyentesForm.get( type ) || [] ).concat( fn ) ),
		getAttribute: ( k ) => ( 'action' === k ? '/tienda/' : null ),
	};
	const sandbox = {
		AbortController, Event, URL, URLSearchParams, Date,
		AKB_FACETS: {},
		FormData: function () { this.forEach = () => {}; },
		addEventListener() {},
		matchMedia: () => ( { matches: false, addEventListener() {}, addListener() {} } ),
		setTimeout: ( fn, ms ) => {
			secuencia += 1;
			temporizadores.push( { id: secuencia, fn, ms, vivo: true } );
			return secuencia;
		},
		clearTimeout: ( id ) => temporizadores.forEach( ( t ) => { if ( t.id === id ) { t.vivo = false; } } ),
		DOMParser: function () {
			this.parseFromString = ( html ) => ( {
				querySelector: ( sel ) => ( 0 === sel.indexOf( 'meta[name="akb-search-facets"]' ) && html.includes( 'name="akb-search-facets" content="indisponible"' ) ? {} : null ),
				querySelectorAll: ( sel ) => ( '.akb-facet[data-facet]' === sel && html.includes( 'data-facet="autor"' )
					? [ { getAttribute: () => 'autor', querySelector: ( s ) => ( '.akb-facet-list' === s ? { children: [] } : null ) } ]
					: [] ),
			} );
		},
		document: {
			addEventListener() {}, removeEventListener() {},
			body: { classList: { add() {}, remove() {} }, appendChild() {}, children: [] },
			querySelector: ( sel ) => ( '[data-akb-facets]' === sel ? form : null ),
			querySelectorAll: () => [],
			getElementById: () => null,
			createElement: () => ( { hidden: false, className: '', textContent: '' } ),
			contains: () => true,
			importNode: ( n ) => n,
			readyState: 'complete',
		},
		fetch: () => {
			pedidas += 1;
			const html = respuestas.shift();
			return Promise.resolve( { ok: true, status: 200, text: () => Promise.resolve( html ) } );
		},
		history: { pushState() {} },
		location: { href: 'https://akibara.test/tienda/', pathname: '/tienda/', search: '' },
	};
	sandbox.window = sandbox;
	vm.runInNewContext( asset( 'assets/js/akb-search.min.js' ), sandbox );
	return {
		pulsarVerTodas() {
			const evento = { preventDefault() {}, target: { closest: ( sel ) => ( '[data-akb-facet-expand]' === sel ? boton : null ) } };
			( oyentesForm.get( 'click' ) || [] ).forEach( ( fn ) => fn( evento ) );
		},
		esperas: () => temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).map( ( t ) => t.ms ),
		correrEsperas: () => temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).forEach( ( t ) => { t.vivo = false; t.fn(); } ),
		pedidas: () => pedidas,
		estado: () => estado.textContent,
		cargada: () => 'true' === atributos[ 'data-akb-facet-loaded' ],
	};
}

const PAGINA_SIN_LA_FACETA = '<html><head></head><body><ul class="products"></ul></body></html>';
const PAGINA_FAIL_OPEN_SIN_FACETA = '<html><head><meta name="akb-search-facets" content="indisponible"></head><body></body></html>';
const PAGINA_CON_LA_FACETA = '<html><body><div class="akb-facet" data-facet="autor"><ul class="akb-facet-list"></ul></div></body></html>';

test( 'Ver todas: una faceta ausente SIN marca avisa al primer intento, sin reintentos', async () => {
	const app = montarFaceta( [ PAGINA_SIN_LA_FACETA ] );

	app.pulsarVerTodas();
	await pausa();

	assert.equal( app.pedidas(), 1 );
	assert.deepEqual( app.esperas(), [] );
	assert.match( app.estado(), /No se pudieron cargar las opciones/ );
	assert.equal( app.cargada(), false );
} );

test( 'Ver todas: una faceta ausente CON marca se reintenta y carga la lista', async () => {
	const app = montarFaceta( [ PAGINA_FAIL_OPEN_SIN_FACETA, PAGINA_CON_LA_FACETA ] );

	app.pulsarVerTodas();
	await pausa();
	assert.equal( app.pedidas(), 1 );
	assert.deepEqual( app.esperas(), [ 1000 ] );
	assert.doesNotMatch( app.estado(), /No se pudieron cargar/, 'no hay aviso de error mientras se reintenta' );

	app.correrEsperas();
	await pausa();
	assert.equal( app.pedidas(), 2 );
	assert.equal( app.cargada(), true );
	assert.doesNotMatch( app.estado(), /No se pudieron cargar/ );
} );

test( 'el chip de filtro activo acota su ancho y parte cadenas largas sin espacios en móvil', () => {
	const css = asset( 'assets/css/akb-search.css' );
	const min = asset( 'assets/css/akb-search.min.css' );

	assert.match( css, /\.akb-chip\s*\{[^}]*max-width:\s*100%;[^}]*overflow-wrap:\s*anywhere;/ );
	assert.match( min, /\.akb-chip\{[^}]*max-width:100%[^}]*overflow-wrap:anywhere/ );
} );

test( 'PJAX sincroniza la fecha de frescura del catálogo (.akb-route-updated)', () => {
	const source = asset( 'assets/js/akb-search.js' );
	assert.match( source, /'\.akb-route-updated'/ );
	assert.match( source, /if \( '\.akb-route-updated' === sel \)/ );
} );
