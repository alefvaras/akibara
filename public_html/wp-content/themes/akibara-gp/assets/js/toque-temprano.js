/*!
 * Akibara GP — toque-temprano.js (en línea, en el <head>; ver inc/enqueue.php)
 *
 * Captura y preserva interacciones de usuario que ocurren antes de que los
 * scripts diferidos inicialicen sus manejadores de eventos, reejecutándolas
 * una vez completada la inicialización del DOM y librerías.
 *
 * Contrato de interacción:
 * - Oyente de `click` en fase de captura sobre document. Retiene clics prematuros
 *   sobre controles interactivos (añadir al carrito, búsqueda, menú móvil).
 * - Los controles se marcan como ocupados (`akb-toque-pendiente`) proporcionando
 *   retroalimentación inmediata al usuario para evitar pulsaciones reiteradas.
 * - Despacho de cola al completarse `DOMContentLoaded` (y jQuery ready diferido):
 *   * Acciones de compra («Añadir al carrito»): se preservan en orden, deduplicadas
 *     por identificador de producto.
 *   * Controles de navegación y búsqueda: se procesa únicamente el último toque.
 *   * Limpieza exhaustiva de marcas visuales de espera al liberar la cola.
 *
 * Protección contra envíos duplicados en fichas de producto (form.cart):
 * - Envíos sincrónicos nativos marcan el formulario como `inert` previniendo clics
 *   duplicados antes de que tome el control `pdp-ajax-atc.js`.
 * - Vencimiento por temporizador seguro (`PLAZO_ENVIO`) para restaurar interactividad
 *   en caso de interrupción de navegación, error de red o cancelaciones manuales.
 *
 * ES5 autónomo sin dependencias para ejecución inmediata en el head.
 */
( function ( d, w ) {
	'use strict';

	var COMPRA = '.ajax_add_to_cart';
	var DESTINOS = '#menu-toggle, #bottom-nav-search, ' + COMPRA;
	var MARCA = 'akb-toque-pendiente';
	var FICHA = 'form.cart';
	var PLAZO_ENVIO = 30000;
	var compras = [];
	var cajon = null;
	var listo = false;
	var plazo = 0;

	function marcar( el ) {
		el.classList.add( MARCA );
		el.setAttribute( 'aria-busy', 'true' );
	}

	function desmarcar( el ) {
		el.classList.remove( MARCA );
		el.removeAttribute( 'aria-busy' );
	}

	function yaEnCola( el ) {
		var id = el.getAttribute( 'data-product_id' );
		for ( var i = 0; i < compras.length; i++ ) {
			if ( compras[ i ] === el || ( id && compras[ i ].getAttribute( 'data-product_id' ) === id ) ) {
				return true;
			}
		}
		return false;
	}

	function captar( e ) {
		if ( listo ) {
			return;
		}
		var destino = e.target && e.target.closest ? e.target.closest( DESTINOS ) : null;
		if ( ! destino ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		// Cualquier toque posterior deja sin efecto el del cajón pendiente.
		if ( cajon && cajon !== destino ) {
			desmarcar( cajon );
			cajon = null;
		}
		if ( destino.closest( COMPRA ) === destino ) {
			if ( ! yaEnCola( destino ) ) {
				compras.push( destino );
			}
		} else {
			cajon = destino;
		}
		marcar( destino );
	}

	function soltar() {
		listo = true;
		d.removeEventListener( 'click', captar, true );
		var cola = cajon ? compras.concat( [ cajon ] ) : compras;
		compras = [];
		cajon = null;
		Array.prototype.forEach.call( d.querySelectorAll( '.' + MARCA ), desmarcar );
		for ( var i = 0; i < cola.length; i++ ) {
			// El nodo pudo desaparecer (el buscador repinta el listado) o quedar
			// deshabilitado: entonces no hay a quién repetirle el toque.
			if ( d.documentElement.contains( cola[ i ] ) && ! cola[ i ].disabled ) {
				cola[ i ].click();
			}
		}
	}

	function arrancado() {
		var $ = w.jQuery;
		if ( 'function' === typeof $ ) {
			$( function () {
				w.setTimeout( soltar, 0 );
			} );
			return;
		}
		w.setTimeout( soltar, 0 );
	}

	function alEnviar( e ) {
		var form = e.target;
		if ( ! e.defaultPrevented && form && form.matches && form.matches( FICHA ) ) {
			form.setAttribute( 'inert', '' );
			w.clearTimeout( plazo );
			plazo = w.setTimeout( devolverFicha, PLAZO_ENVIO );
		}
	}

	/*
	 * Escucha los envíos, y vuelve a hacerlo cada vez que el formulario vuelve
	 * (VERIF-Z-1). WebKit, si se detiene la carga con la página aún cargando y el
	 * POST de camino, deja de avisar a los oyentes registrados ANTES del stop, éste
	 * incluido; los relojes siguen, y un oyente registrado después sí oye (medido en
	 * el espejo, webkit-2342, escritorio y táctil; con la página ya cargada, o en
	 * Chromium, el oyente sigue vivo). Sin esto el formulario salía de `inert` sin
	 * nadie que lo volviera a poner, y el reintento en doble toque mandaba dos altas
	 * (9 de 9). Quitar y volver a poner la misma función: donde sigue vivo, no queda
	 * repetido.
	 */
	function escucharEnvios() {
		d.removeEventListener( 'submit', alEnviar );
		d.addEventListener( 'submit', alEnviar );
	}

	// La página sigue aquí: el formulario de la ficha vuelve a comprar, protegido.
	function devolverFicha() {
		w.clearTimeout( plazo );
		escucharEnvios();
		Array.prototype.forEach.call( d.querySelectorAll( FICHA + '[inert]' ), function ( form ) {
			form.removeAttribute( 'inert' );
		} );
	}

	escucharEnvios();
	w.addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			devolverFicha();
		}
	} );

	d.addEventListener( 'click', captar, true );
	if ( 'loading' === d.readyState ) {
		d.addEventListener( 'DOMContentLoaded', arrancado );
	} else {
		arrancado();
	}
}( document, window ) );
