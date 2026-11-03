/*!
 * Akibara GP — pdp-ajax-atc.js (solo PDP)
 *
 * AJAX add-to-cart en single product. Intercepta el submit de
 * form.cart y postea a wc-ajax=add_to_cart (endpoint WC core): sin reload,
 * fragments actualizados (badge header, mini-cart, FSP) y estado en el botón
 * real Y en el sticky proxy. Dispara el evento jQuery `added_to_cart`
 * (NativeCart lo escucha para refresh/auto-open del drawer).
 *
 * Accesibilidad: el resultado se anuncia en una región role="status" dedicada
 * (no con aria-live sobre el propio botón). Copy según estado de stock:
 * "Reservando…/Reservado" en preventa, "Agregando…/Agregado" en stock.
 *
 * Solo productos simples (todo el catálogo). Rechazo del servidor (stock, ya
 * en el carrito) → motivo presentado junto al botón activo o en la barra de compra
 * fija si está en pantalla; la recarga queda solo como último recurso.
 * Si el servidor indica que el carrito ya contiene todo el stock disponible
 * (código `ya_en_carrito`), los botones pasan a «Ver carrito».
 * Cuando el carrito cambia (actualización de fragments), se restablece el estado.
 * Error de red → estado "Reintentar" inline sin recargar.
 */
