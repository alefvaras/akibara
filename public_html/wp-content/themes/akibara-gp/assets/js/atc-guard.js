/**
 * Add-to-cart de las tarjetas (loop/PLP/home/404/carrito vacío):
 * Control de concurrencia, doble disparo, rechazo del servidor y contingencia de red.
 *
 * Modelo de estados y responsabilidades:
 *
 *  - INTENTO: representa cada petición lanzada a WooCommerce iniciada en `adding_to_cart`.
 *    El prefiltro asocia la petición a su botón mediante la referencia compartida de `data`,
 *    garantizando aislamiento incluso si las peticiones se encolan secuencialmente.
 *  - BOTÓN: WooCommerce añade clase `loading` al activarlo. Un botón con alta pendiente
 *    no envía peticiones concurrentes. Tras completar el ciclo (éxito, error o abort),
 *    el estado vuelve a reposo para permitir nuevas interacciones.
 *  - TOMO (id de producto): gestiona el estado del producto (`ultimoDelTomo`), coordinando
 *    tarjetas en grilla y vistas rápidas para actualizar avisos, transformar botones a
 *    «Ver carrito» o liberar candados (`inFlight`).
 *
 * Flujo de control:
 *   1. Doble disparo: Se bloquean peticiones concurrentes del mismo producto mientras
 *      hay una en vuelo mediante el filtro `should_send_ajax_request.adding_to_cart`.
 *      El candado es por producto con timeout de seguridad defensivo.
 *   2. Rechazo del servidor: Intercepción de `response.error` vía `$.ajaxPrefilter`
 *      para consultar el motivo detallado (`akb_get_cart&drain_notices=1`) y mostrarlo
 *      directamente en la tarjeta sin redirigir innecesariamente a la ficha.
 *   3. Enlace «Ver carrito»: Cuando el motivo indica producto ya presente en carrito
 *      (`ya_en_carrito`), el botón muta a enlace accesible hacia el carrito hasta
 *      que una actualización de fragments restablece el estado.
 *   4. Notificación de interfaz: Emite evento personalizado `akb:tarjeta-motivo` para
 *      que elementos flotantes (ej. botón de soporte) coordinen su visibilidad y eviten solapes.
 *   5. Contingencia de red y errores: Gestión de fallos de conexión restableciendo
 *      el estado del botón y mostrando aviso accesible para permitir reintentos.
 *   6. Resolución secuencial: El último intento activo del tomo gobierna la presentación
 *      de avisos y sincroniza todas las representaciones visuales del producto.
 *
 * @package akibara-gp
 */
