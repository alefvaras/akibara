/**
 * akb-search.js — pjax de facetas + drawer mobile. Vanilla, CERO jQuery.
 * PE del form GET: sin JS recarga; con JS intercepta submit/pager/chips, fetch
 * de la MISMA URL, swap, pushState/popstate.
 */
( function () {
	'use strict';

	var SWAP = [
		'ul.products',
		'.woocommerce-pagination',
		'.akb-facets',
		'.akb-facet-chips',
		'.akb-shop-toolbar',
		'.akb-empty-state',
		'.page-title',
		'.term-description',
		'.akb-route-updated'
	];
	var GRID = 'ul.products';
	// El aviso de «sin resultados». El tema pinta DENTRO su venta cruzada
	// (`akibara_search_empty_after`), que también es una `ul.products`: esa
	// rejilla viaja con el aviso y no es la del catálogo (ver mainGrid()).
	var EMPTY = '[data-akb-empty]';
	var FORM = '[data-akb-facets]';
	// El aviso de un PJAX que no llegó por la red (ver avisarFalloDeRed()). Lo
	// pinta el servidor, con el texto del panel, en un <template> de la barra de
	// resultados: hasta que hace falta ni se ve ni es texto de la página.
	var AVISO_RED = 'template[data-akb-aviso-red]';
	var CFG = window.AKB_FACETS || {};
	var MOBILE_MAX = CFG.mobileMax || 768;

	var inflight = null;
	var debounce = 0;
	var countInflight = null;
	var countTimer = 0;
	var drawerOpen = false;   // el drawer mobile está abierto.
	var pendingApply = false; // hay cambios sin aplicar (modo batch).
	var drawerClose = null; // set en initDrawer.

	// Caché LRU en memoria client-side (Map de 20 entradas, TTL 3 min):
	// acelera el hover prefetch, navegación recurrente y desmarcado en 0ms.
	var AkbCatalogCache = ( function ( maxEntries, ttlMs ) {
		var cache = new Map();

		function normalizeKey( uStr ) {
			try {
				var u = new URL( uStr, location.href );
				u.searchParams.sort();
				return u.pathname + '?' + u.searchParams.toString();
			} catch ( e ) {
				return uStr;
			}
		}

		return {
			get: function ( uStr ) {
				var key = normalizeKey( uStr );
				if ( ! cache.has( key ) ) {
					return null;
				}
				var entry = cache.get( key );
				if ( Date.now() - entry.timestamp > ttlMs ) {
					cache.delete( key );
					return null;
				}
				cache.delete( key );
				cache.set( key, entry );
				return entry.html;
			},
			set: function ( uStr, html ) {
				if ( typeof html !== 'string' || '' === html ) {
					return;
				}
				var key = normalizeKey( uStr );
				if ( cache.has( key ) ) {
					cache.delete( key );
				} else if ( cache.size >= maxEntries ) {
					var oldestKey = cache.keys().next().value;
					cache.delete( oldestKey );
				}
				cache.set( key, { html: html, timestamp: Date.now() } );
			},
			clear: function () {
				cache.clear();
			}
		};
	} )( 20, 180000 );

	// Prefetch especulativo (gate 07-16): slot ÚNICO {url,ctl,t,promise};
	// go() lo consume si matchea o lo aborta. Intent = 150 ms sobre el label.
	var pre = null;
	var preTimer = 0;
	var preLabel = null;
	var dimTimer = 0;     // dim diferido 120ms (it2 C) — ÚNICO, limpiado al entrar a go().
	var postSwap = false; // suprime el pointerover SINTÉTICO post-swap hasta que
	                      // haya movimiento REAL (pointermove) — sin ventana de reloj.
	var pressPt = null;    // coords del último press: el jitter de ±3px al soltar
	                       // el click NO cuenta como movimiento real (it3).
	var onFirstMove = null; // listener ÚNICO post-swap (se reemplaza, no se acumula).
	// Carga bajo demanda de las facetas grandes (Autor/Serie): un solo timer,
	// porque sólo puede haber una expansión en vuelo a la vez. 220 ms, la misma
	// ventana del autocomplete: es el mismo gesto de tecleo.
	var facetLoadTimer = 0;
	var FACET_LOAD_DEBOUNCE = 120;
	// Path + query que describe el DOM visible. El fragmento es estado UI local
	// (por ejemplo, el selector de tomos) y nunca exige releer el catálogo.
	var renderedLocation = location.pathname + location.search;

	// Capturar la modalidad del último gesto antes de los handlers y del clic sintético de
	// Enter/Espacio. El drawer evita el anillo tras puntero y keepFocus() elige dónde devolver el
	// foco después de PJAX. null cuenta como teclado: un lector puede activar controles sin
	// pointerdown.
	var modality = null;
	// Teclas pulsadas desde que cargó la página: si cambian mientras un PJAX
	// espera, el cliente siguió con el teclado (ver avisarFalloDeRed()).
	var teclas = 0;
	document.addEventListener( 'keydown', function () {
		modality = 'teclado';
		teclas++;
	}, true );
	document.addEventListener( 'pointerdown', function () { modality = 'puntero'; }, true );

	/*
	 * Reintentar la ventana transitoria sin índice sellado: /count responde 503 index_unavailable
	 * y el catálogo o el fragmento de opciones pueden traer la marca meta
	 * akb-search-facets=indisponible sin facetas. Usar esperas de 1s, 2s y 4s según
	 * Retry-After/RestCache::RETRY_AFTER_SECONDS; no reintentar 429. La lógica se duplica en
	 * search-suggest.js porque son bundles independientes.
	 */
	var RETRY_BASE = 1000;
	var RETRY_MAX = 3;

	function abortado() {
		var e = new Error( 'aborted' );
		e.name = 'AbortError';
		return e;
	}

	function esTransitorio( r ) {
		if ( ! r || 503 !== r.status || 'function' !== typeof r.json ) {
			return Promise.resolve( false );
		}
		return r.json().then(
			function ( body ) { return !! body && 'index_unavailable' === body.code; },
			function () { return false; }
		);
	}

	function demoraReintento( r, intento ) {
		var ra = r && r.headers && 'function' === typeof r.headers.get ? parseInt( r.headers.get( 'Retry-After' ), 10 ) : NaN;
		var base = ra >= 1 && ra <= 4 ? ra * 1000 : RETRY_BASE;
		return base * Math.pow( 2, intento );
	}

	// Espera cortable: un abort (tipeo nuevo, navegación) la rechaza al instante.
	function esperar( ms, signal ) {
		return new Promise( function ( resolve, reject ) {
			if ( signal && signal.aborted ) {
				reject( abortado() );
				return;
			}
			var id = window.setTimeout( function () {
				if ( signal ) {
					signal.removeEventListener( 'abort', alAbortar );
				}
				resolve();
			}, ms );
			function alAbortar() {
				window.clearTimeout( id );
				reject( abortado() );
			}
			if ( signal ) {
				signal.addEventListener( 'abort', alAbortar );
			}
		} );
	}

	/*
	 * Reintentar el 503 transitorio y devolver la Response final para que el consumidor decida
	 * sobre no-ok. Consultar seguir() antes de esperar y repetir. Si la consulta ya cambió,
	 * devolver el último 503 sin abort artificial para que el consumidor cierre su carga sin
	 * pintar resultados obsoletos. Un abort real del controller sí rechaza.
	 */
	function fetchReintentando( url, opciones, seguir, alReintentar ) {
		var signal = opciones && opciones.signal;
		function intento( n ) {
			return fetch( url, opciones ).then( function ( r ) {
				if ( r.ok || n >= RETRY_MAX ) {
					return r;
				}
				return esTransitorio( r ).then( function ( transitorio ) {
					if ( ! transitorio || ( seguir && ! seguir() ) ) {
						return r;
					}
					if ( alReintentar ) {
						alReintentar( n + 1 );
					}
					return esperar( demoraReintento( r, n ), signal ).then( function () {
						return seguir && ! seguir() ? r : intento( n + 1 );
					} );
				} );
			} );
		}
		return intento( 0 );
	}

	/*
	 * Fail-open MARCADO [revisión de 0.1.64]. Una página sin facetas no basta
	 * para reintentar: el archivo vacío sin filtros omite el form a propósito, y
	 * reintentarlo costaba tres renders completos de más y siete segundos de
	 * página atenuada. Sólo se reintenta con la marca que el servidor pone cuando
	 * las facetas faltan porque el gate de publicación está cerrado.
	 *
	 * Y con disyuntor: si tras los tres reintentos sigue marcada, no es la ventana
	 * corta de un guardado sino un cierre largo, y cada clic de filtro costaría
	 * cuatro renders. Durante `FAIL_OPEN_PAUSA` se pinta lo que llega sin insistir.
	 */
	var FAIL_OPEN_MARCA = 'meta[name="akb-search-facets"][content="indisponible"]';
	var FAIL_OPEN_PAUSA = 60000;
	var failOpenHasta = 0;

	function reintentarFailOpen( doc, intento ) {
		if ( ! doc || ! doc.querySelector( FAIL_OPEN_MARCA ) ) {
			return false;
		}
		if ( intento >= RETRY_MAX ) {
			failOpenHasta = Date.now() + FAIL_OPEN_PAUSA;
			return false;
		}
		return Date.now() >= failOpenHasta;
	}

	function form() {
		return document.querySelector( FORM );
	}

	function isMobile() {
		return window.matchMedia( '(max-width: ' + MOBILE_MAX + 'px)' ).matches;
	}

	/** Batch = drawer abierto: acumular, no recargar por tap. */
	function isBatch() {
		return drawerOpen && isMobile();
	}

	/** Clave de catálogo deliberadamente ajena al hash. */
	function navigationKey( url ) {
		try {
			var parsed = new URL( url, location.href );
			return parsed.pathname + parsed.search;
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * La intención de historial cancela toda navegación PJAX pendiente. Además de abort(),
	 * desvincular inflight: un fetch ya resuelto puede ignorar el abort y go() debe descartarlo
	 * mediante mine !== inflight. Retirar akb-pjax-loading aquí, pues el finally de la navegación
	 * desvinculada sólo limpia cuando sigue siendo la vigente.
	 */
	function cancelInflight() {
		// Cancelar también el debounce de submitForm al navegar por historial. El timer pendiente de
		// un checkbox no debe empujar después el estado que el usuario acaba de abandonar.
		window.clearTimeout( debounce );
		// Y el aviso diferido de un PJAX que ya falló por la red (ver
		// avisarTrasFallo() en go()).
		soltarEspera();

		if ( ! inflight ) {
			return;
		}
		inflight.abort();
		if ( inflight.preCtl ) { inflight.preCtl.abort(); }
		inflight = null;
		vigilarSalida( false );
		window.clearTimeout( dimTimer );
		document.body.classList.remove( 'akb-pjax-loading' );
	}

	/*
	 * Un TypeError de fetch puede significar corte de red o cancelación de Safari por salida de
	 * página. No navegar automáticamente tras un fallo de red: location.assign() podría ganar al
	 * Atrás incluso diferido. Conservar la página y permitir Reintentar o el siguiente gesto
	 * nativo del catálogo. Los errores de servidor o respuestas ajenas al catálogo sí usan
	 * navegación nativa.
	 *
	 * La Navigation API, beforeunload o un gesto sin cancelar pueden anticipar la salida; pagehide
	 * la confirma. Safari de iPhone anterior a iOS 26.2 no aporta Navigation API y no despacha
	 * beforeunload (webkit.org/b/219102); Atrás a otro sitio puede carecer de señal previa. Por
	 * eso el aviso espera conservando atenuado y oyentes: ESPERA_SALIDA tras clic/submit de
	 * salida, ESPERA_SIN_CONEXION cuando navigator.onLine es false y ESPERA_RED en los demás
	 * casos. Si la salida tarda más que el plazo, el aviso puede aparecer aunque la navegación
	 * esté pendiente.
	 *
	 * Escuchar beforeunload sólo durante PJAX o la espera del aviso para respetar bfcache. Rearmar
	 * en cada go(): una salida anunciada, como mailto:, puede no confirmar navegación. Al
	 * confirmarse la salida, retirar aviso, anuncio y desplazamientos para no guardar un error en
	 * bfcache.
	 */
	var saliendo = false;
	// Un clic o un envío que se lleva la página (ver alGesto()).
	var pista = false;
	// El aviso de un fallo de red que espera a ver si la página se va, y lo que
	// hará al vencer (alGesto() puede alargar la espera).
	var esperaRed = 0;
	var avisoPendiente = null;
	// Hubo un fallo de red: el siguiente gesto del catálogo navega sin PJAX (ver
	// go()).
	var nativo = false;
	// Lo que el cliente pidió y no llegó: lo que carga «Reintentar».
	var urlFallida = '';
	// ESPERA_RED de 4s cubre un Atrás sin señal previa que tarda hasta ese plazo en confirmarse.
	// Un corte real con navigator.onLine=true asume esa demora adicional antes del aviso.
	var ESPERA_RED = 4000;
	// 1 s: sin conexión no hay página nueva que esperar; cubre con holgura a
	// `navigate`/`beforeunload` (≤ 60 ms tras el rechazo de un Atrás).
	var ESPERA_SIN_CONEXION = 1000;
	// 8 s: un enlace pulsado lleva a otra página casi seguro; sólo lo pagaría
	// uno que no navegue (una respuesta 204, una descarga sin `download`).
	var ESPERA_SALIDA = 8000;

	function alSalir() {
		saliendo = true;
		// Un aviso esperando ya no tiene a quién avisar: se retira, con el
		// atenuado, para que la página guardada en el bfcache vuelva limpia.
		if ( esperaRed ) {
			cancelInflight();
		}
	}

	// Navigation API: sólo cuenta una navegación a OTRO documento que no sea
	// una descarga. Las del mismo documento (un ancla, el pushState del propio
	// PJAX, un Atrás a una entrada del PJAX) no se llevan la página.
	function alNavegar( e ) {
		var destino = e && e.destination;
		if ( ! destino || destino.sameDocument || 'string' === typeof e.downloadRequest ) {
			return;
		}
		alSalir();
	}

	/*
	 * Detectar clics y envíos sin cancelar que abren otro documento en la misma pestaña. Es una
	 * señal temprana útil sin Navigation API: alarga la espera a ESPERA_SALIDA, pero sólo
	 * navigate, beforeunload y pagehide confirman salida y retiran el aviso.
	 *
	 * Escuchar en captura para cubrir sugerencias que cortan la burbuja y decidir en una tarea
	 * posterior, después de los handlers que pueden cancelar el gesto. Una microtarea no basta
	 * porque puede correr entre oyentes nativos. Si el fetch falla antes, alargar desde aquí la
	 * espera ya iniciada.
	 */
	function alGesto( e ) {
		var el = null;
		if ( 'submit' === e.type ) {
			el = e.target;
		} else if ( ! e.button && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey && ! e.altKey && e.target && e.target.closest ) {
			el = e.target.closest( 'a[href]' );
		}
		if ( ! el || '_self' !== ( el.getAttribute( 'target' ) || '_self' ) || el.hasAttribute( 'download' ) ) {
			return;
		}
		if ( 'submit' !== e.type && ! otroDocumento( el.getAttribute( 'href' ) ) ) {
			return;
		}
		// El gesto es del PJAX que vuela (o espera) ahora: si para entonces
		// terminó o lo reemplazó otro, ya no hay a quién avisar.
		var suyo = inflight;
		window.setTimeout( function () {
			if ( suyo !== inflight || e.defaultPrevented ) {
				return;
			}
			pista = true;
			if ( esperaRed ) {
				window.clearTimeout( esperaRed );
				esperaRed = window.setTimeout( avisoPendiente, ESPERA_SALIDA );
			}
		}, 0 );
	}

	// ¿El enlace abre otro documento? No un mailto:/tel:/javascript:, ni un
	// ancla de esta misma página.
	function otroDocumento( href ) {
		try {
			var destino = new URL( href, location.href );
			var aqui = new URL( location.href );
			if ( ! /^https?:$/.test( destino.protocol ) ) {
				return false;
			}
			return ! ( destino.hash && destino.origin === aqui.origin && destino.pathname + destino.search === aqui.pathname + aqui.search );
		} catch ( err ) {
			return false;
		}
	}

	/**
	 * Cuánto espera el aviso de un fallo de red a que la salida se confirme
	 * (ver la nota de «La página se está yendo», más arriba).
	 *
	 * @return {number} ms.
	 */
	function esperaDelAviso() {
		if ( pista ) {
			return ESPERA_SALIDA;
		}
		return window.navigator && false === window.navigator.onLine ? ESPERA_SIN_CONEXION : ESPERA_RED;
	}

	/** Retira el aviso que esperaba (una navegación nueva o un Atrás lo dejan sin objeto). */
	function soltarEspera() {
		window.clearTimeout( esperaRed );
		esperaRed = 0;
		avisoPendiente = null;
	}

	function vigilarSalida( activa ) {
		var metodo = activa ? 'addEventListener' : 'removeEventListener';
		if ( activa ) {
			saliendo = false;
			pista = false;
		}
		window[ metodo ]( 'beforeunload', alSalir );
		window[ metodo ]( 'pagehide', alSalir );
		window[ metodo ]( 'click', alGesto, true );
		window[ metodo ]( 'submit', alGesto, true );
		if ( window.navigation && window.navigation[ metodo ] ) {
			window.navigation[ metodo ]( 'navigate', alNavegar );
		}
	}

	/**
	 * Navega por pjax a una URL: fetch, swap de los bloques, pushState.
	 *
	 * @param {string}      url
	 * @param {boolean}     push   Empujar a history (false en popstate).
	 * @param {Object|null} origen Control que pidió la navegación y que debe
	 *                             conservar el foco (ver controlOrigin()); sin
	 *                             él, el foco va a la rejilla.
	 * @param {boolean}     lote   Aplica lo marcado en el cajón móvil (ver
	 *                             avisarFalloDeRed()).
	 */
	function go( url, push, origen, lote ) {
		// Tras un fallo de red (ver la nota de «La página se está yendo», más
		// arriba), este gesto navega como sin JS. Es del cliente y es el más
		// reciente: no le pisa nada. En popstate `url` es `location.href`:
		// recarga lo que la barra ya dice.
		if ( nativo ) {
			window.location.assign( url );
			return;
		}
		// Una navegación nueva deja sin objeto el aviso de la anterior.
		soltarEspera();
		// Dónde estaba el foco al pedirla y cuántas teclas iban: si nada de
		// eso cambió cuando haya que avisar de un fallo, el aviso se puede
		// llevar la vista y el foco (ver avisarFalloDeRed()).
		var pedido = { foco: document.activeElement, teclas: teclas };
		if ( inflight ) {
			inflight.abort();
			// El prefetch consumido corre con SU controller: abortarlo o la
			// request zombie sigue en el server (gate, punto c).
			if ( inflight.preCtl ) { inflight.preCtl.abort(); }
		}
		// Carrera (m8): abort() no cancela un fetch ya resolviendo; comparar
		// `mine` vs el vigente o pisa la request nueva.
		var mine = new AbortController();
		inflight = mine;
		vigilarSalida( true );
		// Retrasar el atenuado unos 120ms según stalledSearchDelay para evitar parpadeo en un prefetch
		// caliente. Mantener un solo timer y limpiarlo al superar go() o en el finally de la
		// navegación ganadora.
		window.clearTimeout( dimTimer );
		dimTimer = window.setTimeout( function () {
			document.body.classList.add( 'akb-pjax-loading' );
		}, 120 );

		// Slot de prefetch: consumir si matchea fresco; si no, abortarlo.
		var hit = pre && pre.url === url && Date.now() - pre.t < 5000 ? pre : null;
		if ( pre && ! hit ) { pre.ctl.abort(); }
		pre = null;
		if ( hit ) { mine.preCtl = hit.ctl; }

		function pedir() {
			return fetch( url, { signal: mine.signal, headers: { 'X-Requested-With': 'akb-pjax' }, credentials: 'same-origin', priority: 'high' } )
				.then( contratoHttp )
				.then( function ( html ) {
					AkbCatalogCache.set( url, html );
					return html;
				} );
		}

		function contratoHttp( r ) {
				// Validar contrato HTTP y forma HTML antes de tocar el DOM. Un 5xx o challenge puede devolver
				// HTML válido sin los bloques de catálogo y no debe vaciar los existentes. Aceptar el 404
				// legítimo de FilteredUrlsPolicy para colecciones filtradas sin resultados: conserva
				// empty-state, facetas y chips. Un 404 ajeno sigue rechazado por la forma exigida: grilla,
				// empty-state o facetas.
				if ( ! r.ok && 404 !== r.status ) { throw new Error( 'pjax_http_' + r.status ); }
				var ct = r.headers.get( 'content-type' ) || '';
				if ( ct.indexOf( 'text/html' ) === -1 ) { throw new Error( 'pjax_ct' ); }
				return r.text();
		}

		function aplicar( html, intento ) {
				if ( mine !== inflight ) {
					return; // una request más nueva ganó.
				}
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				// …y contrato de FORMA: un 200 «raro» (login, challenge, error
				// servido con 200) parsea sin fallar. Uno de los tres SIEMPRE
				// existe en una respuesta de catálogo: la grilla, el empty-state
				// (0 hits) o el chrome de facetas. Ninguno = no es catálogo.
				if ( ! doc.querySelector( GRID ) && ! doc.querySelector( '.akb-empty-state' ) && ! doc.querySelector( '.akb-facets' ) ) {
					throw new Error( 'pjax_shape' );
				}
				// Fail-open MARCADO por el servidor [0.1.64]: la ventana de guardado
				// sirve el catálogo sin filtrar y sin facetas para no caerse, y
				// pintarlo borraba las facetas y mostraba productos que no respetan
				// el filtro elegido. Se vuelve a pedir con el mismo backoff; tras el
				// último intento se pinta lo que llegó, como siempre.
				if ( reintentarFailOpen( doc, intento ) ) {
					return esperar( RETRY_BASE * Math.pow( 2, intento ), mine.signal )
						.then( pedir )
						.then( function ( siguiente ) { return aplicar( siguiente, intento + 1 ); } );
				}
				var facetsState = captureFacetsState();
				document.title = doc.title;
				SWAP.forEach( function ( sel ) { swap( doc, sel ); } );
				restoreFacetsState( facetsState );
				bindForm();
				applyCollapse();

				if ( push ) {
					history.pushState( { akb: true }, '', url );
				}
				renderedLocation = navigationKey( url ) || location.pathname + location.search;
				syncSearchInputs();
				announce();
				// El foco se decide ya; el desplazamiento al total, después de
				// avisar al tema (ver revealResults()).
				var alTotal = ! keepFocus( origen ) && focusGrid();
				// El DOM nuevo bajo el puntero QUIETO dispara un pointerover
				// sintético que armaría un prefetch de deshacer sin intención
				// real (cazado por probe B1; WebKit lo emite tarde — la
				// supresión es por MOVIMIENTO, no por reloj). El primer move
				// se RE-EVALÚA como hover: pointerover precede a pointermove,
				// así que un hover directo al label caería en la supresión.
				postSwap = true;
				armPostSwap();
				// main.js refresca el badge (URL ya vigente).
				document.dispatchEvent( new Event( 'akb:pjax' ) );
				if ( alTotal ) {
					revealResults();
				}
		}

		// Cierra ESTA navegación si sigue siendo la vigente: quita el atenuado y
		// retira los oyentes de salida.
		function terminar() {
			if ( mine === inflight ) {
				window.clearTimeout( dimTimer );
				document.body.classList.remove( 'akb-pjax-loading' );
				inflight = null;
				vigilarSalida( false );
			}
		}

		// Fallo del SERVIDOR (5xx, challenge, respuesta que no es catálogo):
		// degradamos a navegación nativa, que es el fallback sin JS documentado
		// en la cabecera; la red no pasa por aquí (ver avisarTrasFallo()). En
		// popstate el browser YA movió la barra de direcciones, así que
		// quedarnos callados dejaba la URL mintiendo sobre lo que se está
		// mostrando; assign() la vuelve a hacer verdad (en popstate `url` es
		// `location.href`).
		function respaldoNativo() {
			terminar();
			window.location.assign( url );
		}

		/*
		 * Ante TypeError, esperar esperaDelAviso() con atenuado y oyentes activos sin navegar
		 * automáticamente. Puede ser corte de red o salida de Safari aún sin señal. alSalir(),
		 * cancelInflight(), un go() nuevo o popstate retiran la espera; alGesto() la prolonga para una
		 * salida probable. Mostrar avisarFalloDeRed() sólo si esta navegación sigue vigente.
		 */
		function avisarTrasFallo() {
			avisoPendiente = function () {
				esperaRed = 0;
				avisoPendiente = null;
				terminar();
				avisarFalloDeRed( url, origen, lote, pedido );
			};
			esperaRed = window.setTimeout( avisoPendiente, esperaDelAviso() );
		}

		( hit ? hit.promise : pedir() )
			.then( function ( html ) { return aplicar( html, 0 ); } )
			.then( terminar, function ( err ) {
				// Abort (tipeo/go más nuevo) = no-op: el ganador ya pinta.
				if ( mine !== inflight ) { return; }
				// El cliente se va a otra página: su navegación gana (ver
				// vigilarSalida()).
				if ( ( err && 'AbortError' === err.name ) || saliendo ) {
					terminar();
					return;
				}
				// Por nombre y no por instanceof: el rechazo de fetch puede
				// venir de otro realm. Los Error propios (pjax_http_*, pjax_ct,
				// pjax_shape) son respuestas del servidor: al respaldo ya.
				if ( err && 'TypeError' === err.name ) {
					avisarTrasFallo();
					return;
				}
				respaldoNativo();
			} );
	}

	/**
	 * Un fallo de red conserva lo pintado y comunica el aviso configurable con Reintentar y región
	 * viva. En el cajón móvil, mantener la selección pendiente para que «Ver N» o cerrar la
	 * aplique sin PJAX; fuera del cajón, restaurar los controles al estado mostrado.
	 *
	 * El aviso pertenece a .woocommerce-notices-wrapper en la barra de resultados, cuyo layout
	 * controla el tema. Si el usuario permanece quieto y ningún control conserva el foco, revelar
	 * el aviso; sin puntero, enfocar Reintentar. Si conserva el foco en una casilla/orden o siguió
	 * usando el teclado durante la espera, no mover foco ni scroll y flotar una copia visible
	 * junto al control si hace falta.
	 *
	 * @param {string}      url    Lo que el cliente pidió.
	 * @param {Object|null} origen De controlOrigin(): null con el puntero.
	 * @param {boolean}     lote   Venía del «Ver N» del cajón móvil.
	 * @param {Object}      pedido {foco, teclas} al pedirlo (ver go()).
	 */
	function avisarFalloDeRed( url, origen, lote, pedido ) {
		nativo = true;
		urlFallida = url;
		if ( lote ) {
			pendingApply = true;
		} else {
			volverALoPintado();
		}
		var molde = document.querySelector( AVISO_RED );
		var bloque = molde && molde.content ? molde.content.firstElementChild : null;
		if ( ! bloque ) {
			return;
		}
		bloque = bloque.cloneNode( true );
		molde.replaceWith( bloque );
		announce( bloque.textContent.trim() );
		var aviso = bloque.querySelector( '.akb-aviso-red' ) || bloque;
		var ahora = document.activeElement;
		var siguio = teclas !== pedido.teclas || !! ( ahora && ahora !== pedido.foco && ahora !== document.body );
		if ( ! origen && ( 'puntero' === modality || ! siguio ) ) {
			aviso.scrollIntoView( { block: 'start', behavior: scrollBehavior() } );
			if ( 'puntero' !== modality ) {
				// Sin corchetes en el texto del panel no hay botón: el aviso mismo.
				var destino = aviso.querySelector( '[data-akb-reintentar]' );
				if ( ! destino ) {
					destino = aviso;
					destino.setAttribute( 'tabindex', '-1' );
				}
				destino.focus( { preventScroll: true } );
			}
			return;
		}
		if ( ! aLaVista( aviso ) ) {
			flotar( aviso, ahora );
		}
	}

	/**
	 * El aviso o su enlace Reintentar debe caber en la ventana visible sin quedar tapado por la
	 * cabecera fija.
	 *
	 * @param {Element} aviso El aviso de la barra de resultados.
	 * @return {boolean}
	 */
	function aLaVista( aviso ) {
		var blanco = aviso.querySelector( '[data-akb-reintentar]' ) || aviso;
		var caja = blanco.getBoundingClientRect();
		var encima = document.elementFromPoint( caja.left + caja.width / 2, caja.top + caja.height / 2 );
		return !! encima && blanco.contains( encima );
	}

	/**
	 * Mostrar la copia junto al foco sin desplazar la página ni ocultar el control: debajo si
	 * cabe, encima si no. Seguir scroll/resize y ocultarla cuando el ancla no sea visible o tenga
	 * sugerencias abiertas.
	 *
	 * Insertar después del foco en el DOM y usar popover para evitar recorte de ancestros; así
	 * Reintentar es el siguiente Tab (Opción+Tab para botones en Safari). Sin popover, al final de
	 * body se conserva visibilidad pero no la cercanía de Tab. Retirar ante la siguiente
	 * interacción fuera del aviso y su control, Escape o salida; conservar el aviso original. La
	 * copia no añade región viva para evitar anuncios duplicados.
	 *
	 * @param {Element}      aviso El aviso de la barra de resultados.
	 * @param {Element|null} foco  Donde está el foco del cliente.
	 */
	function flotar( aviso, foco ) {
		var copia = document.createElement( 'div' );
		copia.className = 'akb-aviso-flotante';
		copia.setAttribute( 'data-akb-aviso-flotante', '' );
		copia.appendChild( aviso.cloneNode( true ) );
		var ancla = foco && foco !== document.body && document.body.contains( foco ) ? foco.closest( 'label' ) || foco : null;
		if ( ancla && 'function' === typeof copia.showPopover ) {
			copia.setAttribute( 'popover', 'manual' );
			ancla.after( copia );
			copia.showPopover();
		} else {
			document.body.appendChild( copia );
		}
		colocar( copia, ancla, foco );
		// Mantener la copia fija en la ventana y reposicionarla al mover su control, hacer scroll o
		// resize y abrir/cerrar sugerencias. Escuchar scroll en captura para cubrir listas internas
		// cuyos eventos no burbujean hasta window.
		var cuadro = 0;
		var raf = window.requestAnimationFrame || function ( cb ) { return window.setTimeout( cb, 16 ); };
		function recolocar() {
			if ( ! cuadro ) {
				cuadro = raf( function () {
					cuadro = 0;
					colocar( copia, ancla, foco );
				} );
			}
		}
		var CAPTURA = { capture: true, passive: true };
		window.addEventListener( 'scroll', recolocar, CAPTURA );
		window.addEventListener( 'resize', recolocar );
		var sugerencias = ancla && 'combobox' === foco.getAttribute( 'role' ) ? new MutationObserver( recolocar ) : null;
		if ( sugerencias ) {
			sugerencias.observe( foco, { attributes: true, attributeFilter: [ 'aria-expanded' ] } );
		}

		var TIPOS = [ 'focusin', 'pointerdown', 'keydown' ];
		function retirar( e ) {
			if ( 'keydown' === e.type && 'Escape' !== e.key ) {
				return;
			}
			// Tabular hasta «Reintentar», o volver a su control, no es irse.
			if ( ( 'focusin' === e.type || 'pointerdown' === e.type ) && ( copia.contains( e.target ) || ( ancla && ancla.contains( e.target ) ) ) ) {
				return;
			}
			var tenia = copia.contains( document.activeElement );
			copia.remove();
			TIPOS.forEach( function ( t ) { document.removeEventListener( t, retirar, true ); } );
			window.removeEventListener( 'pagehide', retirar );
			window.removeEventListener( 'scroll', recolocar, CAPTURA );
			window.removeEventListener( 'resize', recolocar );
			if ( sugerencias ) {
				sugerencias.disconnect();
			}
			// Escape en «Reintentar»: el foco vuelve a donde estaba, sin mover la página.
			if ( tenia && ancla ) {
				foco.focus( { preventScroll: true } );
			}
		}
		TIPOS.forEach( function ( t ) { document.addEventListener( t, retirar, true ); } );
		window.addEventListener( 'pagehide', retirar );
	}

	/**
	 * Colocar junto a lo visible del control, debajo si cabe y encima si no; respetar viewport y
	 * --akb-franja-inferior medida por el tema. Sin ancla, usar el pie; un control fuera del
	 * viewport aproxima la copia al borde más cercano.
	 *
	 * Calcular con getBoundingClientRect() y corregir tras medir: WebKit puede desfasar
	 * position:fixed respecto al viewport visible al desplazar o hacer pinch zoom. Ocultar si el
	 * contenedor recorta totalmente el ancla o si un buscador tiene sugerencias abiertas; volver
	 * al recuperarse el ancla. Conservarla visible si contiene el foco para no expulsarlo.
	 *
	 * @param {Element}      copia La copia, ya pintada (se mide).
	 * @param {Element|null} ancla Su control.
	 * @param {Element|null} foco  El foco del cliente (el control, o lo que
	 *                             está dentro de su etiqueta).
	 */
	function colocar( copia, ancla, foco ) {
		var MARGEN = 16;
		var HUECO = 8;
		var caja = ancla ? loQueSeVe( ancla ) : null;
		var oculta = !! ancla && ! copia.contains( document.activeElement ) &&
			( ! caja || ( 'combobox' === foco.getAttribute( 'role' ) && 'true' === foco.getAttribute( 'aria-expanded' ) ) );
		copia.style.visibility = oculta ? 'hidden' : '';
		if ( oculta ) {
			return;
		}
		var raiz = document.documentElement;
		var franja = parseFloat( window.getComputedStyle( raiz ).getPropertyValue( '--akb-franja-inferior' ) ) || 0;
		var alto = copia.offsetHeight;
		// El `top` que la deja al pie, sobre la franja.
		var alPie = raiz.clientHeight - franja - MARGEN - alto;
		var top = alPie;
		var left = MARGEN;
		if ( ancla ) {
			caja = caja || ancla.getBoundingClientRect();
			top = caja.bottom + HUECO <= alPie ? caja.bottom + HUECO : caja.top - HUECO - alto;
			left = caja.left;
		}
		top = Math.round( Math.max( MARGEN, Math.min( top, alPie ) ) );
		left = Math.round( Math.max( MARGEN, Math.min( left, raiz.clientWidth - copia.offsetWidth - MARGEN ) ) );
		copia.style.top = top + 'px';
		copia.style.left = left + 'px';
		var quedo = copia.getBoundingClientRect();
		copia.style.top = Math.round( 2 * top - quedo.top ) + 'px';
		copia.style.left = Math.round( 2 * left - quedo.left ) + 'px';
	}

	/**
	 * Lo que se ve de `el`: su caja recortada por los ancestros que recortan
	 * lo que se les sale (`overflow` distinto de `visible`: la lista de una
	 * faceta, el cajón), hasta <body>. La ventana la resuelve colocar().
	 *
	 * @param {Element} el
	 * @return {{top: number, bottom: number, left: number, right: number}|null}
	 *         Null si alguno lo esconde del todo.
	 */
	function loQueSeVe( el ) {
		var r = el.getBoundingClientRect();
		var caja = { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
		for ( var a = el.parentElement; a && a !== document.body; a = a.parentElement ) {
			var estilo = window.getComputedStyle( a );
			if ( 'visible' === estilo.overflowX && 'visible' === estilo.overflowY ) {
				continue;
			}
			var ra = a.getBoundingClientRect();
			var top = ra.top + a.clientTop;
			var left = ra.left + a.clientLeft;
			caja.top = Math.max( caja.top, top );
			caja.bottom = Math.min( caja.bottom, top + a.clientHeight );
			caja.left = Math.max( caja.left, left );
			caja.right = Math.min( caja.right, left + a.clientWidth );
		}
		return caja.bottom > caja.top && caja.right > caja.left ? caja : null;
	}

	/**
	 * Devuelve los controles del catálogo a lo que está pintado: lo que el
	 * servidor marcó para `renderedLocation` (`defaultChecked`,
	 * `defaultSelected`) y la búsqueda que un chip o «Limpiar todo» apartó.
	 */
	function volverALoPintado() {
		var f = form();
		if ( f ) {
			f.querySelectorAll( 'input' ).forEach( function ( i ) {
				var tipo = i.getAttribute( 'type' );
				if ( 'checkbox' === tipo || 'radio' === tipo ) {
					i.checked = i.defaultChecked;
				} else if ( 'hidden' === tipo ) {
					i.disabled = false;
				}
			} );
		}
		document.querySelectorAll( '.woocommerce-ordering option' ).forEach( function ( o ) {
			o.selected = o.defaultSelected;
		} );
	}

	/**
	 * Reemplaza `sel` por el de la respuesta: reemplazar, borrar (solo DOM),
	 * o INSERTAR (solo respuesta, ej. pager que reaparece) con anchor.
	 */
	function swap( doc, sel ) {
		var next = pick( doc, sel );
		var cur  = pick( document, sel );
		if ( cur && next ) {
			cur.replaceWith( next );
		} else if ( cur && ! next ) {
			cur.remove();
		} else if ( ! cur && next ) {
			insertFresh( sel, next );
		}
	}

	/**
	 * El bloque `sel` de un documento. La rejilla es la del CATÁLOGO: la primera
	 * `ul.products` que no vive dentro del aviso de «sin resultados». Con un
	 * `querySelector` a secas, en cero resultados la venta cruzada del aviso
	 * pasaba por la rejilla: el swap la sacaba del aviso al sitio del catálogo
	 * y, al volver a haber resultados, la barra de orden se insertaba DENTRO
	 * del aviso y moría con él.
	 */
	function pick( root, sel ) {
		return GRID === sel ? mainGrid( root ) : root.querySelector( sel );
	}

	function mainGrid( root ) {
		// Camino corto: con resultados, la primera rejilla ya es la del
		// catálogo (el aviso va vacío) y no hace falta recorrer nada.
		var first = root.querySelector( GRID );
		if ( ! first || ! first.closest( EMPTY ) ) {
			return first;
		}
		var grids = root.querySelectorAll( GRID );
		for ( var i = 1; i < grids.length; i++ ) {
			if ( ! grids[ i ].closest( EMPTY ) ) {
				return grids[ i ];
			}
		}
		return null;
	}

	/**
	 * El header queda fuera del swap. Refleja `_buscar` desde la URL ya confirmada
	 * y sincroniza la X de ambos inputs después de avanzar, limpiar o volver.
	 */
	function syncSearchInputs() {
		var value = '';
		try {
			value = new URL( location.href ).searchParams.get( '_buscar' ) || '';
		} catch ( err ) {
			value = '';
		}

		document.querySelectorAll( '#akb-search-input, #akb-mobile-search-input' ).forEach( function ( input ) {
			input.value = value;
			var searchForm = input.closest( 'form' );
			var clear = searchForm && searchForm.querySelector( '.akibara-search__clear' );
			if ( clear ) {
				clear.hidden = ! value;
			}
		} );
	}

	/**
	 * WooCommerce liga el ordering solo a los forms presentes en DOMContentLoaded.
	 * El listener delegado en captura también cubre forms que nacen en 0→N y
	 * mantiene el ordenamiento dentro del mismo flujo PJAX.
	 */
	document.addEventListener( 'change', function ( e ) {
		var select = e.target && e.target.closest
			? e.target.closest( '.woocommerce-ordering select.orderby' )
			: null;
		if ( ! select ) {
			return;
		}

		var ordering = select.closest( 'form' );
		if ( ! ordering ) {
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		var params = new URLSearchParams( new FormData( ordering ) );
		var action = ordering.getAttribute( 'action' ) || location.pathname;
		var target = new URL( action, location.href );
		target.search = params.toString();
		go( target.toString(), true, controlOrigin( select, '.woocommerce-ordering' ) );
	}, true );

	/**
	 * Inserta un bloque nuevo (INSERTAR del swap). Caso crítico 0→N (major m2):
	 * en 0 hits WC no emite `ul.products` y el grid nuevo, sin ancla, se
	 * descartaba. Anclamos al chrome de facetas (count/chips/empty-state), que
	 * SIEMPRE existe — también en 0 hits vía woocommerce_no_products_found.
	 */
	function insertFresh( sel, next ) {
		var grid = mainGrid( document );
		if ( '.woocommerce-pagination' === sel && grid ) {
			grid.after( next ); // el pager va después del grid.
			return;
		}
		if ( '.term-description' === sel ) {
			var title = document.querySelector( '.woocommerce-products-header__title' ) || document.querySelector( '.page-title' );
			if ( title && title.parentNode ) {
				title.after( next );
				return;
			}
			var header = document.querySelector( '.woocommerce-products-header' );
			if ( header ) {
				var routeUpdated = header.querySelector( '.akb-route-updated' );
				if ( routeUpdated ) {
					header.insertBefore( next, routeUpdated );
				} else {
					header.appendChild( next );
				}
				return;
			}
		}
		if ( '.akb-route-updated' === sel ) {
			var desc = document.querySelector( '.term-description' );
			if ( desc && desc.parentNode ) {
				desc.after( next );
				return;
			}
			var header = document.querySelector( '.woocommerce-products-header' );
			if ( header ) {
				header.appendChild( next );
				return;
			}
			var titleFallback = document.querySelector( '.woocommerce-products-header__title' ) || document.querySelector( '.page-title' );
			if ( titleFallback && titleFallback.parentNode ) {
				titleFallback.after( next );
				return;
			}
		}
		if ( GRID !== sel && grid ) {
			grid.parentNode.insertBefore( next, grid ); // el resto, antes del grid.
			return;
		}
		// Fallback: el grid falta (0→N) o reaparece. Insertar tras el chrome.
		var anchor = document.querySelector( '.akb-empty-state' ) ||
			document.querySelector( '.akb-facet-chips' ) ||
			document.querySelector( '.akb-result-count' ) ||
			document.querySelector( '.woocommerce-result-count' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( next, anchor.nextSibling );
		} else if ( grid ) {
			grid.parentNode.insertBefore( next, grid );
		}
	}

	/**
	 * Anuncia el total a lectores: .akb-result-count se REEMPLAZA en cada swap
	 * (nodo nuevo = no se anuncia) → escribimos en una región live PERSISTENTE.
	 *
	 * @param {string} [texto] Otro anuncio en vez del total (el aviso de un
	 *                         fallo de red, ver avisarFalloDeRed()).
	 */
	function announce( texto ) {
		var live = document.getElementById( 'akb-live-region' );
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.id = 'akb-live-region';
			live.className = 'screen-reader-text';
			live.setAttribute( 'aria-live', 'polite' );
			live.setAttribute( 'role', 'status' );
			document.body.appendChild( live );
		}
		if ( undefined === texto ) {
			// Sólo el total: si la línea trae un extra al lado (el atajo «Último
			// tomo» de las series), el lector no tiene por qué oírlo tras cada
			// filtro.
			var count = document.querySelector( '.akb-result-count__total' ) ||
				document.querySelector( '.akb-result-count' );
			texto = count ? count.textContent.trim() : '';
		}
		// Vaciar + setear al tick siguiente: el mismo texto no se re-anuncia.
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = texto;
		}, 60 );
	}

	/**
	 * Lleva el foco a la rejilla, sin desplazar: eso lo hace revealResults().
	 *
	 * @return {boolean} true si había rejilla.
	 */
	function focusGrid() {
		var grid = mainGrid( document );
		if ( ! grid ) {
			return false;
		}
		grid.setAttribute( 'tabindex', '-1' );
		grid.focus( { preventScroll: true } );
		return true;
	}

	/**
	 * Revelar resultados en el requestAnimationFrame posterior a akb:pjax. El tema agenda
	 * ScrollTrigger.refresh() en su propio frame y puede restaurar el scroll; agendar después
	 * evita que anule el desplazamiento suave.
	 */
	function revealResults() {
		var raf = window.requestAnimationFrame || function ( cb ) { return window.setTimeout( cb, 16 ); };
		raf( function () {
			// Ancla = inicio de resultados (no la grilla): el header sticky la tapaba.
			var ancla = document.querySelector( '.akb-result-count' ) || mainGrid( document );
			if ( ancla ) {
				ancla.scrollIntoView( { block: 'start', behavior: scrollBehavior() } );
			}
		} );
	}

	/**
	 * Seleccionar auto con movimiento reducido y smooth en caso contrario. Un smooth explícito de
	 * JS no queda anulado por scroll-behavior:auto !important del CSS.
	 *
	 * @return {string} 'auto' o 'smooth'.
	 */
	function scrollBehavior() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth';
	}

	/**
	 * Identificar el control que solicitó la navegación para restaurar su foco tras el swap. Con
	 * teclado, cambiar una casilla u orden conserva el contexto y permite añadir filtros sin
	 * recorrer la grilla. Con puntero, revelar el inicio de resultados.
	 *
	 * @param {Element} el     Control que cambió.
	 * @param {string}  ambito Selector del bloque que lo contiene.
	 * @return {Object|null} {ambito, tag, name, value} o null (foco a la rejilla).
	 */
	function controlOrigin( el, ambito ) {
		if ( 'puntero' === modality || ! el || ! el.getAttribute || ! el.getAttribute( 'name' ) ) {
			return null;
		}
		return {
			ambito: ambito,
			tag: String( el.tagName || '' ).toLowerCase(),
			name: el.getAttribute( 'name' ),
			value: 'select' === String( el.tagName || '' ).toLowerCase() ? null : el.getAttribute( 'value' )
		};
	}

	/**
	 * Enfoca, en el DOM ya intercambiado, el control equivalente al que pidió la
	 * navegación. Sin `preventScroll`: si el swap lo movió fuera de la vista, el
	 * navegador lo trae; si se ve, no se mueve nada. El total lo anuncia
	 * announce() en la región viva.
	 *
	 * @param {Object|null} origen De controlOrigin().
	 * @return {boolean} true si el foco quedó en el control; false si el
	 *                   control ya no existe (o no admite foco) y hay que ir a
	 *                   la rejilla.
	 */
	function keepFocus( origen ) {
		if ( ! origen ) {
			return false;
		}
		var root = document.querySelector( origen.ambito );
		if ( ! root ) {
			return false;
		}
		var candidatos = root.querySelectorAll( origen.tag );
		for ( var i = 0; i < candidatos.length; i++ ) {
			var el = candidatos[ i ];
			if ( el.getAttribute( 'name' ) !== origen.name || ( null !== origen.value && el.getAttribute( 'value' ) !== origen.value ) ) {
				continue;
			}
			if ( el.disabled ) {
				return false;
			}
			el.focus();
			return document.activeElement === el;
		}
		return false;
	}

	/**
	 * Serializa el form a URL con la gramática FacetWP: multi-valor por COMA
	 * (`?_categoria=a,b`), no array — paridad bookmarks/SEO. El server acepta
	 * ambas; el JS emite la limpia.
	 */
	function formUrl() {
		var f = form();
		if ( ! f ) {
			return null;
		}
		var map = {};
		new FormData( f ).forEach( function ( v, k ) {
			if ( '' === v ) {
				return;
			}
			var key = k.replace( /\[\]$/, '' );
			( map[ key ] = map[ key ] || [] ).push( v );
		} );
		var qs = new URLSearchParams();
		Object.keys( map ).forEach( function ( k ) {
			qs.append( k, map[ k ].join( ',' ) );
		} );
		var base = f.getAttribute( 'action' ) || location.pathname;
		var str  = qs.toString();
		return str ? base + '?' + str : base;
	}

	/**
	 * Derivar la URL desde la barra de direcciones cuando falta data-akb-facets: los archivos de
	 * serie/autor pueden tener layout sin sidebar, pero conservan chips y Limpiar todo.
	 *
	 * Reproducir formUrl(): conservar parámetros _* unidos por comas y orderby; descartar el
	 * resto. Quitar un chip no debe restablecer el orden ni bloquear el gesto por falta de
	 * formulario.
	 *
	 * @param {string|null} facet      Faceta a quitar; null quita todas.
	 * @param {string|null} value      Valor a quitar; null quita la faceta entera
	 *                                 (el chip `buscar` no es multivalor).
	 * @param {boolean}     keepSearch Con `facet` null, conservar `_buscar`: la
	 *                                 salida del aviso de «filtros» (ver el
	 *                                 handler de `[data-akb-clear]`).
	 * @return {string} URL destino.
	 */
	function urlWithout( facet, value, keepSearch ) {
		var qs = new URLSearchParams();
		new URLSearchParams( location.search ).forEach( function ( v, k ) {
			if ( '' === v ) {
				return;
			}
			if ( 'orderby' === k || ( keepSearch && '_buscar' === k ) ) {
				qs.append( k, v );
				return;
			}
			if ( '_' !== k.charAt( 0 ) || null === facet ) {
				return;
			}
			if ( k !== '_' + facet ) {
				qs.append( k, v );
				return;
			}
			if ( null === value ) {
				return;
			}
			var kept = v.split( ',' ).filter( function ( item ) {
				return item !== value;
			} );
			if ( kept.length ) {
				qs.append( k, kept.join( ',' ) );
			}
		} );
		var str = qs.toString();
		return str ? location.pathname + '?' + str : location.pathname;
	}

	function normalizeFacetText( value ) {
		var text = String( value || '' ).toLowerCase();
		return typeof text.normalize === 'function'
			? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: text;
	}

	function setFacetStatus( section, text ) {
		var status = section.querySelector( '[data-akb-facet-status]' );
		if ( status ) {
			status.textContent = text;
		}
	}

	/**
	 * Filtra la lista ya disponible. Mientras la lista completa se carga, no
	 * anuncia un falso «sin coincidencias» basado solo en las destacadas.
	 */
	function filterLargeFacet( section, value ) {
		var q      = normalizeFacetText( value.trim() );
		var loaded = 'true' === section.getAttribute( 'data-akb-facet-loaded' );
		var total  = Number( section.getAttribute( 'data-akb-facet-total' ) || 0 );
		var shown  = 0;

		section.querySelectorAll( '.akb-facet-item' ).forEach( function ( item ) {
			var label = item.querySelector( '.akb-facet-label' );
			var match = ! q || ( label && normalizeFacetText( label.textContent ).indexOf( q ) !== -1 );
			item.hidden = ! match;
			if ( match ) { shown++; }
		} );

		var empty = section.querySelector( '.akb-facet-noresults' );
		if ( ! empty ) {
			empty = document.createElement( 'li' );
			empty.className = 'akb-facet-noresults';
			empty.textContent = 'Sin coincidencias';
			var list = section.querySelector( '.akb-facet-list' );
			if ( list ) { list.appendChild( empty ); }
		}
		empty.hidden = shown > 0 || ( q && ! loaded );

		if ( q && ! loaded ) {
			setFacetStatus( section, 'Buscando en ' + total.toLocaleString( 'es-CL' ) + ' opciones…' );
		} else if ( q ) {
			setFacetStatus( section, shown.toLocaleString( 'es-CL' ) + ( 1 === shown ? ' coincidencia.' : ' coincidencias.' ) );
		} else if ( loaded ) {
			setFacetStatus( section, total.toLocaleString( 'es-CL' ) + ' opciones disponibles.' );
		} else {
			setFacetStatus( section, 'Mostrando ' + shown.toLocaleString( 'es-CL' ) + ' destacadas de ' + total.toLocaleString( 'es-CL' ) + '.' );
		}
	}

	function findFacetSection( root, name ) {
		return Array.prototype.find.call(
			root.querySelectorAll( '.akb-facet[data-facet]' ),
			function ( item ) { return item.getAttribute( 'data-facet' ) === name; }
		) || null;
	}

	/**
	 * Captura el estado efímero del sidebar antes de un swap PJAX:
	 * - Texto escrito en el input de filtrado rápido de autor/serie
	 * - Foco activo y selección de texto
	 * - Posición de scroll de las listas .akb-facet-list
	 */
	function captureFacetsState() {
		var state = { searches: {}, scrolls: {}, focusedSearch: null };
		var f = form();
		if ( ! f ) { return state; }

		f.querySelectorAll( '.akb-facet' ).forEach( function ( section ) {
			var name = section.getAttribute( 'data-facet' );
			var search = section.querySelector( '[data-akb-facet-search]' );
			if ( name && search && search.value ) {
				state.searches[ name ] = search.value;
			}
			if ( search && document.activeElement === search ) {
				state.focusedSearch = {
					facet: name,
					start: typeof search.selectionStart === 'number' ? search.selectionStart : null,
					end: typeof search.selectionEnd === 'number' ? search.selectionEnd : null
				};
			}
			var list = section.querySelector( '.akb-facet-list' );
			if ( name && list && list.scrollTop > 0 ) {
				state.scrolls[ name ] = list.scrollTop;
			}
		} );
		return state;
	}

	/**
	 * Restaura el estado efímero del sidebar tras el swap PJAX:
	 * re-aplica el texto en los inputs, re-filtra la lista con filterLargeFacet,
	 * restaura el scroll de cada lista y devuelve el foco si el usuario estaba escribiendo.
	 */
	function restoreFacetsState( state ) {
		if ( ! state ) { return; }
		var f = form();
		if ( ! f ) { return; }

		f.querySelectorAll( '.akb-facet' ).forEach( function ( section ) {
			var name = section.getAttribute( 'data-facet' );
			if ( ! name ) { return; }
			var search = section.querySelector( '[data-akb-facet-search]' );
			if ( search && state.searches && state.searches[ name ] ) {
				search.value = state.searches[ name ];
				filterLargeFacet( section, search.value );
				if ( search.value.trim() && 'true' !== section.getAttribute( 'data-akb-facet-loaded' ) ) {
					loadLargeFacet( section );
				}
			}
			var list = section.querySelector( '.akb-facet-list' );
			if ( list && state.scrolls && typeof state.scrolls[ name ] === 'number' ) {
				list.scrollTop = state.scrolls[ name ];
			}
			if ( search && state.focusedSearch && state.focusedSearch.facet === name ) {
				if ( typeof search.focus === 'function' ) {
					search.focus();
				}
				if ( typeof state.focusedSearch.start === 'number' && typeof search.setSelectionRange === 'function' ) {
					try {
						search.setSelectionRange( state.focusedSearch.start, state.focusedSearch.end );
					} catch ( e ) {}
				}
			}
		} );
	}

	/**
	 * Carga Autor/Serie solo cuando la persona busca o pide ver todo. La
	 * respuesta es el mismo render server-first con un parámetro auxiliar que
	 * no entra en formUrl(), history ni la gramática pública de filtros.
	 */
	function loadLargeFacet( section ) {
		if ( 'true' === section.getAttribute( 'data-akb-facet-loaded' ) ) {
			var readySearch = section.querySelector( '[data-akb-facet-search]' );
			filterLargeFacet( section, readySearch ? readySearch.value : '' );
			return;
		}
		if ( 'true' === section.getAttribute( 'data-akb-facet-loading' ) ) {
			return;
		}

		var name = section.getAttribute( 'data-facet' );
		var base = formUrl();
		if ( ! name || ! base ) {
			return;
		}

		var url = new URL( base, location.href );
		url.searchParams.set( '_akb_expand', name );
		// Pedir el fragmento en su propia URL. La caché del borde no varía necesariamente por
		// X-Requested-With y puede servir HTML de invitado durante diez minutos; compartir la URL de
		// página permitiría entregar un fragmento como documento completo.
		url.searchParams.set( '_akb_fragment', '1' );

		var button = section.querySelector( '[data-akb-facet-expand]' );
		var search = section.querySelector( '[data-akb-facet-search]' );
		section.setAttribute( 'data-akb-facet-loading', 'true' );
		section.setAttribute( 'aria-busy', 'true' );
		if ( button ) {
			button.disabled = true;
			button.setAttribute( 'data-akb-label', button.textContent );
			button.textContent = 'Cargando opciones…';
		}
		setFacetStatus( section, 'Cargando todas las opciones…' );

		// Si falta la faceta y el servidor marca akb-search-facets=indisponible, reintentar con el
		// backoff común mientras la sección siga en el DOM. No interpretar esa ventana de reindexado
		// como fallo definitivo de opciones.
		function cargar( intento ) {
			return fetch( url.toString(), {
				headers: { 'X-Requested-With': 'akb-facet-options' },
				credentials: 'same-origin'
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}
					return response.text();
				} )
				.then( function ( html ) {
					var doc  = new DOMParser().parseFromString( html, 'text/html' );
					var next = findFacetSection( doc, name );
					if ( ! next && document.contains( section ) && reintentarFailOpen( doc, intento ) ) {
						return esperar( RETRY_BASE * Math.pow( 2, intento ), null )
							.then( function () { return cargar( intento + 1 ); } );
					}
					return next;
				} );
		}

		cargar( 0 )
			.then( function ( next ) {
				if ( ! document.contains( section ) ) {
					return;
				}
				var nextList   = next && next.querySelector( '.akb-facet-list' );
				var currentList = section.querySelector( '.akb-facet-list' );
				if ( ! nextList || ! currentList ) {
					throw new Error( 'missing-facet' );
				}

				currentList.replaceChildren.apply(
					currentList,
					Array.prototype.map.call(
						nextList.children,
						function ( item ) { return document.importNode( item, true ); }
					)
				);
				section.setAttribute( 'data-akb-facet-loaded', 'true' );
				if ( button ) { button.remove(); }
				filterLargeFacet( section, search ? search.value : '' );
			} )
			.catch( function () {
				if ( ! document.contains( section ) ) {
					return;
				}
				setFacetStatus( section, 'No se pudieron cargar las opciones. Intenta nuevamente.' );
				if ( button ) {
					button.disabled = false;
					button.textContent = button.getAttribute( 'data-akb-label' ) || 'Ver todas las opciones';
				}
			} )
			.finally( function () {
				section.removeAttribute( 'data-akb-facet-loading' );
				section.removeAttribute( 'aria-busy' );
			} );
	}

	/**
	 * @param {Object|null} [origen] Ver go().
	 * @param {boolean}     [lote]   Ver go().
	 */
	function submitForm( origen, lote ) {
		var url = formUrl();
		if ( url ) {
			go( url, true, origen || null, !! lote );
		}
	}

	function prefetch( url ) {
		// Tras un fallo de red el siguiente gesto navega sin PJAX (ver go()): lo
		// precargado ya no tiene quién lo use, y sería otra petición a una red
		// que acaba de fallar.
		if ( nativo ) { return; }
		if ( AkbCatalogCache.get( url ) ) { return; }
		if ( pre && pre.url === url && Date.now() - pre.t < 5000 ) { return; }
		if ( pre ) { pre.ctl.abort(); }
		var ctl = new AbortController();
		var p   = fetch( url, { signal: ctl.signal, headers: { 'X-Requested-With': 'akb-pjax' }, credentials: 'same-origin', priority: 'low' } )
			.then( function ( r ) {
				// Un non-OK (503/challenge) NO es cacheable: serviría el error
				// hasta 5s después aunque el server ya se haya recuperado (it3).
				// El 404 sí: es la respuesta determinista del catálogo para una
				// combinación de facetas sin resultados, y es JUSTO el caso en que
				// el prefetch paga — el cliente que va a tocar ese filtro ya tiene
				// el empty-state caliente en vez de una recarga.
				if ( ! r.ok && 404 !== r.status ) { throw new Error( String( r.status ) ); }
				return r.text();
			} )
			.then( function ( html ) {
				AkbCatalogCache.set( url, html );
				return html;
			} );
		pre = { url: url, ctl: ctl, t: Date.now(), promise: p };
		p.catch( function () {
			if ( pre && pre.ctl === ctl ) { pre = null; }
		} );
	}

	// Post-swap: el primer pointermove levanta la supresión y se RE-EVALÚA como
	// hover — pero el temblor de ±3px al soltar el click no es intención: re-armaría
	// el prefetch de DESHACER sobre el label recién clickeado (it3). Listener único:
	// un swap nuevo REEMPLAZA al pendiente (no se acumulan en sesiones sin mouse).
	function armPostSwap() {
		if ( onFirstMove ) { document.removeEventListener( 'pointermove', onFirstMove ); }
		onFirstMove = function ( ev ) {
			if ( pressPt && Math.abs( ev.clientX - pressPt.x ) < 4 && Math.abs( ev.clientY - pressPt.y ) < 4 ) {
				document.addEventListener( 'pointermove', onFirstMove, { once: true } );
				return; // jitter del click: sigue suprimido.
			}
			onFirstMove = null;
			postSwap = false;
			onFacetHover( ev );
		};
		document.addEventListener( 'pointermove', onFirstMove, { once: true } );
	}

	// Press = compromiso, prefetch INMEDIATO sin intent (it2 A+B). Batch: el CTA
	// "Ver N" — estado final, formUrl() sin flip, touch INCLUIDO (no es H-2: acá
	// nada se togglea después). Desktop: label checkbox — el flip en pointerdown
	// predice el estado POST-toggle correcto (a diferencia del timer de H-2).
	function onPress( e ) {
		if ( 0 !== e.button ) { return; } // solo botón primario/tap (B2).
		pressPt = { x: e.clientX, y: e.clientY }; // ancla del filtro anti-jitter.
		if ( isBatch() ) {
			// A1: sin cambios pendientes el CTA cierra SIN navegar — no quemar
			// un fetch por tap no-op. Touch INCLUIDO acá (estado ya final).
			if ( pendingApply && e.target && e.target.closest && e.target.closest( '.akb-facets-apply' ) ) {
				var u = formUrl();
				if ( u ) { prefetch( u ); }
			}
			return;
		}
		// Labels desktop: mismos guards que el hover (B2) — mouse, checkbox vivo.
		if ( e.pointerType && 'mouse' !== e.pointerType ) { return; }
		var label = e.target && e.target.closest ? e.target.closest( 'label' ) : null;
		if ( ! label ) { return; }
		var box = label.querySelector( 'input[type="checkbox"]' );
		if ( ! box || box.disabled ) { return; }
		window.clearTimeout( preTimer ); // el press supera al intent pendiente.
		var was = box.checked;
		box.checked = ! was;
		var url = formUrl();
		box.checked = was;
		if ( url ) { prefetch( url ); }
	}

	// Hover-intent (gate, punto b): 150 ms sostenidos sobre el label — escanear
	// la lista no genera ráfagas. Flip de .checked por JS no dispara change (f).
	// Solo mouse: en touch el pointerover del tap precede al change y el timer
	// prefetchearía la URL de DESHACER (gate H-2).
	function onFacetHover( e ) {
		if ( e.pointerType && 'mouse' !== e.pointerType ) { return; }
		// Pointerover sintético post-swap (puntero quieto, DOM nuevo): sin
		// movimiento no hay intención — no armar hasta el primer pointermove.
		if ( postSwap ) { return; }
		var label = e.target && e.target.closest ? e.target.closest( 'label' ) : null;
		if ( label === preLabel ) { return; } // movimiento interno: timer sigue.
		preLabel = label;
		window.clearTimeout( preTimer );
		if ( ! label || isBatch() ) { return; }
		// Solo checkboxes: en radio el flip des-chequea al hermano y el restore
		// NO lo devuelve → filtro perdido sin click (bug probado, gate H-1).
		// Ghost (disabled) no entra a FormData → prefetchearía la URL actual.
		var box = label.querySelector( 'input[type="checkbox"]' );
		if ( ! box || box.disabled ) { return; }
		preTimer = window.setTimeout( function () {
			// Sin :hover al disparar no hay intención — cubre puntero que salió
			// y label descolgado por un swap en la ventana (gate H-3).
			if ( ! label.matches( ':hover' ) ) { return; }
			var was = box.checked;
			box.checked = ! was;
			var url = formUrl();
			box.checked = was;
			if ( url ) { prefetch( url ); }
		}, 150 );
	}

	/**
	 * Elegir singular o plural mediante AKB_FACETS.noun del servidor, igual que la primera carga.
	 * Si falta la configuración, conservar la palabra del HTML; no codificar «productos» en el
	 * bundle.
	 *
	 * @param {Element} count Nodo del número (`[data-akb-apply-count]`).
	 * @param {number}  total Total que devolvió /count.
	 */
	function applyNoun( count, total ) {
		var nouns = CFG.noun;
		var apply = count.closest ? count.closest( '.akb-facets-apply' ) : null;
		var noun  = apply ? apply.querySelector( '[data-akb-apply-noun]' ) : null;
		if ( ! noun || ! nouns || ! nouns.one || ! nouns.other ) {
			return;
		}
		noun.textContent = 1 === Number( total ) ? nouns.one : nouns.other;
	}

	/**
	 * Batch (drawer mobile): actualiza "Ver N productos" con un conteo LIVIANO
	 * debounced, sin recargar el grid por tap (Baymard).
	 */
	function updateCount() {
		var btn = document.querySelector( '[data-akb-apply-count]' );
		if ( ! btn || ! CFG.countUrl ) {
			return;
		}
		var f = form();
		if ( ! f ) {
			return;
		}
		var url = formUrl();
		var qs  = url.indexOf( '?' ) !== -1 ? url.slice( url.indexOf( '?' ) ) : '';
		// Contexto del archive (data-attrs): /count lo recibe como param normal.
		var cf = f.getAttribute( 'data-akb-ctx-facet' );
		var cv = f.getAttribute( 'data-akb-ctx-value' );
		if ( cf && cv ) {
			qs += ( qs ? '&' : '?' ) + encodeURIComponent( cf ) + '=' + encodeURIComponent( cv );
		}
		if ( countInflight ) {
			countInflight.abort();
		}
		// Guarda de carrera: abort() no cancela un fetch ya resolviendo; si una
		// la request más nueva gana (review 07-04).
		var mine = new AbortController();
		countInflight = mine;
		btn.closest( '.akb-facets-apply' ).classList.add( 'is-loading' );
		// El 503 de la ventana de guardado se reintenta (el botón sigue en
		// «is-loading»); sin esto quedaba el número de la consulta anterior.
		fetchReintentando(
			CFG.countUrl + qs,
			{ signal: mine.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' },
			function () { return mine === countInflight; }
		)
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) {
				if ( mine !== countInflight ) {
					return; // request más nueva ganó.
				}
				if ( d && typeof d.total !== 'undefined' ) {
					btn.textContent = new Intl.NumberFormat( 'es-CL' ).format( d.total );
					applyNoun( btn, d.total );
				}
			} )
			.catch( function () { /* abort/red: dejamos el número anterior */ } )
			.finally( function () {
				if ( mine === countInflight ) {
					var apply = document.querySelector( '.akb-facets-apply' );
					if ( apply ) { apply.classList.remove( 'is-loading' ); }
				}
			} );
	}

	// El drawer vive en el sidebar, display:none en mobile → position:fixed no
	// vence un ancestro oculto. Al abrir se mueve a <body>; al cerrar vuelve a
	// su lugar (el layout desktop y el swap pjax lo esperan ahí).
	var drawerHome = null;

	function drawerToBody() {
		var d = form();
		if ( ! d || d.parentNode === document.body ) {
			return;
		}
		drawerHome = { parent: d.parentNode, next: d.nextSibling };
		document.body.appendChild( d );
	}

	function drawerRestore() {
		var d = form();
		if ( ! d || ! drawerHome ) {
			return;
		}
		drawerHome.parent.insertBefore( d, drawerHome.next );
		drawerHome = null;
	}

	/** Refleja el estado del drawer en los triggers (a11y). */
	function setTriggerExpanded( on ) {
		document.querySelectorAll( '[data-akb-drawer-open]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		} );
	}

	/** Aplica los filtros pendientes y cierra el drawer (modo batch). */
	function applyAndClose() {
		document.body.classList.remove( 'akb-drawer-open' );
		drawerOpen = false;
		pendingApply = false;
		setTriggerExpanded( false );
		drawerRestore(); // volver al sidebar ANTES del swap de pjax.
		submitForm( null, true );
	}

	/** (Re)liga los listeners del form tras cada swap. */
	function bindForm() {
		var f = form();
		if ( ! f || f.dataset.akbBound === '1' ) {
			return;
		}
		f.dataset.akbBound = '1';
		f.querySelectorAll( '[data-akb-facet-search]' ).forEach( function ( input ) {
			input.disabled = false;
		} );

		// Checkbox/radio → auto-submit (debounce). El input de búsqueda de
		// autor/serie NO submitea: filtra client-side (sin name, no viaja).
		f.addEventListener( 'change', function ( e ) {
			// B1: el click superó al intent — un timer de hover pendiente que
			// dispare POST-change computaría la URL de DESHACER y pisaría el slot.
			window.clearTimeout( preTimer );
			if ( e.target && e.target.matches( '[data-akb-facet-search]' ) ) {
				return;
			}
			// Drawer abierto: acumular + contador, sin recargar el grid.
			if ( isBatch() ) {
				pendingApply = true;
				window.clearTimeout( countTimer );
				countTimer = window.setTimeout( updateCount, 220 );
				return;
			}
			// Desktop: refresh instantáneo (Baymard: instant > batch en desktop).
			// El control se recuerda AHORA: tras el debounce el gesto ya pasó.
			var origen = controlOrigin( e.target, FORM );
			window.clearTimeout( debounce );
			debounce = window.setTimeout( function () { submitForm( origen ); }, 100 );
		} );

		// pointerover burbujea (pointerenter no); onFacetHover dedupea por label.
		// pointerleave cancela el intent pendiente al salir del form (si no, el
		// timer del último label hovereado dispara un prefetch huérfano).
		f.addEventListener( 'pointerover', onFacetHover );
		f.addEventListener( 'pointerdown', onPress );
		f.addEventListener( 'pointerleave', function () {
			preLabel = null;
			window.clearTimeout( preTimer );
		} );
		// Touch: si el press degenera en scroll, el browser emite pointercancel
		// y el prefetch del CTA quedaría quemado sin tap real (it3). El slot
		// consumido por go() ya no está en `pre`, así que abortar acá es seguro.
		f.addEventListener( 'pointercancel', function () {
			window.clearTimeout( preTimer );
			if ( pre ) { pre.ctl.abort(); pre = null; }
		} );

		// Autor/Serie: búsqueda local tras cargar la lista completa bajo demanda.
		//
		// El filtrado de lo YA pintado es inmediato (es local y no cuesta nada);
		// la carga de la lista completa va con debounce. Sin él se disparaba con
		// la PRIMERA tecla y otra vez con cada una siguiente hasta que la primera
		// respondiera: escribir «one piece» abría una petición por letra desde el
		// móvil. Enter y «Ver todas» siguen siendo inmediatos — ahí el usuario
		// ya declaró que quiere la lista.
		f.addEventListener( 'focusin', function ( e ) {
			var box = e.target;
			if ( ! box || ! box.matches( '[data-akb-facet-search]' ) ) {
				return;
			}
			var section = box.closest( '.akb-facet' );
			if ( section && 'true' !== section.getAttribute( 'data-akb-facet-loaded' ) ) {
				loadLargeFacet( section );
			}
		} );

		f.addEventListener( 'input', function ( e ) {
			var box = e.target;
			if ( ! box || ! box.matches( '[data-akb-facet-search]' ) ) {
				return;
			}
			var section = box.closest( '.akb-facet' );
			if ( ! section ) {
				return;
			}
			filterLargeFacet( section, box.value );
			window.clearTimeout( facetLoadTimer );
			if ( box.value.trim() && 'true' !== section.getAttribute( 'data-akb-facet-loaded' ) ) {
				facetLoadTimer = window.setTimeout( function () {
					loadLargeFacet( section );
				}, FACET_LOAD_DEBOUNCE );
			}
		} );

		f.addEventListener( 'keydown', function ( e ) {
			var box = e.target;
			if ( 'Enter' !== e.key || ! box || ! box.matches( '[data-akb-facet-search]' ) ) {
				return;
			}
			e.preventDefault();
			window.clearTimeout( facetLoadTimer );
			var section = box.closest( '.akb-facet' );
			if ( section ) {
				loadLargeFacet( section );
			}
		} );

		f.addEventListener( 'click', function ( e ) {
			window.clearTimeout( facetLoadTimer );
			var button = e.target.closest( '[data-akb-facet-expand]' );
			if ( ! button ) {
				return;
			}
			e.preventDefault();
			var section = button.closest( '.akb-facet' );
			if ( section ) {
				loadLargeFacet( section );
			}
		} );

		// Aplicar «Ver N» del lote mediante close(), limpiando inert y foco antes de navegar.
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( isBatch() && drawerClose ) {
				drawerClose();
			} else {
				submitForm();
			}
		} );
	}

	/** Chips (delegado en document, sobreviven a los swaps). */
	document.addEventListener( 'click', function ( e ) {
		var chip = e.target.closest( '[data-akb-chips] .akb-chip' );
		var clear = e.target.closest( '[data-akb-clear]' );
		var pager = e.target.closest( '.woocommerce-pagination a' );

		// «Reintentar» del aviso de un fallo de red: carga, como sin JS, lo que
		// el cliente pidió y no llegó (ver avisarFalloDeRed()).
		if ( e.target.closest( '[data-akb-reintentar]' ) ) {
			e.preventDefault();
			window.location.assign( urlFallida );
			return;
		}

		if ( clear ) {
			e.preventDefault();
			// data-akb-clear=filtros conserva _buscar cuando los filtros dejan una búsqueda sin
			// resultados. Sólo Limpiar todo elimina también el término escrito.
			var keepSearch = 'filtros' === clear.getAttribute( 'data-akb-clear' );
			var f = form();
			if ( f ) {
				f.querySelectorAll( 'input:checked' ).forEach( function ( i ) { i.checked = false; } );
				// Apartada, no quitada: si el PJAX no llega por la red, la
				// búsqueda vuelve (ver volverALoPintado()).
				var hidden = keepSearch ? null : f.querySelector( 'input[name="_buscar"]' );
				if ( hidden ) { hidden.disabled = true; }
				submitForm();
			} else {
				go( urlWithout( null, null, keepSearch ), true );
			}
			return;
		}

		if ( chip && ! chip.classList.contains( 'akb-chip--clear' ) ) {
			e.preventDefault();
			var facet = chip.getAttribute( 'data-facet' );
			var value = chip.getAttribute( 'data-value' );
			if ( form() ) {
				uncheck( facet, value );
				submitForm();
			} else {
				go( urlWithout( facet, 'buscar' === facet ? null : value ), true );
			}
			return;
		}

		if ( pager && pager.href ) {
			e.preventDefault();
			go( pager.href, true );
		}
	} );

	function uncheck( facet, value ) {
		var f = form();
		if ( ! f ) {
			return;
		}
		if ( 'buscar' === facet ) { // chip de búsqueda: no es checkbox.
			// Apartada, no quitada (ver el handler de `[data-akb-clear]`).
			var hidden = f.querySelector( 'input[name="_buscar"]' );
			if ( hidden ) { hidden.disabled = true; }
			return;
		}
		f.querySelectorAll( 'input[name="_' + facet + '[]"]' ).forEach( function ( i ) {
			if ( i.value === value ) {
				i.checked = false;
			}
		} );
	}

	// Botón atrás/adelante. Un traversal que sólo cambia el hash pertenece
	// al componente que lo publicó; abrir un fetch PJAX acá deja una navegación
	// obsoleta compitiendo con un Back documental inmediato (especialmente WebKit).
	window.addEventListener( 'popstate', function () {
		// ANTES del return temprano: un traversal de historial cancela cualquier
		// PJAX pendiente, incluso cuando no haya que navegar. Si no, una respuesta
		// que llega tarde pisa el Atrás del usuario (ver cancelInflight).
		cancelInflight();

		if ( renderedLocation === location.pathname + location.search ) {
			return;
		}
		go( location.href, false );
	} );

	// --- Drawer mobile ---
	function initDrawer() {
		var lastFocus = null;

		// El restore de foco post-TAP no debe pintar el ring (ver `modality`);
		// los caminos de teclado lo conservan.
		// Suprime el ring solo en este restore; blur/keydown se limpian entre sí.
		function quietFocus( el ) {
			el.classList.add( 'akb-tap-focus' );
			function clean() {
				el.classList.remove( 'akb-tap-focus' );
				el.removeEventListener( 'blur', clean );
				document.removeEventListener( 'keydown', clean, true );
			}
			el.addEventListener( 'blur', clean );
			document.addEventListener( 'keydown', clean, true );
		}

		function drawerEl() {
			return document.querySelector( '.akb-facets' ); // fresco: pjax lo swapea.
		}

		/**
		 * Inertiza/restaura el fondo con el drawer abierto (major m1): tras
		 * drawerToBody() el fondo = hermanos de <body> menos drawer y región
		 * live; `inert` los saca del foco y del árbol de accesibilidad.
		 */
		function setBackgroundInert( on ) {
			var drawer = drawerEl();
			Array.prototype.forEach.call( document.body.children, function ( el ) {
				if ( el === drawer || el.id === 'akb-live-region' ) {
					return;
				}
				if ( on ) {
					el.setAttribute( 'inert', '' );
				} else {
					el.removeAttribute( 'inert' );
				}
			} );
		}

		/** Lista de tabbables visibles dentro del drawer. */
		function tabbables( drawer ) {
			var sel = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
			return Array.prototype.filter.call( drawer.querySelectorAll( sel ), function ( el ) {
				return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
			} );
		}

		function open( opener ) {
			var drawer = drawerEl();
			if ( ! drawer ) {
				return;
			}
			// Safari no enfoca botones al tap → sin opener, lastFocus=<body>.
			lastFocus = opener || document.activeElement;
			drawerOpen = true;
			drawerToBody(); // escapar el sidebar display:none de mobile.
			drawer = drawerEl();
			document.body.classList.add( 'akb-drawer-open' );
			drawer.setAttribute( 'aria-modal', 'true' );
			drawer.setAttribute( 'role', 'dialog' );
			drawer.removeAttribute( 'inert' );
			setBackgroundInert( true );
			setTriggerExpanded( true );
			var first = drawer.querySelector( '[data-akb-drawer-close], input, button, summary' );
			if ( first ) {
				// Mantener el foco inicial del diálogo sin dejar un ring pegado tras
				// tap. La apertura por teclado conserva el indicador visible.
				if ( 'teclado' !== modality ) { quietFocus( first ); }
				first.focus();
			}
			document.addEventListener( 'keydown', onKey );
		}

		function close() {
			document.removeEventListener( 'keydown', onKey );
			setBackgroundInert( false ); // restaurar el fondo ANTES de reparentar.
			// aria-modal/role: limpiar en AMBOS caminos.
			var drawer = drawerEl();
			if ( drawer ) {
				drawer.removeAttribute( 'aria-modal' );
				drawer.removeAttribute( 'role' );
			}
			// Cerrar con cambios pendientes (batch) = aplicar: los tildados
			// son la intención.
			if ( pendingApply && isMobile() ) {
				applyAndClose(); // quita la clase + drawerOpen=false + restore + pjax.
			} else {
				drawerOpen = false;
				document.body.classList.remove( 'akb-drawer-open' );
				setTriggerExpanded( false );
				drawerRestore(); // devolver al sidebar.
			}
			if ( lastFocus && typeof lastFocus.focus === 'function' ) {
				if ( 'teclado' !== modality ) { quietFocus( lastFocus ); }
				lastFocus.focus();
			}
		}
		drawerClose = close;

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			// Focus-trap real: Tab/Shift+Tab circulan dentro del drawer (WCAG 2.4.3).
			var drawer = drawerEl();
			if ( ! drawer ) {
				return;
			}
			var items = tabbables( drawer );
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last  = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			} else if ( ! drawer.contains( document.activeElement ) ) {
				e.preventDefault();
				first.focus();
			}
		}

		// Delegado en document → toggle/close sobreviven a los swaps de pjax.
		document.addEventListener( 'click', function ( e ) {
			var op = e.target.closest( '[data-akb-drawer-open]' );
			if ( op ) {
				open( op );
				return;
			}
			if ( e.target.closest( '[data-akb-drawer-close]' ) ) {
				close();
				return;
			}
			// Click fuera del drawer (backdrop) con el drawer abierto = cerrar.
			if ( drawerOpen && ! e.target.closest( '.akb-facets' ) && ! e.target.closest( '[data-akb-drawer-open]' ) ) {
				close();
			}
		} );
	}

	// --- Colapso de facetas (persistido en localStorage) ---
	var COLLAPSE_KEY = 'akb_facet_collapse';

	function loadCollapse() {
		try {
			return JSON.parse( localStorage.getItem( COLLAPSE_KEY ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function saveCollapse( map ) {
		try {
			localStorage.setItem( COLLAPSE_KEY, JSON.stringify( map ) );
		} catch ( e ) { /* modo privado */ }
	}

	function setFacetOpen( section, open ) {
		var btn = section.querySelector( '[data-akb-facet-toggle]' );
		var body = section.querySelector( '.akb-facet-body' );
		if ( btn ) { btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' ); }
		if ( body ) { body.hidden = ! open; }
		section.classList.toggle( 'is-collapsed', ! open );
	}

	/** Aplica la preferencia guardada del usuario sobre el estado server. */
	function applyCollapse() {
		var map = loadCollapse();
		document.querySelectorAll( '.akb-facet[data-facet]' ).forEach( function ( sec ) {
			var name = sec.getAttribute( 'data-facet' );
			if ( map[ name ] === 'open' || map[ name ] === 'closed' ) {
				setFacetOpen( sec, map[ name ] === 'open' );
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '[data-akb-facet-toggle]' );
		if ( ! toggle ) {
			return;
		}
		var section = toggle.closest( '.akb-facet' );
		if ( ! section ) {
			return;
		}
		var open = 'true' !== toggle.getAttribute( 'aria-expanded' );
		setFacetOpen( section, open );
		var map = loadCollapse();
		map[ section.getAttribute( 'data-facet' ) ] = open ? 'open' : 'closed';
		saveCollapse( map );
	} );

	function boot() {
		bindForm();
		initDrawer();
		applyCollapse();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
