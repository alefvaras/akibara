/**
 * search-suggest.js — autocomplete del buscador Akibara. Vanilla, CERO jQuery.
 * Debounce 220ms + AbortController; REST /suggest sin nonce (LiteSpeed);
 * combobox APG completo; historial v3 en localStorage; /track por sendBeacon.
 * Config vía window.AKB_SEARCH = { rest, minChars, debounce, emptyHint }.
 */
( function () {
	'use strict';

	var CFG = window.AKB_SEARCH || {};
	var REST = ( CFG.rest || '/wp-json/akibara/v1/search/' ).replace( /\/?$/, '/' );
	var MIN = CFG.minChars || 2;
	var DEBOUNCE = CFG.debounce || 220;
	// Pista de «sin resultados»: la edita el dueño en Buscador → Ajustes y llega
	// por wp_localize_script. NO se escribe acá: producción sirve el bundle
	// minificado, así que un literal en este archivo obliga a regenerar el `.min`
	// y desplegar para cambiar una frase.
	var EMPTY_HINT = typeof CFG.emptyHint === 'string' ? CFG.emptyHint : 'Prueba con el nombre de la serie.';
	// Historial unificado con envelope {v:3, items}. La migración absorbe y elimina los tres
	// stores anteriores después de persistir el nuevo.
	var HISTORY_KEY = 'akb_search_history_v3';
	var OLD_KEYS = [ 'akibara_search_history_v2', 'akb_search_history', 'akb-search-recent-v1' ];
	var HISTORY_MAX = 10;
	var HISTORY_MAX_AGE = 1000 * 60 * 60 * 24 * 45; // 45 días, como el v2.
	var PANEL_SEQ = 0; // ids únicos: Date.now() colisionaba con 2 inputs en el mismo ms.
	var POP = null; // Populares resueltos (array) — UNA fetch por pageload.
	var POPQ = null; // …y su promesa memoizada (compartida entre inputs).
	var LOADING_DELAY = 150; // umbral corto antes de mostrar "Buscando…" — evita flicker en respuestas rápidas.

	/*
	 * Compartir entre inputs la memo de /suggest, igual que POPQ, para reutilizar consultas
	 * repetidas al corregir texto. El origen usa Cache-Control:no-store,max-age=0, por lo que el
	 * navegador no puede reutilizarlas por sí solo. Aplicar un TTL explícito de 60s: el panel
	 * contiene precios y disponibilidad que no deben quedar en caché indefinida.
	 */
	var SUGGEST_MEMO = new Map(); // clave → { ts, data }; el orden de Map es el LRU.
	var SUGGEST_MEMO_MAX = 50;
	var SUGGEST_MEMO_TTL = 60000;

	function memoGet( key ) {
		var hit = SUGGEST_MEMO.get( key );
		if ( ! hit ) {
			return null;
		}
		if ( Date.now() - hit.ts > SUGGEST_MEMO_TTL ) {
			SUGGEST_MEMO.delete( key );
			return null;
		}
		// Reinsertar = moverla al final: el primer elemento del Map es siempre el
		// menos usado, que es el que se desaloja abajo.
		SUGGEST_MEMO.delete( key );
		SUGGEST_MEMO.set( key, hit );
		return hit.data;
	}

	function memoSet( key, data ) {
		if ( ! data || 'object' !== typeof data ) {
			return; // sólo se memoiza el camino de éxito; un 429/500 no se guarda.
		}
		SUGGEST_MEMO.delete( key );
		SUGGEST_MEMO.set( key, { ts: Date.now(), data: data } );
		while ( SUGGEST_MEMO.size > SUGGEST_MEMO_MAX ) {
			SUGGEST_MEMO.delete( SUGGEST_MEMO.keys().next().value );
		}
	}

	/*
	 * Reintentar sólo 503 con code=index_unavailable durante el resellado del índice. Usar
	 * Retry-After/RestCache::RETRY_AFTER_SECONDS como base, con esperas de 1s, 2s y 4s. No repetir
	 * 429 ni 503 ajenos sin ese código. La implementación se duplica en akb-search.js porque ambos
	 * bundles se cargan de forma independiente.
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

	/**
	 * Inicializa el autocomplete sobre un input de búsqueda.
	 *
	 * @param {HTMLInputElement} input
	 */
	function init( input ) {
		if ( ! input || input.dataset.akbSuggest === '1' ) {
			return;
		}
		input.dataset.akbSuggest = '1';
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		// Omitir aria-haspopup: el combobox ya implica listbox en ARIA 1.2/APG. Declararlo puede
		// impedir que axe compruebe el id de aria-controls aunque el listbox exista oculto.
		input.setAttribute( 'aria-expanded', 'false' );

		var panel = document.createElement( 'div' );
		panel.className = 'akb-suggest-panel';
		panel.setAttribute( 'role', 'listbox' );
		// Un listbox sin nombre accesible se anuncia como «lista» a secas, y en el
		// cajón móvil hay más de un combobox en la misma página.
		panel.setAttribute( 'aria-label', 'Sugerencias de búsqueda' );
		PANEL_SEQ += 1;
		panel.id = 'akb-suggest-' + PANEL_SEQ;
		panel.hidden = true;
		input.setAttribute( 'aria-controls', panel.id );

		var wrap = input.closest( 'form' ) || input.parentNode;
		wrap.style.position = wrap.style.position || 'relative';
		wrap.appendChild( panel );

		// `zeroGen`: generación del estado vacío. Cada close() la sube, y una
		// respuesta de Populares pedida antes ya no pinta (ver close()).
		var state = { items: [], active: -1, controller: null, timer: 0, loadingTimer: 0, lastQuery: '', zeroGen: 0 };

		/**
		 * Cerrar la lista y cancelar debounce, fetch y lupa para que una respuesta tardía no vuelva a
		 * abrirla. Con campo vacío, invalidar también la generación de Populares: su promesa se
		 * comparte y no se aborta, pero ningún pedido anterior al cierre puede pintar.
		 */
		function close() {
			state.zeroGen += 1;
			ocultar();
		}

		/**
		 * Cerrar sin invalidar Populares sólo cuando renderZero() todavía no tiene contenido, por
		 * ejemplo después de borrar historial. Esa espera necesita la respuesta pendiente y no
		 * representa un cierre solicitado por el cliente.
		 */
		function ocultar() {
			cancelPending();
			fx( false );
			panel.hidden = true;
			panel.innerHTML = '';
			panel.removeAttribute( 'aria-busy' );
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			state.active = -1;
			state.lastQuery = ''; // re-focus con texto debe poder re-buscar.
		}

		/**
		 * Repintar el panel INVALIDA la selección: sin esto Enter navegaba a la
		 * sugerencia resaltada ANTES del repintado (con el panel diciendo
		 * «Buscando…»), y aria-activedescendant quedaba apuntando a un id que en
		 * el panel nuevo pertenece a OTRO producto (los ids son panel.id + idx).
		 */
		function clearActive() {
			state.active = -1;
			input.removeAttribute( 'aria-activedescendant' );
		}

		/** Pinta y abre el panel. */
		function open( html ) {
			panel.innerHTML = html;
			panel.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		// Mostrar la carga discreta después de LOADING_DELAY para evitar parpadeo en respuestas
		// rápidas.
		function renderLoading() {
			panel.setAttribute( 'aria-busy', 'true' );
			state.items = [];
			clearActive();
			open( '<div class="akb-suggest-loading" role="status">Buscando…</div>' );
		}

		// Distinguir fallos reales de red/servidor de un abort por tipeo. Un 429/500 es un aviso, no
		// ausencia de resultados; conservar el envío del formulario con Enter.
		function renderError() {
			panel.removeAttribute( 'aria-busy' );
			state.items = [];
			clearActive();
			open( '<div class="akb-suggest-error" role="alert">No pudimos cargar sugerencias. Presiona Enter para buscar igual.</div>' );
		}

		/**
		 * URL de la página de resultados completa para `q` — el action del form
		 * dueño (ya resuelve tienda o archivo de término; el contexto viaja en
		 * la propia URL). Sin form no hay destino: el footer no se pinta.
		 */
		function searchUrl( q ) {
			var f = input.closest( 'form' );
			if ( ! f || ! f.action ) {
				return '';
			}
			try {
				var u = new URL( f.action, window.location.origin );
				u.searchParams.set( '_buscar', q );
				return u.href;
			} catch ( e ) {
				return '';
			}
		}

		/**
		 * Contexto implícito del archive (data-attrs del form, mismos que usa
		 * el /count del drawer): viaja al /suggest como param `_*` normal para
		 * que el `total` del footer calce con la página destino.
		 */
		function ctxParams() {
			var f = input.closest( 'form' );
			var cf = f && f.getAttribute( 'data-akb-ctx-facet' );
			var cv = f && f.getAttribute( 'data-akb-ctx-value' );
			return cf && cv ? '&' + encodeURIComponent( cf ) + '=' + encodeURIComponent( cv ) : '';
		}

		function highlight( text, q ) {
			var i = text.toLowerCase().indexOf( q.toLowerCase() );
			if ( i < 0 ) {
				return escapeHtml( text );
			}
			return escapeHtml( text.slice( 0, i ) ) + '<mark>' +
				escapeHtml( text.slice( i, i + q.length ) ) + '</mark>' +
				escapeHtml( text.slice( i + q.length ) );
		}

		/**
		 * La option de serie precede a productos y usa icono de tomos, badge Serie y conteo, con
		 * enlace a su landing. No reutilizar la plantilla de producto sin portada/precio. Todas las
		 * options pertenecen al mismo listbox; los títulos de grupo son presentation y quedan fuera
		 * del recorrido.
		 */
		function serieOpt( it, idx ) {
			return '<a class="akb-suggest-item akb-suggest-serie" role="option" aria-selected="false" id="' +
				panel.id + '-' + idx + '" href="' + escapeHtml( it.url ) + '" data-idx="' + idx + '">' +
				// Tres lomos en una repisa, uno inclinado: con barras horizontales
				// iguales el icono se leía como el menú hamburguesa.
				'<svg class="akb-suggest-serie-icon" width="40" height="40" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<rect x="3" y="4" width="5" height="17" rx="1"></rect>' +
				'<rect x="9.5" y="6" width="5" height="15" rx="1"></rect>' +
				'<rect x="16" y="6" width="5" height="15" rx="1" transform="rotate(12 18.5 13.5)"></rect></svg>' +
				'<span class="akb-suggest-body">' +
				'<span class="akb-suggest-title">' + ( it.name_html || escapeHtml( it.name || '' ) ) + '</span> ' +
				// Conservar un espacio antes del meta para separar el nombre accesible de la serie y su
				// conteo. El whitespace entre flex items no modifica la grilla.
				'<span class="akb-suggest-meta">' +
				'<span class="akb-suggest-badge akb-badge-serie">Serie</span> ' +
				escapeHtml( it.meta || '' ) + '</span></span></a>';
		}

		function render( items, q, total, corrected, series, relaxed ) {
			panel.removeAttribute( 'aria-busy' );
			series = series || [];

			// Clasificación comercial: bodega inmediata vs disponibles para encargo/reserva
			var bodegaItems = [];
			var encargoItems = [];
			( items || [] ).forEach( function ( it ) {
				var isBodega = it.availability === 'instock' || ( ! it.availability && ( ! it.badge_tone || it.badge_tone === 'ok' ) );
				if ( isBodega ) {
					bodegaItems.push( it );
				} else {
					it.isEncargo = true;
					// Si está agotado o sin precio comercial, abre el modal de solicitud sin pago.
					// Si es preventa o a pedido con precio, navega a la ficha para compra/reserva pagada.
					it.isRequestable = it.availability === 'outofstock' || it.badge_tone === 'out' || ! it.price_html;
					encargoItems.push( it );
				}
			} );
			// Orden en el panel: bodega primero, encargo después
			items = bodegaItems.concat( encargoItems );

			// Los índices del recorrido con flechas se leen del ORDEN DEL DOM
			// (setActive usa querySelectorAll): state.items tiene que ir en el
			// mismo orden en que se pinta — series, productos, «Ver los N».
			state.items = series.concat( items );
			clearActive();
			if ( ! items.length && ! series.length ) {
				// `role="status"`, NO `role="option"`: el nodo es inerte —no entra
				// en state.items (se acaba de asignar arriba), no tiene id ni
				// data-idx ni aria-selected, y setActive() sólo recorre
				// `.akb-suggest-item`—, así que anunciarlo como opción de un
				// listbox le prometía al lector de pantalla algo seleccionable que
				// Enter jamás activa. Mismo rol que las otras dos líneas inertes
				// del panel (carga y corrección fuzzy). La salida con teclado ya
				// existe y sigue intacta: sin options el keydown sale por el guard
				// `if ( ! n )` y Enter envía el formulario a la tienda, donde la
				// corrección laxa sí aplica.
				open( '<div class="akb-suggest-empty" role="status">Sin resultados para «' +
					escapeHtml( q ) + '». ' + escapeHtml( EMPTY_HINT ) + '</div>' +
					'<div class="akb-suggest-empty-cta"><button type="button" class="akb-suggest-request-btn" data-akb-request-q="' +
					escapeHtml( q ) + '">📋 Solicitar «<strong>' + escapeHtml( q ) + '</strong>» a pedido</button></div>' );
				return;
			}
			// La corrección fuzzy es un nodo no interactivo con role=status y sin data-idx: queda fuera de
			// state.items y del recorrido de flechas. Mostrar consulta cruda y corrección completa, igual
			// que el catálogo, sólo si existen items.
			var html = corrected
				? '<div class="akb-suggest-correction" role="status">Sin resultados para «' +
					escapeHtml( corrected.typo ) + '». Mostrando lo más parecido a «<strong>' +
					escapeHtml( corrected.fix ) + '</strong>».</div>'
				: '';

			// Editorial soltada: la búsqueda pedía una editorial que no tiene esa
			// serie. El servidor manda la frase ya escrita (el dueño la edita en
			// Textos), así que aquí sólo se escapa y se pinta. Mismo nodo inerte
			// que la corrección: role="status", sin data-idx, fuera de las flechas.
			if ( relaxed && ( relaxed.notice_html || relaxed.notice ) ) {
				// `notice_html` viene escapado del servidor con las dos piezas en
				// <strong> (igual que title_html); `notice` es el texto plano de
				// respaldo, que sí se escapa acá.
				html += '<div class="akb-suggest-correction" role="status" data-akb-relaxed>' +
					( relaxed.notice_html || escapeHtml( relaxed.notice ) ) + '</div>';
			}

			// Grupo de series ARRIBA y rotulado. Los dos grupos se rotulan (no
			// sólo el nuevo): con «Series» suelto encima, los productos de abajo
			// quedaban leyéndose como parte del mismo grupo.
			if ( series.length ) {
				html += '<div class="akb-suggest-head" role="presentation">' +
					( series.length === 1 ? 'Serie' : 'Series' ) + '</div>';
				html += series.map( serieOpt ).join( '' );
				if ( items.length ) {
					html += '<div class="akb-suggest-head" role="presentation">Productos</div>';
				}
			}

			html += items.map( function ( it, i ) {
				var idx = series.length + i;
				var subhead = '';
				if ( i === 0 && bodegaItems.length > 0 ) {
					subhead = '<div class="akb-suggest-subhead akb-subhead--bodega" role="presentation"><span class="akb-subhead-dot akb-dot--ok"></span> En Bodega · Entrega Inmediata</div>';
				} else if ( i === bodegaItems.length && encargoItems.length > 0 ) {
					subhead = '<div class="akb-suggest-subhead akb-subhead--encargo" role="presentation"><span class="akb-subhead-dot akb-dot--encargo"></span> Disponibles a Pedido</div>';
				}
				var isEnc = !! it.isEncargo;
				var isReq = !! it.isRequestable;
				var tagHtml = '';
				if ( isReq ) {
					tagHtml = ' <span class="akb-suggest-encargo-tag">Solicitar</span>';
				} else if ( isEnc ) {
					tagHtml = ' <span class="akb-suggest-reservar-tag">Reservar →</span>';
				}
				return subhead + '<a class="akb-suggest-item' + ( isEnc ? ' akb-suggest-item--encargo' : '' ) + '" role="option" aria-selected="false" id="' + panel.id + '-' + idx +
					'" href="' + escapeHtml( it.url ) + '" data-idx="' + idx + '"' + ( isEnc ? ' data-akb-encargo="1"' : '' ) + ( isReq ? ' data-akb-requestable="1"' : '' ) + '>' +
					'<img class="akb-suggest-thumb" src="' + escapeHtml( it.thumb ) + '" alt="" loading="lazy" width="40" height="40">' +
					'<span class="akb-suggest-body">' +
					'<span class="akb-suggest-title">' + ( it.title_html || highlight( it.title, q ) ) + '</span>' +
					'<span class="akb-suggest-meta">' + it.price_html +
					' <span class="akb-suggest-badge akb-badge-' + escapeHtml( it.badge_tone ) + '">' +
					escapeHtml( it.badge ) + '</span>' +
					tagHtml + '</span></span></a>';
			} ).join( '' );

			if ( encargoItems.length > 0 || ( bodegaItems.length > 0 && total <= items.length ) ) {
				html += '<div class="akb-suggest-request-banner" role="presentation"><button type="button" class="akb-suggest-request-trigger" data-akb-request-term="' + escapeHtml( q ) + '">¿Buscas otro tomo o edición? <strong>Solicitar a Pedido »</strong></button></div>';
			}

			// Añadir «Ver los N resultados» como rama propia fuera de la plantilla de producto. Comparte
			// .akb-suggest-item y entra en state.items como {url} para conservar foco, flechas y
			// navegación con Enter.
			if ( total > items.length ) {
				// Con corrección, «Ver los N» lleva a la query CORREGIDA (los
				// N resultados son de «fix», no del typo) [spec v5 D4].
				var moreUrl = searchUrl( corrected ? corrected.fix : ( relaxed && relaxed.kept ? relaxed.kept : q ) );
				if ( moreUrl ) {
					var moreIdx = series.length + items.length;
					html += '<a class="akb-suggest-item akb-suggest-more" role="option" aria-selected="false" id="' +
						panel.id + '-' + moreIdx + '" data-idx="' + moreIdx + '" href="' + escapeHtml( moreUrl ) + '">Ver los ' +
						new Intl.NumberFormat( 'es-CL' ).format( total ) + ' resultados</a>';
					state.items = series.concat( items, [ { url: moreUrl } ] );
				}
			}
			open( html );
		}

		// Solo pintar con el input ENFOCADO: una respuesta tardía re-abría el
		// panel ya cerrado por focusout. El mousedown preserva el foco en clicks.
		function inputFocused() {
			return document.activeElement === input;
		}

		// Activación CENTRAL (click Y Enter — paridad teclado/mouse: Enter pulsa
		// la opción, ver el keydown). fill/clear/re = pseudo-items SIN beacon.
		// Las que llevan url son <a> y navegan solas.
		function activate( it ) {
			if ( 'fill' in it ) {
				input.value = it.fill;
				state.lastQuery = it.fill;
				// Emitir input después de asignar value por script para actualizar el botón de limpiar del
				// tema. La lista corta la burbuja del gesto original. No duplicar fetch: lastQuery ya coincide
				// y el handler sale por su guard.
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				fetchSuggest( it.fill );
				return;
			}
			if ( it.clear ) {
				writeHistory( [] );
				renderZero();
				return;
			}
			if ( it.re ) {
				it.re.ts = Date.now();
				writeHistory( [ it.re ].concat( readHistory() ) );
			} else {
				var q = input.value.trim();
				track( q );
				if ( it.thumb ) {
					// Persistir entradas enriquecidas del historial.
					writeHistory( [ { type: 'product', label: it.title, query: q, url: it.url, image: it.thumb, ts: Date.now() } ].concat( readHistory() ) );
				}
			}
			if ( it.url ) {
				cancelPending();
			}
		}

		/** Option del vacío-enfocado. `pfx` = contexto para AT (accname). */
		function zeroOpt( idx, pfx, label, href, img, cls ) {
			var inner = ( img ? '<img class="akb-suggest-thumb" src="' + escapeHtml( img ) + '" alt="" width="40" height="40">' : '' ) +
				( pfx ? '<span class="akb-sr-only">' + pfx + ': </span>' : '' ) + escapeHtml( label );
			var attrs = ' class="akb-suggest-item ' + ( cls || 'akb-suggest-zero' ) + '" role="option" aria-selected="false" id="' +
				panel.id + '-' + idx + '" data-idx="' + idx + '"';
			return href
				? '<a' + attrs + ' href="' + escapeHtml( href ) + '">' + inner + '</a>'
				: '<button type="button"' + attrs + '>' + inner + '</button>';
		}

		// Vacío-enfocado: Recientes + Populares. Heads role=presentation; todos
		// los interactivos = role=option planos del MISMO listbox (APG).
		function renderZero() {
			var rec = readHistory().slice( 0, 5 );
			var pop = ( POP || [] ).slice( 0, 5 );
			if ( ! rec.length && ! pop.length ) {
				if ( ! panel.hidden ) { ocultar(); }
				return;
			}
			var items = [];
			var html = '';
			function head( t ) {
				return '<div class="akb-suggest-head" role="presentation">' + t + '</div>';
			}
			if ( rec.length ) {
				html += head( 'Recientes' );
				rec.forEach( function ( en ) {
					var p = en.type === 'product' && en.url;
					items.push( p ? { url: en.url, re: en } : { fill: en.query } );
					html += zeroOpt( items.length - 1, 'Reciente', en.label, p ? en.url : '', p ? en.image : '' );
				} );
				html += zeroOpt( items.length, '', 'Borrar historial', '', '', 'akb-suggest-clear' );
				items.push( { clear: 1 } );
			}
			if ( pop.length ) {
				html += head( 'Populares' );
				pop.forEach( function ( q ) {
					items.push( { fill: q } );
					html += zeroOpt( items.length - 1, 'Popular', q );
				} );
			}
			state.items = items;
			clearActive();
			panel.removeAttribute( 'aria-busy' );
			open( html );
		}

		// Race-gate de Populares (tardía no pinta).
		function maybeZero() {
			if ( inputFocused() && input.value.trim().length < MIN ) {
				renderZero();
			}
		}

		// Estado vacío ahora, y otra vez cuando lleguen los Populares, salvo
		// que la lista se haya cerrado entretanto (ver close()).
		function zeroConPopulares() {
			maybeZero();
			var gen = state.zeroGen;
			loadPopular( function () {
				if ( gen === state.zeroGen ) {
					maybeZero();
				}
			} );
		}

		// Spinner de campo (.is-fetching en el form; la lupa gira via CSS).
		function fx( on ) {
			wrap.classList.toggle( 'is-fetching', !! on );
		}

		// Antes de submit o activate, cancelar tanto fetch como debounce; abortar sólo la petición
		// deja un timer que puede dispararse durante la navegación.
		function cancelPending() {
			if ( state.controller ) {
				state.controller.abort();
			}
			window.clearTimeout( state.timer );
			window.clearTimeout( state.loadingTimer );
		}

		function fetchSuggest( q ) {
			cancelPending();

			// La clave incluye el contexto del archive: la misma consulta dentro de
			// /categoria-producto/manga/ y en /tienda/ son respuestas distintas.
			var memoKey = q + '\n' + ctxParams();
			var memoHit = memoGet( memoKey );
			if ( null !== memoHit ) {
				fx( false ); // el fetch recién abortado dejó la lupa girando.
				if ( inputFocused() && input.value.trim() === q ) {
					render( memoHit.items || [], q, memoHit.total || 0, memoHit.corrected || null, memoHit.series || [], memoHit.relaxed || null );
				}
				return;
			}

			state.controller = new AbortController();
			var signal = state.controller.signal;
			fx( true );

			state.loadingTimer = window.setTimeout( function () {
				if ( inputFocused() && input.value.trim() === q ) {
					renderLoading();
				}
			}, LOADING_DELAY );

			fetchReintentando(
				REST + 'suggest?q=' + encodeURIComponent( q ) + ctxParams(),
				{
					signal: signal,
					headers: { Accept: 'application/json' },
					credentials: 'same-origin'
				},
				// Sólo mientras la consulta siga siendo la misma. Un tipeo nuevo
				// además aborta el controller, que corta la espera al instante.
				function () { return input.value.trim() === q; },
				function () {
					// El 503 transitorio no es una respuesta: el panel sigue en
					// «Buscando…» mientras se reintenta, y la lupa sigue girando.
					window.clearTimeout( state.loadingTimer );
					if ( inputFocused() && input.value.trim() === q ) {
						renderLoading();
					}
				}
			)
				.then( function ( r ) {
					if ( ! r.ok ) {
						// 429, 5xx ajeno o el 503 transitorio tras el último intento.
						throw new Error( 'suggest_http_' + r.status ); // ≠ "sin resultados".
					}
					return r.json();
				} )
				.then( function ( data ) {
					window.clearTimeout( state.loadingTimer );
					fx( false );
					// Se guarda aunque el foco haya pasado a la lista (Tab a una
					// sugerencia): la respuesta es válida igual y el próximo
					// tecleo la aprovecha. Si el foco salió del buscador, close()
					// ya abortó la petición y no se llega aquí.
					memoSet( memoKey, data );
					if ( inputFocused() && input.value.trim() === q ) {
						render( data.items || [], q, data.total || 0, data.corrected || null, data.series || [], data.relaxed || null );
					}
				} )
				.catch( function ( err ) {
					window.clearTimeout( state.loadingTimer );
					if ( err && 'AbortError' === err.name ) {
						return; // canceló un tipeo más nuevo: su fetch ya re-encendió fx.
					}
					fx( false );
					if ( inputFocused() && input.value.trim() === q ) {
						renderError();
					}
				} );
		}

		/**
		 * Registrar sólo búsquedas asentadas por clic, Enter en sugerencia o submit del formulario. No
		 * registrar cada respuesta de debounce, que representa prefijos parciales. El servidor deriva
		 * hits por su cuenta porque el valor del cliente es falsificable.
		 */
		function track( q, source ) {
			try {
				var payload = new Blob(
					[ JSON.stringify( { q: q, source: source || 'suggest' } ) ],
					{ type: 'application/json' }
				);
				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( REST + 'track', payload );
				}
			} catch ( e ) { /* best-effort */ }
			pushHistory( q );
		}

		function setActive( idx ) {
			// Sincronizar aria-selected de cada role=option con la clase visual y aria-activedescendant.
			// Si idx<0, retirar aria-activedescendant para no referir una option inexistente.
			var opts = panel.querySelectorAll( '.akb-suggest-item' );
			opts.forEach( function ( o ) {
				o.classList.remove( 'is-active' );
				o.setAttribute( 'aria-selected', 'false' );
			} );
			if ( idx >= 0 && opts[ idx ] ) {
				opts[ idx ].classList.add( 'is-active' );
				opts[ idx ].setAttribute( 'aria-selected', 'true' );
				input.setAttribute( 'aria-activedescendant', opts[ idx ].id );
				opts[ idx ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
			state.active = idx;
		}

		// Fetch memoizado de Populares (fallo/vacío = silencioso: solo Recientes).
		function loadPopular( done ) {
			// Mismo reintento que /suggest: la promesa se memoiza por pageload, así
			// que un 503 de la ventana de guardado dejaba la página entera sin
			// Populares aunque el servidor se recuperara a los dos segundos.
			POPQ = POPQ || fetchReintentando( REST + 'popular', { credentials: 'same-origin' } )
				.then( function ( r ) { return r.ok ? r.json() : 0; } )
				.then( function ( d ) {
					POP = ( ( d && d.items ) || [] ).map( function ( i ) { return normTerm( i && i.q ); } ).filter( Boolean );
				} )
				.catch( function () { POP = []; } );
			POPQ.then( done );
		}

		input.addEventListener( 'input', function () {
			var q = input.value.trim();
			if ( q === state.lastQuery ) {
				return;
			}
			state.lastQuery = q;
			window.clearTimeout( state.timer );
			if ( q.length < MIN ) {
				// Vaciar CON foco (incluye botón clear) → vacío-enfocado [gate F1].
				if ( inputFocused() ) {
					zeroConPopulares();
				} else {
					close();
				}
				return;
			}
			state.timer = window.setTimeout( function () { fetchSuggest( q ); }, DEBOUNCE );
		} );

		// Focus: texto re-busca; vacío → zero-state.
		input.addEventListener( 'focus', function () {
			var q = input.value.trim();
			if ( q.length >= MIN ) {
				state.lastQuery = q;
				fetchSuggest( q );
				return;
			}
			zeroConPopulares();
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( panel.hidden ) {
				// Con la lista cerrada, Escape conserva su acción nativa pero cancela todo lo que podría
				// reabrirla, incluida una respuesta pendiente de Populares.
				if ( e.key === 'Escape' ) {
					close();
				}
				return;
			}
			var n = state.items.length;
			// Escape ANTES del guard de vacío: cerrar debe funcionar también con
			// el panel en «Buscando…»/error, que no tienen options navegables.
			if ( e.key === 'Escape' ) {
				// Con la lista abierta, el primer Escape sólo la cierra y conserva la consulta. Con la lista
				// ya cerrada, dejar el Escape nativo de type=search para borrar el texto en Chromium y Safari.
				e.preventDefault();
				close();
				return;
			}
			// Sin options no hay a qué apuntar (y `% 0` daría NaN al índice).
			if ( ! n ) {
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActive( ( state.active + 1 ) % n );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActive( ( state.active - 1 + n ) % n );
			} else if ( e.key === 'Enter' && state.active >= 0 ) {
				e.preventDefault();
				// Enter PULSA la opción activa, que sigue el camino del clic: el
				// <a> navega solo y el Reciente se guarda en un único sitio
				// (el manejador de clic del panel, más abajo).
				var opcion = panel.querySelectorAll( '.akb-suggest-item' )[ state.active ];
				if ( opcion ) {
					opcion.click();
				}
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! wrap.contains( e.target ) ) {
				close();
			}
		} );

		// Cierre por focus-out (APG): Tab fuera dejaba el panel abierto con
		// ARIA colgado [P1 opositor]. Cierra salvo que el foco entre al PANEL
		// (Tab al botón clear/submit también cierra: el combobox perdió el
		// foco). El mousedown previene el blur para que el click de una
		// sugerencia no desmonte su target (gotcha Safari).
		panel.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
		} );
		input.addEventListener( 'focusout', function ( e ) {
			if ( ! e.relatedTarget || ! panel.contains( e.relatedTarget ) ) {
				close();
			}
		} );

		// Click (del dedo, del ratón o de Enter, que pulsa la opción); los <a>
		// navegan solos. stopPropagation: un re-render deja huérfano al target y
		// el click-fuera cerraba el panel. El PJAX del catálogo no depende de
		// este clic: se entera de la salida por la Navigation API, y sin ella
		// un fallo suyo no navega solo (ver vigilarSalida() en akb-search.js).
		panel.addEventListener( 'click', function ( e ) {
			var reqBtn = null;
			if ( e.target && e.target.className && typeof e.target.className === 'string' && e.target.className.indexOf( 'akb-suggest-request' ) !== -1 ) {
				reqBtn = e.target;
			} else if ( e.target && e.target.closest ) {
				var cand = e.target.closest( '.akb-suggest-request-btn, .akb-suggest-request-trigger' );
				if ( cand && cand.className && typeof cand.className === 'string' && cand.className.indexOf( 'akb-suggest-request' ) !== -1 ) {
					reqBtn = cand;
				}
			}
			if ( reqBtn ) {
				e.stopPropagation();
				if ( e.preventDefault ) { e.preventDefault(); }
				var term = ( reqBtn.getAttribute && ( reqBtn.getAttribute( 'data-akb-request-q' ) || reqBtn.getAttribute( 'data-akb-request-term' ) ) ) || input.value.trim();
				close();
				openRequestModal( { title: term, status_intended: 'general' } );
				return;
			}

			e.stopPropagation();
			var el = e.target && e.target.closest && e.target.closest( '.akb-suggest-item' );
			var it = el && state.items[ Number( el.getAttribute( 'data-idx' ) ) ];
			if ( it ) {
				// Solo intercepta con modal sin pago si es explícitamente solicitante (agotado o sin precio).
				// Si es preventa o a pedido con precio, activate(it) navega a la ficha de producto para comprar.
				if ( it.isRequestable === true ) {
					if ( e.preventDefault ) { e.preventDefault(); }
					track( input.value.trim() );
					close();
					openRequestModal( it );
					return;
				}
				activate( it );
			}
		} );
		var ownerForm = input.closest( 'form' );
		if ( ownerForm ) {
			// source:'search' = pool de Populares (queries completas al buscar).
			ownerForm.addEventListener( 'submit', function () {
				cancelPending();
				var q = input.value.trim();
				if ( q.length >= MIN ) {
					track( q, 'search' );
				}
			} );
		}
	}

	var requestModalEl = null;

	function closeRequestModal() {
		if ( requestModalEl ) {
			requestModalEl.hidden = true;
			requestModalEl.classList.remove( 'is-open' );
			if ( document.body && document.body.classList ) {
				document.body.classList.remove( 'akb-modal-open' );
			}
		}
	}

	function getRequestModal() {
		if ( requestModalEl && document.body && document.body.contains( requestModalEl ) ) {
			return requestModalEl;
		}
		var m = document.createElement( 'div' );
		m.id = 'akb-request-modal';
		m.className = 'akb-modal-overlay';
		m.hidden = true;
		m.setAttribute( 'role', 'dialog' );
		m.setAttribute( 'aria-modal', 'true' );
		m.setAttribute( 'aria-labelledby', 'akb-req-modal-title' );
		m.addEventListener( 'click', function ( e ) {
			if ( e.target === m || e.target.closest( '.akb-modal-close' ) ) {
				closeRequestModal();
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! m.hidden ) {
				closeRequestModal();
			}
		} );
		if ( document.body ) {
			document.body.appendChild( m );
		}
		requestModalEl = m;
		return m;
	}

	function openRequestModal( it ) {
		var m = getRequestModal();
		if ( ! m || typeof m.querySelector !== 'function' ) {
			return;
		}
		var title = typeof it === 'string' ? it : ( ( it && it.title ) || '' );
		title = title.replace( /<[^>]*>/g, '' ).trim();
		var status = ( it && it.availability ) || ( ( it && it.badge_tone === 'pre' ) ? 'a_pedido' : ( ( it && it.badge_tone === 'out' ) ? 'agotado' : 'general' ) );
		var q = ( it && it.query ) || title;
		var productId = ( it && it.id ) ? Number( it.id ) : 0;

		var badgeText = 'Solicitud a Pedido';
		var badgeClass = 'akb-req-pill--encargo';
		var helpText = 'Traemos este título directamente con la editorial o distribuidor. Te avisaremos apenas llegue a bodega en Santiago.';

		if ( 'a_pedido' === status ) {
			badgeText = 'Disponible a Pedido (~15-20 días hábiles)';
			badgeClass = 'akb-req-pill--pedido';
			helpText = 'Título disponible en catálogo editorial. Tiempo estimado: 15 a 20 días hábiles. Te avisamos apenas ingrese a bodega.';
		} else if ( 'agotado' === status ) {
			badgeText = 'Aviso de Reposición / Reedición';
			badgeClass = 'akb-req-pill--agotado';
			helpText = 'Este título se encuentra agotado o sin stock inmediato. Déjanos tu aviso y te notificaremos con prioridad ante una reedición o nuevo lote.';
		} else if ( 'preventa' === status ) {
			badgeText = 'Preventa Oficial';
			badgeClass = 'akb-req-pill--preventa';
			helpText = 'Próximo lanzamiento oficial. Registra tu solicitud y te informaremos la fecha de arribo y precio garantizado.';
		}

		m.innerHTML = '<div class="akb-modal-dialog" role="document">' +
			'<button type="button" class="akb-modal-close" aria-label="Cerrar ventana">✕</button>' +
			'<div class="akb-modal-header">' +
				'<span class="akb-modal-badge ' + badgeClass + '">' + escapeHtml( badgeText ) + '</span>' +
				'<h2 id="akb-req-modal-title" class="akb-modal-title">Solicitud de Tomo a Pedido</h2>' +
				'<p class="akb-modal-intro">' + escapeHtml( helpText ) + '</p>' +
			'</div>' +
			'<form class="akb-modal-form" id="akb-request-form">' +
				'<input type="text" name="akb_hp_url" style="display:none!important;" tabindex="-1" autocomplete="off">' +
				'<input type="hidden" name="product_id" value="' + ( productId > 0 ? productId : '' ) + '">' +
				'<input type="hidden" name="status_intended" value="' + escapeHtml( status ) + '">' +
				'<input type="hidden" name="query_norm" value="' + escapeHtml( q ) + '">' +
				'<div class="akb-field-group">' +
					'<label for="akb-req-title">Título solicitado <span class="akb-req-star">*</span></label>' +
					'<input type="text" id="akb-req-title" name="title" required value="' + escapeHtml( title ) + '" placeholder="Ej: InuYasha Kanzenban 01">' +
				'</div>' +
				'<div class="akb-field-group">' +
					'<label for="akb-req-editorial">Editorial / Versión <span class="akb-opt">(opcional)</span></label>' +
					'<input type="text" id="akb-req-editorial" name="editorial" placeholder="Ej: Ivrea, Panini, Planeta, etc.">' +
				'</div>' +
				'<div class="akb-field-row">' +
					'<div class="akb-field-group">' +
						'<label for="akb-req-name">Tu Nombre <span class="akb-req-star">*</span></label>' +
						'<input type="text" id="akb-req-name" name="customer_name" required placeholder="Ej: Camilo Vega">' +
					'</div>' +
					'<div class="akb-field-group">' +
						'<label for="akb-req-contact">WhatsApp o Correo <span class="akb-req-star">*</span></label>' +
						'<input type="text" id="akb-req-contact" name="customer_contact" required placeholder="+56 9 1234 5678 o tu@correo.cl">' +
					'</div>' +
				'</div>' +
				'<div class="akb-modal-actions">' +
					'<button type="submit" class="akb-modal-btn-submit" id="akb-req-submit-btn">' +
						'<span>Confirmar Solicitud</span>' +
					'</button>' +
				'</div>' +
				'<p class="akb-modal-disclaimer">🔒 Sin cobro por adelantado. Cero spam: tus datos sólo se usan para informarte el estado de tu solicitud a pedido.</p>' +
			'</form>' +
		'</div>';

		m.hidden = false;
		m.classList.add( 'is-open' );
		if ( document.body && document.body.classList ) {
			document.body.classList.add( 'akb-modal-open' );
		}

		var form = m.querySelector( '#akb-request-form' );
		var nameInput = m.querySelector( '#akb-req-name' );
		var titleInput = m.querySelector( '#akb-req-title' );
		if ( title && nameInput ) {
			nameInput.focus();
		} else if ( titleInput ) {
			titleInput.focus();
		}

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var submitBtn = form.querySelector( '#akb-req-submit-btn' );
			var productIdVal = form.querySelector( '[name="product_id"]' ) ? form.querySelector( '[name="product_id"]' ).value.trim() : '';
			var titleVal = form.querySelector( '[name="title"]' ) ? form.querySelector( '[name="title"]' ).value.trim() : '';
			var editorialVal = form.querySelector( '[name="editorial"]' ) ? form.querySelector( '[name="editorial"]' ).value.trim() : '';
			var nameVal = form.querySelector( '[name="customer_name"]' ) ? form.querySelector( '[name="customer_name"]' ).value.trim() : '';
			var contactVal = form.querySelector( '[name="customer_contact"]' ) ? form.querySelector( '[name="customer_contact"]' ).value.trim() : '';
			var statusVal = form.querySelector( '[name="status_intended"]' ) ? form.querySelector( '[name="status_intended"]' ).value.trim() : 'general';
			var queryVal = form.querySelector( '[name="query_norm"]' ) ? form.querySelector( '[name="query_norm"]' ).value.trim() : '';
			var hpVal = form.querySelector( '[name="akb_hp_url"]' ) ? form.querySelector( '[name="akb_hp_url"]' ).value : '';

			var payload = {
				product_id: productIdVal ? Number( productIdVal ) : null,
				title: titleVal,
				editorial: editorialVal,
				customer_name: nameVal,
				name: nameVal,
				customer_contact: contactVal,
				contact: contactVal,
				status_intended: statusVal,
				query_norm: queryVal,
				query: queryVal,
				akb_hp_url: hpVal
			};
			if ( ! payload.title || ! payload.customer_name || ! payload.customer_contact ) {
				return;
			}
			submitBtn.disabled = true;
			submitBtn.innerHTML = '<span>Enviando solicitud…</span>';

			fetch( REST + 'request', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( payload )
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					m.querySelector( '.akb-modal-dialog' ).innerHTML = '<button type="button" class="akb-modal-close" aria-label="Cerrar">✕</button>' +
						'<div class="akb-modal-success">' +
							'<div class="akb-success-icon" aria-hidden="true">✓</div>' +
							'<h3 class="akb-success-title">¡Solicitud recibida con éxito!</h3>' +
							'<p class="akb-success-desc">Anotamos tu solicitud a pedido para <strong>' + escapeHtml( payload.title ) + '</strong>. Nuestro equipo verificará el tomo con la editorial y te contactaremos a <strong>' + escapeHtml( payload.customer_contact ) + '</strong> apenas tengamos confirmación.</p>' +
							'<button type="button" class="akb-modal-btn-submit akb-modal-close-trigger">Entendido</button>' +
						'</div>';
					var closeBtn = m.querySelector( '.akb-modal-close-trigger' );
					if ( closeBtn ) {
						closeBtn.addEventListener( 'click', closeRequestModal );
					}
					var closeX = m.querySelector( '.akb-modal-close' );
					if ( closeX ) {
						closeX.addEventListener( 'click', closeRequestModal );
					}
				} else {
					submitBtn.disabled = false;
					submitBtn.innerHTML = '<span>Reintentar envío</span>';
					alert( ( res && res.message ) || 'No se pudo guardar la solicitud. Por favor intenta nuevamente.' );
				}
			} )
			.catch( function () {
				submitBtn.disabled = false;
				submitBtn.innerHTML = '<span>Reintentar envío</span>';
				alert( 'Error de conexión. Por favor intenta nuevamente.' );
			} );
		} );
	}

	function normTerm( s ) {
		return String( s || '' ).replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim();
	}

	// Filtrar al escribir el historial: RUT, celular +56 9, email/URL, número de pedido y
	// secuencias de al menos siete dígitos no deben persistirse ni mostrarse como recientes.
	function looksPrivate( s ) {
		var v = normTerm( s );
		return /https?:|www\.|@/i.test( v ) ||
			/\b\d{1,2}\.?\d{3}\.?\d{3}-?[\dkK]\b/u.test( v ) ||
			/(?:\+?56)?\s*9[\s\-.]?\d{4}[\s\-.]?\d{4}/u.test( v ) ||
			/\b(?:pedido|orden|order|compra)\s*#?\s*\d+/iu.test( v ) ||
			v.replace( /\D+/g, '' ).length >= 7;
	}

	/** Normaliza/valida una entrada (v2 o nueva). null = descartada. */
	function cleanEntry( e ) {
		if ( ! e || typeof e !== 'object' ) { return null; }
		var type = e.type === 'product' ? 'product' : 'query';
		var label = normTerm( e.label || e.query );
		var query = normTerm( e.query || label );
		var ts = Number( e.ts ) || Date.now();
		if ( label.length < 2 || label.length > 90 || query.length < 2 || query.length > 90 ) { return null; }
		if ( looksPrivate( label ) || looksPrivate( query ) ) { return null; }
		if ( Date.now() - ts > HISTORY_MAX_AGE ) { return null; }
		var c = { type: type, label: label, query: query, ts: ts };
		if ( type === 'product' ) {
			var url = String( e.url || '' );
			if ( ! url || /^(?:javascript|data):/i.test( url ) ) { return null; }
			c.url = url;
			var img = String( e.image || '' );
			if ( img && ! /^(?:javascript|data):/i.test( img ) ) { c.image = img; }
		}
		return c;
	}

	function readHistory() {
		try {
			var env = JSON.parse( localStorage.getItem( HISTORY_KEY ) || 'null' );
			if ( ! env || env.v !== 3 || ! Array.isArray( env.items ) ) { return []; }
			// El TTL/privacidad se aplican TAMBIÉN en lectura: cleanEntry solo
			// corría en writeHistory(), así que una sesión que solo navega (sin
			// volver a buscar) seguía pintando «Recientes» vencidos hace meses.
			// Barato: el array está capado a HISTORY_MAX, y como readHistory()
			// alimenta a writeHistory() en activate()/pushHistory(), el purgado
			// se materializa en disco en la primera escritura posterior.
			return env.items.map( cleanEntry ).filter( Boolean );
		} catch ( e ) { return []; }
	}

	/** Sanea, dedupea (type|url|query), ordena ts desc, capa y persiste. */
	function writeHistory( items ) {
		try {
			var seen = {};
			var clean = ( items || [] ).map( cleanEntry ).filter( function ( c ) {
				if ( ! c ) { return false; }
				var k = ( c.type + '|' + ( c.url || c.query ) ).toLowerCase();
				if ( seen[ k ] ) { return false; }
				seen[ k ] = 1;
				return true;
			} );
			clean.sort( function ( a, b ) { return b.ts - a.ts; } );
			localStorage.setItem( HISTORY_KEY, JSON.stringify( { v: 3, items: clean.slice( 0, HISTORY_MAX ) } ) );
		} catch ( e ) { /* localStorage puede fallar (modo privado) */ }
	}

	// Migración idempotente a v3: leer todas las OLD_KEYS antes de borrar, admitir strings u
	// objetos y depurar privacidad/caducidad con cleanEntry. Absorber entradas enriquecidas v2 y
	// antiguas en texto. Eliminar claves anteriores sólo tras confirmar la escritura del envelope
	// v3; si ya existe, no migrar otra vez.
	function migrateHistory() {
		try {
			if ( localStorage.getItem( HISTORY_KEY ) ) { return; }
			var items = [];
			OLD_KEYS.forEach( function ( key ) {
				var stored = JSON.parse( localStorage.getItem( key ) || '[]' );
				if ( ! Array.isArray( stored ) ) { return; }
				stored.forEach( function ( entry ) {
					items.push(
						entry && typeof entry === 'object'
							? entry
							: { type: 'query', label: entry, query: entry, ts: Date.now() }
					);
				} );
			} );
			writeHistory( items );
			if ( localStorage.getItem( HISTORY_KEY ) ) {
				OLD_KEYS.forEach( function ( k ) { localStorage.removeItem( k ); } );
			}
		} catch ( e ) { /* corrupto/privado: el próximo write crea v3 limpio */ }
	}

	function pushHistory( q ) {
		var entry = { type: 'query', label: q, query: q, ts: Date.now() };
		writeHistory( [ entry ].concat( readHistory() ) );
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function boot() {
		migrateHistory();
		// Solo el input marcado por el theme: un selector, un contrato.
		document.querySelectorAll( '[data-akb-suggest]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