( function () {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	var $ = window.jQuery;

	// Candado contra el doble disparo, por tomo → { productId: timeoutHandle }.
	var inFlight = {};

	// Último intento de cada tomo → { productId: intento }: el que habla.
	var ultimoDelTomo = {};

	// Último intento de cada botón: el único que le quita el `loading`.
	var ultimoDelBoton = new WeakMap();

	// El intento de cada petición, por su objeto `data`.
	var intentoDe = new WeakMap();

	// El intento cuya alta anuncia WooCommerce en este momento: `added_to_cart`
	// se dispara dentro del `success` de su petición.
	var terminando = null;

	// Cuánto se ve el motivo sobre la tarjeta: lo mismo que en la ficha.
	var DURACION_MOTIVO = 6000;

	// Tarjetas cuyo botón pasó a «Ver carrito»: { boton, enlace } para poder
	// devolverles el botón cuando el carrito cambie.
	var enVerCarrito = [];

	function productId( $button ) {
		if ( ! $button ) {
			return '';
		}
		var id = $button.data ? $button.data( 'product_id' ) : undefined;
		if ( id === undefined || id === null || id === '' ) {
			id = $button.attr ? $button.attr( 'data-product_id' ) : undefined;
		}
		return ( id === undefined || id === null ) ? '' : String( id );
	}

	// Los «Añadir» del tomo que hay en la página: sus tarjetas y el clon de la vista rápida.
	function botonesDelTomo( id ) {
		return Array.prototype.filter.call( document.querySelectorAll( '.ajax_add_to_cart' ), function ( b ) {
			return String( b.getAttribute( 'data-product_id' ) ) === id;
		} );
	}

	// ¿Tiene el botón su propia alta pendiente (en vuelo o en la cola)?
	function altaPropia( boton ) {
		var intento = boton ? ultimoDelBoton.get( boton ) : null;
		return !! intento && intento.pendiente;
	}

	function release( id ) {
		if ( ! id || ! Object.prototype.hasOwnProperty.call( inFlight, id ) ) {
			return;
		}
		if ( inFlight[ id ] ) {
			window.clearTimeout( inFlight[ id ] );
		}
		delete inFlight[ id ];
	}

	$( document.body ).on(
		'should_send_ajax_request.adding_to_cart',
		function ( event, $button ) {
			var id = productId( $button );
			if ( ! id ) {
				// Sin id no podemos discriminar por producto → no bloqueamos
				// (mejor permitir el add que descartar uno legítimo).
				return true;
			}
			// Mismo producto ya en vuelo → bloquear este click (sin navegar). Y un
			// botón con su alta pendiente no manda otra aunque el candado haya
			// vencido (modelo, BOTÓN).
			if ( Object.prototype.hasOwnProperty.call( inFlight, id ) || altaPropia( $button[ 0 ] ) ) {
				return false;
			}
			// Safety net: liberar ESTE id a los 2.5s aunque no llegue `added_to_cart`.
			inFlight[ id ] = window.setTimeout( function () {
				release( id );
			}, 2500 );
			return true;
		}
	);

	// WooCommerce ya decidió enviar: nace el intento. Aquí y no en el filtro de
	// arriba, porque otro oyente de ese filtro todavía podía vetar el alta.
	$( document.body ).on(
		'adding_to_cart',
		function ( event, $button, data ) {
			var id = productId( $button );
			if ( ! id || ! $button[ 0 ] || ! data || 'object' !== typeof data ) {
				return;
			}
			// Pendiente hasta su `success` o su `error` (el prefiltro de abajo).
			var intento = { $button: $button, id: id, pendiente: true };
			ultimoDelTomo[ id ] = intento;
			ultimoDelBoton.set( $button[ 0 ], intento );
			intentoDe.set( data, intento );
			// Lo que digan los avisos de este tomo era del intento anterior.
			ocultarMotivos( id );
		}
	);

	$( document.body ).on(
		'added_to_cart',
		function ( event, fragments, cart_hash, $button ) {
			var id = productId( $button );
			// Un intento que otro del tomo reemplazó no habla: el candado y los
			// avisos son del que sigue en la cola (modelo, TOMO).
			if ( ! id || ( terminando && terminando.id === id && ultimoDelTomo[ id ] !== terminando ) ) {
				return;
			}
			release( id );
			ocultarMotivos( id );
			// Vuelven los botones del tomo sin alta propia: el clon de la vista
			// rápida que copió el giro de la tarjeta en vuelo se queda en el diálogo,
			// que se cierra con el alta pero no se va (modelo, BOTÓN).
			botonesDelTomo( id ).forEach( function ( b ) {
				if ( ! altaPropia( b ) ) {
					b.classList.remove( 'loading' );
				}
			} );
		}
	);

	// WooCommerce (updateButton) retira `loading` al bloquear la petición por candado;
	// restablece el spinner si la transacción correspondiente sigue pendiente en vuelo.
	$( document.body ).on(
		'wc_cart_button_updated',
		function ( event, $button ) {
			var boton = $button && $button[ 0 ];
			var delTomo = boton ? ultimoDelTomo[ productId( $button ) ] : null;
			if ( altaPropia( boton ) || ( delTomo && delTomo.pendiente && ! boton.closest( 'li.product' ) ) ) {
				$button.removeClass( 'added' ).addClass( 'loading' );
			}
		}
	);

	// Región de estado propia, creada al cargar: una región que nace junto con
	// su texto no la anuncian todos los lectores de pantalla.
	var region = document.createElement( 'div' );
	region.className = 'screen-reader-text';
	region.setAttribute( 'role', 'status' );
	region.setAttribute( 'aria-live', 'polite' );
	region.setAttribute( 'aria-atomic', 'true' );
	document.body.appendChild( region );

	function urlEndpoint( endpoint ) {
		var base = window.wc_add_to_cart_params && window.wc_add_to_cart_params.wc_ajax_url
			? String( window.wc_add_to_cart_params.wc_ajax_url )
			: '/?wc-ajax=%%endpoint%%';
		return base.replace( '%%endpoint%%', endpoint );
	}

	// Aire entre el motivo y el «Ver carrito» que queda debajo (px).
	var AIRE_SOBRE_BOTON = 4;

	/**
	 * Distancia, desde el pie de la tarjeta, a la que tiene que terminar el
	 * motivo para quedar ENCIMA del botón y no taparlo. `bottom` se mide desde el
	 * borde interior del `li.product` (su caja de relleno), así que se descuenta
	 * su borde inferior.
	 */
	function hastaEncimaDe( tarjeta, boton ) {
		var t = tarjeta.getBoundingClientRect();
		var b = boton.getBoundingClientRect();
		var borde = parseFloat( window.getComputedStyle( tarjeta ).borderBottomWidth ) || 0;
		return Math.max( 0, Math.round( t.bottom - borde - b.top + AIRE_SOBRE_BOTON ) );
	}

	/**
	 * Pinta el motivo sobre el pie de la tarjeta sin desalinear la rejilla de productos.
	 *
	 * Se posiciona absolutamente dentro del `li.product` para evitar variaciones de altura.
	 * Con `boton` presente, se sitúa inmediatamente sobre él dejándolo visible y operable.
	 * Utiliza nodos DOM nativos (textContent/appendChild) previniendo inyecciones HTML.
	 */
	function pintarMotivo( donde, id, texto, cartUrl, boton ) {
		// Se calcula la posición antes de insertar para evitar saltos de renderizado.
		var abajo = boton ? hastaEncimaDe( donde, boton ) + 'px' : '';
		var caja = donde.querySelector( '.akb-tarjeta-motivo' );
		if ( ! caja ) {
			caja = document.createElement( 'p' );
			caja.className = 'akb-tarjeta-motivo';
			donde.appendChild( caja );
		}
		caja.akbTomo = id;
		caja.style.bottom = abajo;
		caja.textContent = '';
		caja.appendChild( document.createTextNode( texto ) );
		if ( cartUrl ) {
			var a = document.createElement( 'a' );
			a.href = cartUrl;
			// El mismo rótulo que WooCommerce pone junto a la tarjeta tras un alta.
			a.textContent = ( window.wc_add_to_cart_params && window.wc_add_to_cart_params.i18n_view_cart ) || 'Ver carrito';
			caja.appendChild( a );
		}
		caja.hidden = false;
		region.textContent = '';
		region.textContent = texto;
		avisarCambioDeMotivo();

		window.clearTimeout( caja.akbTemporizador );
		caja.akbTemporizador = window.setTimeout( function () {
			caja.hidden = true;
			avisarCambioDeMotivo();
		}, DURACION_MOTIVO );
		return caja;
	}

	// Notificación escuchada por main.js para coordinar visibilidad de elementos flotantes.
	function avisarCambioDeMotivo() {
		document.dispatchEvent( new window.CustomEvent( 'akb:tarjeta-motivo' ) );
	}

	/**
	 * Retira los avisos a la vista. Sin `id`, todos: el carrito cambió y
	 * cualquiera de ellos («has añadido 1 en tu carrito») puede haber dejado de
	 * ser verdad, pasara o no su botón a «Ver carrito». Con `id`, los de ese tomo
	 * en todas las tarjetas donde salga y en la vista rápida (punto 6): los de los
	 * demás siguen siendo verdad.
	 */
	function ocultarMotivos( id ) {
		var retirados = 0;
		var visibles = document.querySelectorAll( '.akb-tarjeta-motivo:not([hidden])' );
		Array.prototype.forEach.call( visibles, function ( caja ) {
			if ( id && caja.akbTomo !== id ) {
				return;
			}
			window.clearTimeout( caja.akbTemporizador );
			caja.hidden = true;
			retirados++;
		} );
		if ( retirados ) {
			avisarCambioDeMotivo();
		}
	}

	/**
	 * El botón de la tarjeta deja de agregar y pasa a ser un ENLACE al carrito.
	 *
	 * Se reemplaza por un `<a>` y no se le cambia el `href`: WooCommerce pinta el
	 * «Añadir» de las tarjetas como `<button type="button">` (medido en el
	 * espejo), y un botón con `href` no navega. Conserva las clases de aspecto
	 * (`button`, `product_type_*`) y pierde las que WooCommerce escucha
	 * (`ajax_add_to_cart`, `add_to_cart_button`), así que nadie lo intercepta. Si
	 * tenía el foco (teclado), el foco pasa al enlace: no se pierde en el body.
	 */
	function botonVerCarrito( $button, cartUrl ) {
		var original = $button[ 0 ];
		if ( ! original || ! original.parentNode ) {
			return null;
		}
		var rotulo = ( window.wc_add_to_cart_params && window.wc_add_to_cart_params.i18n_view_cart ) || 'Ver carrito';
		var enlace = document.createElement( 'a' );
		enlace.className = String( original.className || '' )
			.split( /\s+/ )
			.filter( function ( c ) {
				return c && -1 === [ 'ajax_add_to_cart', 'add_to_cart_button', 'loading', 'added' ].indexOf( c );
			} )
			.concat( 'akb-ver-carrito' )
			.join( ' ' );
		enlace.href = cartUrl;
		enlace.textContent = rotulo;
		var teniaFoco = document.activeElement === original;
		original.parentNode.replaceChild( enlace, original );
		enVerCarrito.push( { boton: original, enlace: enlace } );
		if ( teniaFoco && enlace.focus ) {
			enlace.focus();
		}
		return enlace;
	}

	/**
	 * El carrito cambió: cada «Ver carrito» vuelve a ser el botón de agregar.
	 *
	 * Es el MISMO nodo que WooCommerce pintó, así que su clic delegado
	 * (`.ajax_add_to_cart` en el body) vuelve a funcionar sin nada más. Sin
	 * `added` ni `loading`: el ✓ de loop.css diría «agregado» de un tomo que el
	 * cliente acaba de quitar. Si el tomo sigue entero en el carrito (el cajón
	 * cambió OTRA línea), el siguiente toque se rechaza y vuelve a «Ver
	 * carrito», como en la ficha. El foco, si estaba en el enlace, pasa al botón.
	 */
	function devolverBotones() {
		var pendientes = enVerCarrito;
		enVerCarrito = [];
		pendientes.forEach( function ( par ) {
			var enlace = par.enlace;
			if ( ! enlace.parentNode ) {
				return; // la tarjeta ya no está (p. ej. el buscador cambió la rejilla).
			}
			var teniaFoco = document.activeElement === enlace;
			par.boton.className = String( par.boton.className || '' )
				.split( /\s+/ )
				.filter( function ( c ) {
					return c && 'added' !== c && 'loading' !== c;
				} )
				.join( ' ' );
			enlace.parentNode.replaceChild( par.boton, enlace );
			if ( teniaFoco && par.boton.focus ) {
				par.boton.focus();
			}
		} );
	}

	// Los mismos avisos que escucha la ficha: native-cart.js dispara
	// `wc_fragment_refresh` al quitar o cambiar una línea del cajón; el
	// mini-carrito de WooCommerce, `removed_from_cart`.
	$( document.body ).on( 'wc_fragment_refresh removed_from_cart', function () {
		ocultarMotivos();
		devolverBotones();
	} );

	/**
	 * El servidor rechazó el alta del intento que habla.
	 *
	 * `habla` se vuelve a mirar cuando llega el motivo: si mientras tanto salió
	 * otro intento del tomo, ése dirá lo suyo, y este botón no pasa a «Ver
	 * carrito» a mitad de una petición.
	 */
	function rechazo( intento, tarjeta, response, exito, contexto, args, habla ) {
		var id = intento.id;
		release( id );

		// Sin motivo que mostrar, el camino de WooCommerce: al menos la ficha
		// enseña el aviso. Nunca el falso éxito sin `product_url`.
		function alCaminoDeWoo() {
			if ( response.product_url && habla() ) {
				exito.apply( contexto, args );
			}
		}

		if ( ! tarjeta ) {
			alCaminoDeWoo(); // la vista rápida: no hay tarjeta donde pintar.
			return;
		}

		// Las tarjetas agregan de a una unidad (add-to-cart.js de WooCommerce).
		var pedido = '&product_id=' + encodeURIComponent( id ) + '&quantity=1';
		window.fetch( urlEndpoint( 'akb_get_cart' ) + '&drain_notices=1' + pedido, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( cart ) {
				if ( ! habla() ) {
					return; // el cliente ya volvió a tocar: habla ese intento.
				}
				var motivo = cart && cart.data && cart.data.errors && cart.data.errors[ 0 ];
				if ( ! motivo ) {
					alCaminoDeWoo();
					return;
				}
				var urlCarrito = cart.data.cart_url || '';
				if ( 'ya_en_carrito' === cart.data.motivo && urlCarrito ) {
					// El botón ya es el enlace: el motivo va sin el suyo, y
					// encima del botón, no sobre él.
					pintarMotivo( tarjeta, id, motivo, '', botonVerCarrito( intento.$button, urlCarrito ) );
					return;
				}
				pintarMotivo( tarjeta, id, motivo, urlCarrito );
			} )
			.catch( alCaminoDeWoo );
	}

	// El mismo texto que anuncia la ficha con la red caída (pdp-ajax-atc.js).
	var MOTIVO_SIN_RESPUESTA = 'No se pudo conectar, reintenta';

	/**
	 * La petición del intento que habla no llegó a responder (o respondió algo
	 * que no es JSON).
	 *
	 * El candado de ese producto se suelta ya, no a los 2,5 s: el cliente
	 * reintenta con el mismo botón. El aviso termina justo encima del botón y no
	 * lo tapa. Si la petición la canceló el propio código (`abort`), no hay nada
	 * que explicarle al cliente. Fuera de una tarjeta (la vista rápida), el aviso
	 * va junto al botón y en las tarjetas del tomo (punto 5).
	 */
	function sinRespuesta( intento, tarjeta, estado ) {
		var id = intento.id;
		var boton = intento.$button[ 0 ];
		release( id );
		if ( 'abort' === estado ) {
			return;
		}
		if ( tarjeta ) {
			pintarMotivo( tarjeta, id, MOTIVO_SIN_RESPUESTA, '', boton );
			return;
		}
		if ( boton.parentNode ) {
			// Sin reloj: se queda hasta que el cliente actúa (punto 5).
			var aviso = pintarMotivo( boton.parentNode, id, MOTIVO_SIN_RESPUESTA, '', null );
			aviso.setAttribute( 'role', 'alert' );
			window.clearTimeout( aviso.akbTemporizador );
		}
		botonesDelTomo( id ).forEach( function ( b ) {
			var suya = b.closest( 'li.product' );
			if ( suya ) {
				pintarMotivo( suya, id, MOTIVO_SIN_RESPUESTA, '', b );
			}
		} );
	}

	$.ajaxPrefilter( function ( options, originalOptions ) {
		if ( ! options.url || -1 === String( options.url ).indexOf( 'wc-ajax=add_to_cart' ) ) {
			return;
		}
		var datos = originalOptions && originalOptions.data;
		var intento = datos && 'object' === typeof datos ? intentoDe.get( datos ) : null;
		if ( ! intento ) {
			return; // no la lanzó un botón de WooCommerce: no es nuestra.
		}
		var boton = intento.$button[ 0 ];
		var tarjeta = boton.closest ? boton.closest( 'li.product' ) : null;

		function habla() {
			return ultimoDelTomo[ intento.id ] === intento;
		}

		// El intento terminó sin alta: su botón vuelve (no tiene otra en la cola:
		// un botón con su alta pendiente no manda otra). WooCommerce sólo quita
		// `loading` en `added_to_cart`, que aquí no llega, y loop.css deja sin
		// puntero al botón que lo lleva. Si era el último del tomo, vuelven todos
		// los del tomo (modelo, BOTÓN).
		function devolverBoton() {
			intento.$button.removeClass( 'loading' );
			if ( habla() ) {
				botonesDelTomo( intento.id ).forEach( function ( b ) {
					b.classList.remove( 'loading' );
				} );
			}
		}

		// WooCommerce no pasa `error` (add-to-cart.js); si alguien lo hiciera,
		// se le sigue llamando.
		var fallo = options.error;
		options.error = function ( jqXHR, estado ) {
			intento.pendiente = false;
			devolverBoton();
			if ( habla() ) {
				sinRespuesta( intento, tarjeta, estado );
			}
			if ( 'function' === typeof fallo ) {
				return fallo.apply( this, arguments );
			}
		};

		if ( 'function' !== typeof options.success ) {
			return;
		}
		var exito = options.success;
		options.success = function ( response ) {
			intento.pendiente = false;
			// Sin fetch no se puede pedir el motivo: el camino de WooCommerce.
			if ( ! response || ! response.error || ! window.fetch ) {
				terminando = intento;
				try {
					return exito.apply( this, arguments );
				} finally {
					terminando = null;
				}
			}
			devolverBoton();
			if ( habla() ) {
				rechazo( intento, tarjeta, response, exito, this, arguments, habla );
			}
			// Si no habla, dirá lo suyo el intento que sigue en la cola; la ficha, nunca.
		};
	} );
}() );