(function () {
	'use strict';

	var form = document.querySelector('form.cart');
	if (!form) return;
	var btn = form.querySelector('.single_add_to_cart_button');
	var pidField = form.querySelector('[name="add-to-cart"]');
	if (!btn || !pidField || !pidField.value) return;

	var stickyBar = document.getElementById('akb-sticky-atc');
	var stickyBtn = document.getElementById('akb-sticky-atc-btn');
	var busy = false;

	// UN solo reinicio pendiente. Con uno por envío, el del alta anterior (2 s)
	// caía en medio del rechazo siguiente y escondía su motivo a 1,3 s de
	// aparecer en vez de a los 6 (medido en el espejo: agregar, cerrar el cajón
	// y volver a pulsar antes de 2 s).
	var temporizador = 0;
	function programarReset(ms, soloSi) {
		clearTimeout(temporizador);
		temporizador = setTimeout(function () {
			if (!soloSi || soloSi()) { reset(); }
		}, ms);
	}

	// Preventa: el CTA dice "Reservar en Preventa" (filtros del plugin). Ajusta
	// el copy de los estados para que coincida con el lenguaje del botón.
	var isPreorder = /reserv/i.test(btn.textContent || '');
	var labels = isPreorder
		? { adding: 'Reservando…', done: 'Reservado ✓', sr: 'Producto reservado en preventa' }
		: { adding: 'Agregando…', done: 'Agregado ✓', sr: 'Producto agregado al carrito' };

	// Región de estado dedicada para lectores de pantalla.
	var live = document.createElement('div');
	live.className = 'screen-reader-text';
	live.setAttribute('role', 'status');
	live.setAttribute('aria-live', 'polite');
	live.setAttribute('aria-atomic', 'true');
	form.appendChild(live);
	function announce(msg) { live.textContent = ''; live.textContent = msg; }

	var originalText = btn.textContent;
	var stickyOriginal = stickyBtn ? stickyBtn.textContent : '';

	// A dónde lleva el botón mientras está en «Ver carrito» ('' = compra normal).
	var destinoCarrito = '';

	// Sin respuesta del servidor (red caída, 500, algo que no es JSON). El mismo
	// texto que la tarjeta (MOTIVO_SIN_RESPUESTA de atc-guard.js): la ficha y el
	// listado dicen lo mismo ante el mismo fallo.
	var MOTIVO_SIN_RESPUESTA = 'No se pudo conectar, reintenta';

	// El rótulo que WooCommerce usa para ir al carrito: el mismo que ponen las
	// tarjetas (atc-guard.js), así que la ficha y el listado dicen lo mismo.
	function rotuloCarrito() {
		return (window.wc_add_to_cart_params && window.wc_add_to_cart_params.i18n_view_cart) || 'Ver carrito';
	}

	// Sin `botones`, el estado va a los dos: el real y el de la barra. Cada
	// estado sustituye al anterior, «Ver carrito» incluido: si se quedara, al
	// volver a comprar el botón seguiría negro y secundario (R-I1-1).
	//
	// También se va `added`: WooCommerce (add-to-cart.js) la pone al oír
	// `added_to_cart` y su hoja pinta una ✓ en `::after` que no se quitaba
	// nunca. Medido en el espejo: «AGREGADO ✓ ✓», y después «AÑADIR AL CARRITO
	// ✓», «NO SE PUDO AGREGAR ✓» y «VER CARRITO ✓» (R-I1-3). Los rótulos de
	// este archivo ya llevan su propia ✓ cuando toca.
	function setState(cls, text, botones) {
		(botones || [btn, stickyBtn]).forEach(function (b) {
			if (!b) return;
			b.classList.remove('is-adding', 'is-added', 'is-error', 'is-ver-carrito', 'added');
			if (cls) b.classList.add(cls);
			if (text !== undefined) b.textContent = text;
		});
	}

	// Devuelve un botón a su estado de reposo, con su etiqueta de siempre.
	function restaurar(b) {
		if (!b) return;
		setState(null, b === btn ? originalText : stickyOriginal, [b]);
	}

	function ocultarMotivos() {
		[form.querySelector('.akb-atc-motivo'), stickyBar && stickyBar.querySelector('.akb-sticky-atc__motivo')].forEach(function (caja) {
			if (caja) { caja.hidden = true; }
		});
	}

	function reset() {
		// En «Ver carrito» los botones se quedan así: sólo el carrito lo deshace.
		if (!destinoCarrito) {
			restaurar(btn);
			restaurar(stickyBtn);
		}
		ocultarMotivos();
		busy = false;
	}

	/**
	 * El tomo ya está en el carrito con todo lo que queda: los DOS botones dicen
	 * «Ver carrito» y llevan ahí (el de la barra pulsa el real, que envía el form,
	 * y el envío navega). Los dos y no sólo el que falló: los dos repetirían el
	 * mismo rechazo, y un «Añadir» que lleva al carrito sería mentira.
	 */
	function verCarrito(url) {
		destinoCarrito = url;
		setState('is-ver-carrito', rotuloCarrito());
	}

	function motivoALaVista() {
		return [form.querySelector('.akb-atc-motivo'), stickyBar && stickyBar.querySelector('.akb-sticky-atc__motivo')].some(function (caja) {
			return !!caja && !caja.hidden;
		});
	}

	// Al modificar el contenido del carrito, se restablecen los estados de error
	// y avisos obsoletos para permitir nuevos intentos de compra.
	if (window.jQuery) {
		window.jQuery(document.body).on('wc_fragment_refresh removed_from_cart', function () {
			var enError = [btn, stickyBtn].some(function (b) {
				return !!b && b.classList.contains('is-error');
			});
			if (!destinoCarrito && !enError && !motivoALaVista()) return;
			destinoCarrito = '';
			clearTimeout(temporizador);
			[btn, stickyBtn].forEach(function (b) {
				if (b && (b.classList.contains('is-error') || b.classList.contains('is-ver-carrito'))) {
					restaurar(b);
				}
			});
			ocultarMotivos();
		});
	}

	// La visibilidad efectiva requiere que la barra esté marcada activa y
	// renderizada en el DOM (getClientRects > 0), descartando estados donde
	// media queries de baja altura la ocultan con display:none.
	function barraALaVista() {
		return !!(stickyBar && stickyBtn && stickyBar.classList.contains('is-visible')
			&& stickyBar.getClientRects().length > 0);
	}

	/**
	 * Pinta el motivo del rechazo donde el cliente lo ve, sin recargar.
	 *
	 * Con la barra de compra a la vista (`enBarra`) el motivo va dentro de la
	 * barra para mantener la retroalimentación en el viewport actual del usuario.
	 * Sin barra, se renderiza junto al botón real del formulario.
	 *
	 * Se construye con nodos, nunca con innerHTML: el aviso de WooCommerce trae
	 * markup y es filtrable por terceros, así que el servidor lo manda ya en
	 * texto plano y el enlace se arma acá.
	 */
	function mostrarMotivo(texto, cartUrl, enBarra) {
		var caja = enBarra
			? stickyBar.querySelector('.akb-sticky-atc__motivo')
			: form.querySelector('.akb-atc-motivo');
		if (!caja) {
			caja = document.createElement('p');
			if (enBarra) {
				caja.className = 'akb-sticky-atc__motivo';
				stickyBar.insertBefore(caja, stickyBar.firstChild);
			} else {
				caja.className = 'akb-atc-motivo';
				btn.parentNode.insertBefore(caja, btn.nextSibling);
			}
		}
		caja.textContent = '';
		caja.appendChild(document.createTextNode(texto + ' '));
		if (cartUrl) {
			var a = document.createElement('a');
			a.href = cartUrl;
			a.textContent = rotuloCarrito();
			caja.appendChild(a);
		}
		caja.hidden = false;
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (destinoCarrito) {
			window.location.href = destinoCarrito;
			return;
		}
		if (busy) return;
		busy = true;
		// Un intento nuevo retira la explicación del anterior: un «No puedes
		// añadir…» junto a «Agregado ✓» se contradiría.
		clearTimeout(temporizador);
		ocultarMotivos();

		var qty = form.querySelector('input.qty');
		var cantidad = qty && qty.value ? qty.value : '1';
		var data = new FormData();
		data.append('product_id', pidField.value);
		data.append('quantity', cantidad);

		setState('is-adding', labels.adding);
		// En vuelo se utiliza `aria-disabled` en lugar de `disabled` nativo para
		// preservar el foco activo del teclado y evitar que se desplace al body.
		// El estado `busy` previene envíos concurrentes.
		btn.setAttribute('aria-disabled', 'true');

		fetch('/?wc-ajax=add_to_cart', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.removeAttribute('aria-disabled');

				if (!res || res.error) {
					// Rechazo del servidor: se consulta el motivo específico y se muestra inline.
					busy = false;

					// El estado de error visual aplica al botón activo con el motivo adyacente.
					var enBarra = barraALaVista();
					restaurar(enBarra ? btn : stickyBtn);
					setState('is-error', 'No se pudo agregar', [enBarra ? stickyBtn : btn]);

					// Parámetros del producto para consultar el código de rechazo:
					var intento = '&product_id=' + encodeURIComponent(pidField.value) + '&quantity=' + encodeURIComponent(cantidad);
					fetch('/?wc-ajax=akb_get_cart&drain_notices=1' + intento, { credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (cart) {
							var motivo = cart && cart.data && cart.data.errors && cart.data.errors[0];
							if (!motivo) {
								// Sin motivo que mostrar, la recarga sigue siendo la única
								// forma de que el cliente vea algo. Último recurso, no el
								// camino normal.
								form.submit();
								return;
							}
							var urlCarrito = cart.data.cart_url || '';
							if ('ya_en_carrito' === cart.data.motivo && urlCarrito) {
								// El botón ya es el enlace: el motivo va sin el suyo.
								verCarrito(urlCarrito);
								mostrarMotivo(motivo, '', enBarra);
							} else {
								mostrarMotivo(motivo, urlCarrito, enBarra);
							}
							announce(motivo);
							programarReset(6000);
						})
						.catch(function () { form.submit(); });
					return;
				}

				if (res.fragments) {
					Object.keys(res.fragments).forEach(function (sel) {
						document.querySelectorAll(sel).forEach(function (el) {
							el.outerHTML = res.fragments[sel];
						});
					});
				}

				if (window.jQuery) {
					window.jQuery(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, window.jQuery(btn)]);
				}

				setState('is-added', labels.done);
				announce(labels.sr);

				// Liberación inmediata de busy para permitir adiciones sucesivas
				// sin esperar que concluya la animación de confirmación.
				busy = false;
				programarReset(2000);
			})
			.catch(function () {
				// Red caída / respuesta no-JSON: feedback inline + reintento, sin
				// recargar. El usuario reintenta con el mismo botón.
				//
				// Presentación visual y accesible del error de red junto al botón activo,
				// manteniendo sincronización temporal y posibilidad de reintento directo.
				btn.removeAttribute('aria-disabled');
				busy = false;
				var enBarra = barraALaVista();
				restaurar(enBarra ? btn : stickyBtn);
				setState('is-error', 'Reintentar', [enBarra ? stickyBtn : btn]);
				mostrarMotivo(MOTIVO_SIN_RESPUESTA, '', enBarra);
				announce(MOTIVO_SIN_RESPUESTA);
				programarReset(6000, function () {
					return [btn, stickyBtn].some(function (b) { return !!b && b.classList.contains('is-error'); });
				});
			});
	});
})();
