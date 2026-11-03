/**
 * Akibara — micro-animaciones GSAP (progressive enhancement).
 *
 * 1) Reveal de cards de producto al entrar en viewport (ScrollTrigger.batch).
 * 2) Intro del HomeHero: "armado de viñeta" (una firma breve y sin rebote).
 * 3) «Vuela al carrito» (window.akbAnimations.volarAlCarrito), coordinado por
 *    animations-loader.js para carga diferida tras `load` o primera interacción
 *    evitando impacto en métricas críticas de renderizado (LCP/INP).
 * 4) Marquesina de editoriales de la portada (setupBrandsMarquee).
 *
 * Principios (aplican a todo el archivo):
 *   - Progressive enhancement: si GSAP no carga, todo se ve normal (el estado
 *     inicial se setea por JS, nunca por CSS → sin FOUC ni contenido invisible).
 *   - Respeta prefers-reduced-motion vía gsap.matchMedia (revierte solo).
 *   - Cards: transform + opacity → compositor, 60fps; nunca visibility:hidden,
 *     para conservar el catálogo en el árbol accesible antes del scroll.
 *   - Hero: 100% TRANSFORM, cero opacity. Medido en el render real (puppeteer +
 *     PerformanceObserver): animar la opacidad de CUALQUIER texto grande del hero
 *     difiere el LCP — el lede resultó ser más grande que el logo y, con
 *     autoAlpha, se volvía el LCP retrasado a ~800-950ms. Sin opacity todo pinta
 *     en t=0 (solo desplazado); el gesto lo dan offsets cortos y una salida expo.
 */
(function () {
	'use strict';

	if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') {
		return; // GSAP no disponible → no-op, todo visible por CSS normal.
	}

	gsap.registerPlugin(ScrollTrigger);

	var FOLD = 0.92; // umbral: se considera "bajo el pliegue" si top >= 92% del viewport.
	var akbCardTriggers = [];

	function revealNewCards() {
		var all = gsap.utils.toArray('ul.products li.product:not(.akb-revealed)');
		if (!all.length) {
			return;
		}

		// Separación estricta de lecturas (DOM reads) y escrituras (DOM writes)
		// para evitar reflows y layout trashing forzado en bucle.
		var limite = window.innerHeight * FOLD;
		var below = all.filter(function (card) {
			return card.getBoundingClientRect().top >= limite;
		});
		all.forEach(function (card) {
			card.classList.add('akb-revealed');
		});

		if (!below.length) {
			return; // todas visibles al cargar → no ocultar nada.
		}

		// Aparición corta, contenida y acelerada por compositor. La sombra dura de
		// la propia card conserva la identidad de viñeta sin un scale-pop elástico.
		try {
			// No escalar la card: el scale 0.96 reducía temporalmente los CTA
			// de 44px a 42.24px justo al entrar al viewport. Un desplazamiento
			// corto conserva el reveal sin achicar ningún target interactivo.
			// Opacity, no autoAlpha: visibility:hidden sacaba las cards del árbol
			// accesible hasta que el visitante hiciera scroll.
			gsap.set(below, { opacity: 0, y: 8 });
			var batchTriggers = ScrollTrigger.batch(below, {
				start: 'top ' + Math.round(FOLD * 100) + '%',
				once: true,
				onEnter: function (batch) {
					var staggerAmount = Math.min(0.24, Math.max(0, batch.length - 1) * 0.035);
					gsap.to(batch, {
						opacity: 1,
						y: 0,
						duration: 0.34,
						ease: 'power3.out',
						stagger: { amount: staggerAmount, from: 'start' },
						overwrite: true,
						// El reveal solo debe poseer estos estilos durante la animación.
						// Al terminar, CSS recupera el control de :hover/:focus-within.
						clearProps: 'opacity,transform'
					});
				}
			});
			akbCardTriggers = akbCardTriggers.concat(batchTriggers || []);
		} catch (e) {
			// Paridad con revealHero(): si set/batch falla a mitad, NUNCA dejar
			// productos ocultos → revertir las cards afectadas a visible.
			gsap.set(below, { clearProps: 'opacity,transform' });
		}
	}

	/**
	 * Devuelve el slide del hero que el usuario está viendo realmente.
	 * Hero estático → el único slide. Carrusel → el más cercano a scrollLeft
	 * (si el navegador restauró el scroll a un slide N o hay deep-link, no
	 * animamos un slide fuera de pantalla). El scroll-snap nativo lo maneja
	 * el plugin (home-hero.js); acá solo LEEMOS scrollLeft, no lo tocamos.
	 */
	function getHeroActiveSlide(hero) {
		var slides = hero.querySelectorAll('.akb-hero__slide');
		if (!slides.length) {
			return null;
		}
		if (!hero.classList.contains('akb-hero--carousel')) {
			return slides[0];
		}
		var track = hero.querySelector('.akb-hero__track');
		var sl = track ? (track.scrollLeft || 0) : 0;
		var best = slides[0];
		var bestDist = Infinity;
		Array.prototype.forEach.call(slides, function (s) {
			var d = Math.abs(s.offsetLeft - sl);
			if (d < bestDist) {
				bestDist = d;
				best = s;
			}
		});
		return best;
	}

	/**
	 * Intro "armado de viñeta" del hero:
	 *   1. Barra-lomo roja del título crece de arriba a abajo (estampa de tinta).
	 *   2. El bloque de contenido entra con decisión (x:-12→0, expo.out) — mismo
	 *      gesto que el reveal de cards, sin opacity (protege el LCP del H1).
	 *   3. Eyebrow (tab roja) popea, 4. lede entra suave, 5. CTAs se estampan.
	 * Gated por ScrollTrigger: en desktop el contenido está above-the-fold →
	 * dispara al instante; en mobile el contenido va DEBAJO de la imagen → dispara
	 * recién cuando entra en viewport (no se anima a ciegas algo que no se ve).
	 *
	 * @return {Function|null} cleanup (revierte a estado base visible) o null.
	 */
	function revealHero() {
		var hero = document.querySelector('.akb-hero');
		if (!hero) {
			return null;
		}
		var slide = getHeroActiveSlide(hero);
		if (!slide) {
			return null;
		}
		var content = slide.querySelector('.akb-hero__content');
		if (!content) {
			return null;
		}
		var eyebrow = content.querySelector('.akb-hero__eyebrow');
		var lede = content.querySelector('.akb-hero__lede');
		var actions = content.querySelector('.akb-hero__actions');

		var st = null;
		var tl = null;
		function clearHeroStyles() {
			gsap.set(content, { clearProps: 'transform' });
			if (eyebrow) {
				gsap.set(eyebrow, { clearProps: 'transform,transformOrigin' });
			}
			gsap.set([lede, actions].filter(Boolean), { clearProps: 'transform' });
			slide.style.removeProperty('--akb-hero-bar-scale');
		}

		try {
			// Estado inicial SOLO por JS y SOLO transform (cero opacity en todo el
			// hero): cada elemento pinta en t=0 desplazado unos px → el LCP no se
			// difiere, sin importar cuál sea el elemento más grande. El eyebrow
			// (tab roja) se "estampa" con scale desde su anclaje izquierdo.
			gsap.set(slide, { '--akb-hero-bar-scale': 0 });
			gsap.set(content, { x: -12 });
			if (eyebrow) {
				gsap.set(eyebrow, { scaleX: 0, transformOrigin: 'left center' });
			}
			gsap.set([lede, actions].filter(Boolean), { y: 8 });

			tl = gsap.timeline({
				paused: true,
				// La coreografía no debe seguir siendo dueña de estilos inline una
				// vez terminada: CSS recupera el control y la vista queda limpia.
				onComplete: clearHeroStyles
			});
			tl.to(slide, { '--akb-hero-bar-scale': 1, duration: 0.3, ease: 'power3.out' }, 0)
				.to(content, { x: 0, duration: 0.46, ease: 'expo.out' }, 0.04);
			if (eyebrow) {
				tl.to(eyebrow, { scaleX: 1, duration: 0.3, ease: 'power3.out' }, 0.15);
			}
			if (lede) {
				tl.to(lede, { y: 0, duration: 0.3, ease: 'power3.out' }, 0.22);
			}
			if (actions) {
				tl.to(actions, { y: 0, duration: 0.32, ease: 'power3.out' }, 0.28);
			}

			st = ScrollTrigger.create({
				trigger: content,
				start: 'top 90%',
				once: true,
				onEnter: function () {
					tl.play();
				}
			});
		} catch (e) {
			// Si algo falla a mitad de construir el intro, revertir a estado base
			// visible y no dejar nada oculto.
			if (st) { st.kill(); }
			if (tl) { tl.kill(); }
			clearHeroStyles();
			return null;
		}

		// Cleanup (lo invoca matchMedia si el usuario activa reduce-motion en
		// caliente): mata el trigger/timeline y devuelve TODO a su estado base
		// visible, sin congelar en un frame intermedio.
		return function () {
			if (st) { st.kill(); }
			if (tl) { tl.kill(); }
			clearHeroStyles();
		};
	}

	/**
	 * «Vuela al carrito»: un clon de la portada recorre un arco corto hacia el
	 * icono del carrito y, al aterrizar, ejecuta `onDone` para abrir el cajón lateral
	 * con la barra de envío gratis y recomendaciones. Secuenciado: el cajón se despliega
	 * al concluir el vuelo.
	 *
	 * El listener de `akb:added_to_cart` ya NO vive aquí: vive en
	 * animations-loader.js, que no depende de GSAP. Mientras vivía en este
	 * fichero, un GSAP que no llegaba (o que todavía no había llegado, ahora que
	 * fuera de la portada se carga diferido) dejaba el cajón sin abrir. El
	 * cargador abre el cajón SIEMPRE y sólo pide el vuelo cuando hay movimiento
	 * permitido y este fichero ya está listo.
	 *
	 * Garantías:
	 *  - LCP: clona a un nodo fixed desechable, anima SOLO transform/opacity. Es
	 *    interaction-triggered (jamás toca la imagen real ni corre en load).
	 *  - Sin imagen o sin destino válido llama `onDone` igual: el cajón no se pierde.
	 *  - autoOpenOnAdd del plugin queda en 'no': el cajón lo abre el cargador tras
	 *    el vuelo (si estuviera en 'yes' abriría de inmediato y taparía el vuelo).
	 *  - `rectDest` se lee como NÚMEROS (el ícono es un fragment WC que se reemplaza).
	 */
	var volarAlCarrito = (function () {
		var activeTl = null;

		function center(r) { return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }

		function resolveCartTarget() {
			var mobile = window.matchMedia('(max-width: 1024px)').matches;
			var sels = mobile
				? ['.bottom-nav__item--cart', 'a.header-icon--cart']
				: ['a.header-icon--cart', '.bottom-nav__item--cart'];
			for (var i = 0; i < sels.length; i++) {
				var el = document.querySelector(sels[i]);
				if (!el) { continue; }
				var r = el.getBoundingClientRect();
				if (r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < window.innerHeight) {
					return r; // números; no guardamos el nodo (fragment WC).
				}
			}
			return null;
		}

		function findSourceImg(btn) {
			if (!btn) { return null; }
			// PDP: galería principal. Loop: la portada de la card del botón.
			if (btn.closest && btn.closest('form.cart')) {
				return document.querySelector('.woocommerce-product-gallery__image img, .woocommerce-product-gallery img');
			}
			var card = btn.closest && btn.closest('li.product');
			return card ? card.querySelector('img') : null;
		}

		// Vuela la portada al carrito y, al ATERRIZAR, invoca onDone (abrir drawer).
		// Si no hay imagen/destino válidos, llama onDone igual (no perder el drawer).
		function fly(btn, onDone) {
			var img = findSourceImg(btn);
			var dRect = resolveCartTarget();
			if (!img || !dRect) { onDone(); return; }
			var oRect = img.getBoundingClientRect();
			if (oRect.width <= 0 || oRect.height <= 0) { onDone(); return; }
			var oC = center(oRect), dC = center(dRect);

			if (activeTl) { activeTl.kill(); }
			var stale = document.querySelector('.akb-fly-clone');
			if (stale) { stale.remove(); }

			var clone = img.cloneNode(true);
			clone.className = 'akb-fly-clone';
			clone.removeAttribute('loading');
			clone.removeAttribute('srcset'); // usa el src ya renderizado, sin re-elegir.
			document.body.appendChild(clone);
			gsap.set(clone, {
				position: 'fixed', display: 'block',
				top: oRect.top, left: oRect.left, width: oRect.width, height: oRect.height,
				margin: 0, objectFit: 'cover', borderRadius: 0,
				zIndex: 9998, pointerEvents: 'none', willChange: 'transform', force3D: true
			});

			var done = false;
			function finish() {
				if (done) { return; }
				done = true;
				clone.style.willChange = '';
				clone.remove();
				activeTl = null;
				onDone(); // ← al aterrizar, abrir el drawer con la barra/promos.
			}
			activeTl = gsap.timeline({ onComplete: finish });
			activeTl
				.to(clone, { scale: 1.025, rotation: gsap.utils.random(-3, 3), duration: 0.1, ease: 'power2.out' })
				.to(clone, { x: dC.x - oC.x, y: dC.y - oC.y, scale: 0.16, duration: 0.42, ease: 'power3.in' }, '-=0.02')
				.to(clone, { scale: 0, autoAlpha: 0, duration: 0.12, ease: 'power2.in' }, '-=0.06');
		}

		return fly;
	})();

	/**
	 * Carrusel continuo de editoriales del home.
	 *
	 * Mejora progresiva: el servidor entrega una <ul> normal (grilla legible y
	 * navegable). Solo si GSAP está disponible Y el usuario no pidió reducir
	 * movimiento, se convierte en marquesina infinita. Bajo reduced-motion este
	 * bloque ni corre —vive dentro de gsap.matchMedia— y la grilla queda intacta.
	 *
	 * El bucle se logra duplicando los slides una vez y animando `x` hasta el
	 * ancho del set original: al llegar, la copia está exactamente donde estaba
	 * el original, así que el salto es invisible. Los clones salen del árbol de
	 * accesibilidad (aria-hidden) y del orden de tabulación, para no duplicar
	 * cada editorial ante un lector de pantalla ni al navegar con teclado.
	 *
	 * Se pausa al pasar el mouse (solo puntero real: en táctil el tap emulaba
	 * mouseenter sin mouseleave y congelaba la marquesina para siempre), al
	 * enfocar con teclado (si no, el foco se va moviendo bajo el usuario), con
	 * la pestaña oculta y fuera de pantalla: optimización de ciclo de CPU mediante
	 * IntersectionObserver, pausando el tween infinito cuando la sección no está en viewport.
	 *
	 * Controles accesibles: incluye botón explícito de pausa (WCAG 2.2.2)
	 * generado dinámicamente cuando la marquesina interactiva está activa.
	 *
	 * ARRASTRABLE (pointer events): posicionarse sobre ella y moverla funciona
	 * con dedo y con mouse. El truco es no animar `x` directo durante el gesto
	 * sino mapear el delta del puntero a `tween.progress()` con
	 * gsap.utils.wrap(0,1): el bucle infinito sigue siendo el mismo sistema y
	 * al soltar la inercia decae y la marquesina retoma sola desde donde quedó.
	 * `touch-action: pan-y` (CSS) deja el scroll vertical al navegador — un pan
	 * vertical dispara pointercancel y ahí simplemente reanudamos. Un arrastre
	 * real suprime el click siguiente para no navegar al soltar sobre un logo.
	 *
	 * El pointermove no escribe el tween: deja el progreso pendiente y el
	 * ticker de GSAP lo aplica UNA vez por frame (ver flushDrag), porque WebKit
	 * despacha varias muestras del puntero por frame y todas menos la última
	 * eran trabajo tirado.
	 *
	 * @returns {Function|null} cleanup, o null si no había nada que animar.
	 */
	function setupBrandsMarquee() {
		var track = document.querySelector('.akb-brands-home__track');
		if (!track) {
			return null;
		}

		var slides = Array.prototype.slice.call(track.children);
		if (slides.length < 2) {
			return null; // Con un solo logo no hay recorrido que animar.
		}

		// La clase NO se añade todavía: `brands-home.css` se carga diferida
		// (media="print" + onload, ver inc/performance.php) y hasta que llegue
		// `.is-marquee` no es flex. Montarla antes deja durante toda esa
		// ventana una LISTA VERTICAL de 16 <li> —1815 px de alto, medido—
		// trasladándose en X a 40 px/s. Se monta en `start()`, cuando el
		// display ya es flex.

		// Duplicado para el bucle sin costura (marcado aria-hidden y rel=nofollow para evitar duplicados en Google).
		var clones = slides.map(function (slide) {
			var clone = slide.cloneNode(true);
			clone.setAttribute('aria-hidden', 'true');
			clone.classList.add('akb-brands-home__slide--clone');
			Array.prototype.forEach.call(
				clone.querySelectorAll('a, button, [tabindex]'),
				function (node) {
					node.setAttribute('tabindex', '-1');
					node.setAttribute('aria-hidden', 'true');
					if (node.tagName === 'A') {
						node.setAttribute('rel', 'nofollow');
					}
				}
			);
			track.appendChild(clone);
			return clone;
		});

		// ¿Ya llegó assets/css/brands-home.css? Se carga DIFERIDA (truco
		// media="print" + onload, ver inc/performance.php), así que este script
		// puede correr antes. En esa ventana los <li> caen uno debajo del otro
		// y cualquier medida horizontal es basura: es la causa raíz del salto
		// del bucle (ver setWidth) y de que se viera una columna deslizándose.
		//
		// La señal NO puede ser `display === 'flex'`: ese flex lo produce la
		// propia clase `.is-marquee`, así que usarlo como compuerta para
		// añadirla no termina nunca. Se pregunta por un token que la hoja
		// define en la SECCIÓN, que existe con o sin la clase.
		var section = track.closest('.akb-brands-home') || track.parentElement;
		function stylesReadyRaw() {
			if (!section) {
				return true; // Sin sección no hay compuerta posible: no bloquear.
			}
			var token = window.getComputedStyle(section).getPropertyValue('--akb-brand-slide-w');
			return '' !== String(token).trim();
		}

		function setWidth() {
			// Recorrido de UN set: la distancia entre el primer original y su
			// clon. Es exacta por definición — incluye el gap que los separa y
			// no depende del padding del track.
			//
			// Antes se usaba `track.scrollWidth / 2`, que NO es lo mismo:
			// scrollWidth suma el padding del contenedor y parte el último gap
			// por la mitad. Y como se medía ANTES de que llegara la hoja
			// diferida, medía un track todavía sin ese padding. Medido en
			// producción 2.1.76 a 390px sobre carga fría: el tween nacía
			// animando hasta -1192,5 px cuando el recorrido real de un set son
			// 1205 px (y scrollWidth/2 ya asentado, 1208,5). Esos 12,5 px son
			// un salto hacia atrás en CADA vuelta del bucle — exactamente los
			// 16 px de `padding: 8px 16px` del track móvil menos medio gap.
			//
			// El fallback a scrollWidth queda solo por si no hay clones: nunca
			// debe devolver 0, porque build() interpreta 0 como "no hay nada
			// que animar" y desmonta la marquesina.
			if (!clones.length) {
				return track.scrollWidth / 2;
			}
			// `getBoundingClientRect().left` y no `offsetLeft`: el segundo
			// devuelve un ENTERO redondeado, y el ancho del slide sale de un
			// `clamp(156px, 40vw, 190px)` que en anchos reales de teléfono no
			// cae en entero. Medido: 393px (Pixel) daba +0,500 px de desajuste
			// por vuelta, 412px −0,375 y 428px (iPhone Plus) +0,500. No
			// acumula, pero era un salto sub-pixel evitable en un arreglo que
			// se declaraba exacto. Los dos rects comparten el mismo transform
			// del track, así que la resta lo cancela.
			var exact = clones[0].getBoundingClientRect().left - slides[0].getBoundingClientRect().left;
			return exact > 0 ? exact : track.scrollWidth / 2;
		}

		// ── Equilibrio óptico de la tinta ──────────────────────────────────
		// La placa ya mide lo mismo para todos, pero `object-fit: contain` NO
		// reparte la tinta por igual: el área rendida es bw²/a para un logo más
		// ancho que la caja y a·bh² para uno más alto, así que el máximo se lo
		// lleva siempre aquel cuyo aspecto coincide con el de la caja y los dos
		// extremos pierden. Medido a 390px con placas ya idénticas: Planeta
		// rendía 23px de alto y Arechi 53px — 2,40x de diferencia de área. Eso
		// es lo que el ojo lee como "tienen distinto tamaño".
		//
		// Se corrige escalando SOLO hacia abajo hasta una cobertura objetivo de
		// la caja. Al que ya no llega a esa cobertura (Arechi, Planeta) no se le
		// toca: agrandarlo lo sacaría de la placa. Escalar hacia abajo tampoco
		// pixela — son SVG.
		//
		// El factor sale de sqrt(cobertura · r), con r = cuánto se aparta el
		// aspecto del logo del de la caja. Se recalcula en cada `build()` porque
		// el aspecto de la caja cambia con el breakpoint (156px vs 190px de
		// ancho contra la misma altura de placa) y ahí cambia quién es el
		// "ganador": Ivrea pasa de limitado por ancho a limitado por alto.
		var TARGET_COVERAGE = 0.55;
		function fitLogos() {
			var all = track.querySelectorAll('.akb-brands-home__slide');
			Array.prototype.forEach.call(all, function (slide) {
				var img = slide.querySelector('.akb-brands-home__logo img');
				if (!img || !img.naturalWidth || !img.naturalHeight) {
					return;
				}
				// `offsetWidth/Height` y no `getBoundingClientRect()`: el rect ya
				// viene multiplicado por el `scale` que escribimos la vuelta
				// anterior, así que medirlo realimentaría el cálculo.
				var bw = img.offsetWidth;
				var bh = img.offsetHeight;
				if (!bw || !bh) {
					return;
				}
				var aspect = img.naturalWidth / img.naturalHeight;
				var boxAspect = bw / bh;
				var r = aspect >= boxAspect ? aspect / boxAspect : boxAspect / aspect;
				var k = Math.sqrt(TARGET_COVERAGE * r);
				slide.style.setProperty('--akb-brand-fit', (k < 1 ? k : 1).toFixed(3));
			});
		}

		// `build()` puede correr con el layout todavía asentándose: medido en
		// local, la caja útil valía 153x53 en el primer build y 128x36 una vez
		// asentada, así que los factores quedaban calculados contra una caja que
		// ya no existía (Ivrea 0,806 donde correspondía 0,757). En vez de
		// adivinar el momento bueno, se observa la caja y se recalcula cuando
		// cambie. `fitLogos()` sólo escribe `transform`, que no altera el
		// tamaño observado — no hay realimentación.
		var fitObserver = null;
		if ('function' === typeof window.ResizeObserver) {
			fitObserver = new window.ResizeObserver(function () { fitLogos(); });
			// Se observa la IMAGEN, no la placa: la placa mide 156x56 con y sin
			// la hoja diferida, así que observarla no avisaba nunca. Lo que
			// cambia es la caja INTERIOR (el padding), y eso sólo lo ve la
			// imagen, que es exactamente la caja contra la que se calcula.
			var firstBox = track.querySelector('.akb-brands-home__logo img');
			if (firstBox) { fitObserver.observe(firstBox); }
		}

		var tween = null;
		var distance = 0;
		var wrapProgress = gsap.utils.wrap(0, 1);

		function build() {
			fitLogos();
			// Un rebuild (resize/rotación) invalida `distance`: si hay un gesto
			// o inercia a mitad de camino, se abortan LIMPIO — sin esto, el
			// pointermove siguiente mezclaba startProgress viejo con distance
			// nueva y el tween nuevo competía con la inercia sobre `x`.
			if (drag.active) {
				drag.active = false;
				drag.moved = false;
				// El progreso pendiente es del recorrido VIEJO: se descarta en
				// vez de aplicarse sobre la geometría nueva.
				pendingProgress = null;
				gsap.ticker.remove(flushDrag);
				track.classList.remove('is-dragging');
			}
			if (momentum) {
				momentum.kill();
				momentum = null;
			}
			if (tween) {
				tween.kill();
			}
			distance = setWidth();
			if (distance <= 0) {
				tween = null;
				return;
			}
			gsap.set(track, { x: 0 });
			tween = gsap.to(track, {
				x: -distance,
				// Velocidad constante (~40 px/s) sea cual sea la cantidad de
				// editoriales: al sumar logos el recorrido se alarga, no se acelera.
				duration: distance / 40,
				ease: 'none',
				repeat: -1,
				// Nace pausado: resumeAuto decide si arranca, respetando
				// hover, foco, pestaña oculta y gestos — un resize con el
				// mouse encima no debe ponerla a andar bajo el cursor.
				paused: true
			});
			resumeAuto();
		}

		// ─── Arrastre (dedo y mouse) ───────────────────────────────────
		// El gesto mueve tween.progress() (con wrap), no el transform directo:
		// así el auto-scroll, el arrastre y la inercia comparten UN estado y
		// nunca compiten escrituras sobre `x`.
		var drag = {
			active: false,
			moved: false,
			id: null,
			startX: 0,
			lastX: 0,
			lastT: 0,
			velocity: 0, // px/ms del gesto, para la inercia.
			startProgress: 0
		};
		var momentum = null;
		var suppressClickTimer = null;
		var suppressClick = false;
		// Último progreso pedido por el gesto, aún sin escribir. Ver flushDrag.
		var pendingProgress = null;
		// Estados de pausa RASTREADOS: resumeAuto debe re-verificarlos todos.
		// Sin esto, soltar un drag con el mouse aún encima (o con un logo
		// enfocado por teclado) reanudaba la marquesina bajo el usuario,
		// rompiendo el propio contrato de pausa (WCAG 2.2.2).
		var hoverPaused = false;
		var focusPaused = false;
		// Fuera de pantalla hasta que el observador diga lo contrario: su primer
		// aviso llega siempre, pero asíncrono, y build() no debe echar a andar un
		// tween que quizá ni se ve. Sin IntersectionObserver no hay forma de
		// saberlo y se conserva el comportamiento de siempre.
		var offscreenPaused = 'function' === typeof window.IntersectionObserver;
		// Pausa pedida con el botón: sólo la levanta otro clic en el botón.
		var userPaused = false;

		function stopMomentum() {
			if (momentum) {
				momentum.kill();
				momentum = null;
			}
		}

		// Ticker sincronizado con rAF: los eventos pointermove desacoplados
		// de la tasa de refresco almacenan su progreso pendiente y se aplican
		// una sola vez por cuadro para evitar escrituras y reflujos redundantes.
		function flushDrag() {
			if (null === pendingProgress) {
				return;
			}
			var next = pendingProgress;
			pendingProgress = null;
			if (tween) {
				tween.progress(next);
			}
		}

		function stopDragTicker() {
			// Se vacía ANTES de soltar el ticker: si quedaba un movimiento sin
			// aplicar, la inercia arrancaría desde un progreso viejo.
			flushDrag();
			gsap.ticker.remove(flushDrag);
		}

		function resumeAuto() {
			if (tween && !document.hidden && !drag.active && !momentum && !hoverPaused && !focusPaused && !offscreenPaused && !userPaused) {
				tween.play();
			}
		}

		function onPointerDown(event) {
			if (!tween || drag.active) {
				// drag.active: un segundo dedo no secuestra el gesto del primero.
				return;
			}
			if ('mouse' === event.pointerType && 0 !== event.button) {
				return;
			}
			stopMomentum();
			drag.active = true;
			drag.moved = false;
			drag.id = event.pointerId;
			drag.startX = event.clientX;
			drag.lastX = event.clientX;
			drag.lastT = event.timeStamp;
			drag.velocity = 0;
			drag.startProgress = tween.progress();
			pendingProgress = null;
			gsap.ticker.add(flushDrag);
			tween.pause();
			if (track.setPointerCapture) {
				try {
					track.setPointerCapture(event.pointerId);
				} catch (err) {
					// Puntero ya liberado (p. ej. cancel inmediato): sin captura igual funciona.
				}
			}
		}

		function onPointerMove(event) {
			if (!drag.active || event.pointerId !== drag.id) {
				return;
			}
			var dx = event.clientX - drag.startX;
			if (!drag.moved) {
				// Umbral: un tap con micro-temblor no es un arrastre y debe
				// seguir navegando al link.
				if (Math.abs(dx) < 5) {
					return;
				}
				drag.moved = true;
				track.classList.add('is-dragging');
			}
			var dt = event.timeStamp - drag.lastT;
			if (dt > 0) {
				drag.velocity = (event.clientX - drag.lastX) / dt;
			}
			drag.lastX = event.clientX;
			drag.lastT = event.timeStamp;
			// Arrastrar hacia la izquierda (dx<0) avanza la marquesina.
			// La velocidad SÍ usa todas las muestras (es más fiel para la
			// inercia); lo que se acumula para una sola escritura por frame es
			// la posición. Ver flushDrag.
			pendingProgress = wrapProgress(drag.startProgress - dx / distance);
		}

		function finishDrag(cancelled) {
			if (!drag.active) {
				return;
			}
			drag.active = false;
			stopDragTicker();
			track.classList.remove('is-dragging');
			if (drag.moved) {
				// El click que dispara este mismo gesto al soltar no debe navegar.
				suppressClick = true;
				window.clearTimeout(suppressClickTimer);
				suppressClickTimer = window.setTimeout(function () {
					suppressClick = false;
				}, 80);
			}
			if (!drag.moved || cancelled || !tween) {
				resumeAuto();
				return;
			}
			// Dedo quieto antes de soltar = sin inercia (la velocidad guardada
			// es del último movimiento, no del momento del release).
			if ('undefined' !== typeof performance && performance.now() - drag.lastT > 120) {
				drag.velocity = 0;
			}
			// Inercia: proyecta ~160 ms del gesto y decae; al frenar, retoma sola.
			var glide = { p: tween.progress() };
			var throwDelta = -( drag.velocity * 160 ) / distance;
			momentum = gsap.to(glide, {
				p: glide.p + throwDelta,
				duration: Math.min(1.2, 0.35 + Math.abs(drag.velocity) * 0.55),
				ease: 'power2.out',
				onUpdate: function () {
					tween.progress(wrapProgress(glide.p));
				},
				onComplete: function () {
					momentum = null;
					resumeAuto();
				}
			});
		}

		function onPointerUp(event) {
			if (event.pointerId === drag.id) {
				finishDrag(false);
			}
		}

		function onPointerCancel(event) {
			// touch-action: pan-y → un pan vertical se lo queda el navegador y
			// cae aquí: soltamos el gesto sin inercia y la marquesina sigue.
			if (event.pointerId === drag.id) {
				finishDrag(true);
			}
		}

		function onLostCapture(event) {
			// pointerup perdido (Alt-Tab, diálogo del SO a mitad del drag):
			// sin esto drag.active quedaba true para siempre y la marquesina
			// congelada hasta recargar.
			if (drag.active && event.pointerId === drag.id) {
				finishDrag(true);
			}
		}

		function onClickCapture(event) {
			if (suppressClick) {
				event.preventDefault();
				event.stopPropagation();
				suppressClick = false;
			}
		}

		function onDragStart(event) {
			// Sin esto, en desktop el arrastre inicia el drag nativo de la
			// imagen/link y roba el gesto a mitad de camino.
			event.preventDefault();
		}

		// ─── Botón de pausa (AI-3, WCAG 2.2.2) ───────────────────────────
		// Nombre FIJO + aria-pressed (patrón de botón de alternancia): el lector
		// anuncia «Pausar el carrusel de editoriales, presionado» y el nombre no
		// cambia bajo el foco. Va en la cabecera, junto a «Ver editoriales», y
		// no dentro del track: ahí el foco pausaría por su cuenta y el botón
		// viajaría con los logos.
		var pauseBtn = null;
		function onPauseClick() {
			userPaused = !userPaused;
			pauseBtn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
			if (userPaused) {
				pause();
			} else {
				resumeAuto();
			}
		}
		function mountPauseBtn() {
			var header = section ? section.querySelector('.akb-brands-home__header') : null;
			if (!header || pauseBtn) {
				return;
			}
			pauseBtn = document.createElement('button');
			pauseBtn.type = 'button';
			pauseBtn.className = 'akb-brands-home__pause';
			pauseBtn.setAttribute('aria-label', 'Pausar el carrusel de editoriales');
			pauseBtn.setAttribute('aria-pressed', 'false');
			var icon = document.createElement('span');
			icon.className = 'akb-brands-home__pause-icon';
			icon.setAttribute('aria-hidden', 'true');
			pauseBtn.appendChild(icon);
			pauseBtn.addEventListener('click', onPauseClick);
			// Sin «Ver editoriales» (null) queda al final de la cabecera.
			header.insertBefore(pauseBtn, header.querySelector('.akb-brands-home__more'));
		}

		// Arranque diferido: primero la hoja, después la marquesina. Si la hoja
		// nunca llega, la sección se queda en su grilla estática, que es una
		// experiencia válida — mejor que una columna deslizándose.
		var started = false;
		function start() {
			if (started || !stylesReadyRaw()) {
				return false;
			}
			started = true;
			track.classList.add('is-marquee');
			mountPauseBtn();
			build();
			return true;
		}

		// La espera NO puede colgar de `requestAnimationFrame`: el navegador lo
		// congela en una pestaña de fondo, así que la marquesina no montaría
		// nunca en quien abre el home en segundo plano — medido en local, donde
		// el panel oculto dejó `is-marquee` sin aplicar aunque el token ya
		// estaba. `setTimeout` sigue disparando (throttleado, pero dispara).
		var styleTries = 0;
		var styleWatch = 0;
		function waitForStyles() {
			styleWatch = 0;
			if (start()) {
				return;
			}
			if (styleTries++ > 100) {
				// ~5 s sin hoja: se abandona SIN montar. La sección queda como
				// grilla estática, que es legible y navegable; montarla sería
				// publicar una columna de 1815 px trasladándose.
				return;
			}
			styleWatch = window.setTimeout(waitForStyles, 50);
		}
		if (!start()) {
			styleWatch = window.setTimeout(waitForStyles, 50);
		}

		function pause() { if (tween) { tween.pause(); } }
		function onPointerEnter(event) {
			// Solo mouse real: el "hover" emulado del tap táctil pausaba sin
			// camino de vuelta (no hay mouseleave en un dedo que ya se fue).
			if ('mouse' === event.pointerType) {
				hoverPaused = true;
				if (!drag.active) { pause(); }
			}
		}
		function onPointerLeave(event) {
			if ('mouse' === event.pointerType) {
				hoverPaused = false;
				resumeAuto();
			}
		}
		function onFocusIn() { focusPaused = true; pause(); }
		function onFocusOut() { focusPaused = false; resumeAuto(); }
		function onVisibility() { if (document.hidden) { pause(); } else { resumeAuto(); } }

		track.addEventListener('pointerenter', onPointerEnter);
		track.addEventListener('pointerleave', onPointerLeave);
		track.addEventListener('pointerdown', onPointerDown);
		track.addEventListener('pointermove', onPointerMove);
		track.addEventListener('pointerup', onPointerUp);
		track.addEventListener('pointercancel', onPointerCancel);
		track.addEventListener('lostpointercapture', onLostCapture);
		track.addEventListener('click', onClickCapture, true);
		track.addEventListener('dragstart', onDragStart);
		track.addEventListener('focusin', onFocusIn);
		track.addEventListener('focusout', onFocusOut);
		document.addEventListener('visibilitychange', onVisibility);

		// Fuera de pantalla no anima (AI-1). Se observa la SECCIÓN, no el track:
		// el track se traslada y su caja no dice dónde está la sección.
		var visibilityObserver = null;
		if (offscreenPaused) {
			visibilityObserver = new window.IntersectionObserver(function (entries) {
				offscreenPaused = !entries[entries.length - 1].isIntersecting;
				if (offscreenPaused) {
					pause();
				} else {
					resumeAuto();
				}
			}, { rootMargin: '200px 0px' });
			visibilityObserver.observe(section || track);
		}

		// Al rotar el móvil o cambiar el ancho, el recorrido cambia: se recalcula.
		var resizeTimer = null;
		function onResize() {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(build, 200);
		}
		window.addEventListener('resize', onResize);

		return function () {
			window.clearTimeout(resizeTimer);
			window.clearTimeout(suppressClickTimer);
			if (styleWatch) { window.clearTimeout(styleWatch); }
			if (fitObserver) { fitObserver.disconnect(); }
			if (visibilityObserver) { visibilityObserver.disconnect(); }
			if (pauseBtn) {
				pauseBtn.removeEventListener('click', onPauseClick);
				pauseBtn.remove();
				pauseBtn = null;
			}
			window.removeEventListener('resize', onResize);
			document.removeEventListener('visibilitychange', onVisibility);
			track.removeEventListener('pointerenter', onPointerEnter);
			track.removeEventListener('pointerleave', onPointerLeave);
			track.removeEventListener('pointerdown', onPointerDown);
			track.removeEventListener('pointermove', onPointerMove);
			track.removeEventListener('pointerup', onPointerUp);
			track.removeEventListener('pointercancel', onPointerCancel);
			track.removeEventListener('lostpointercapture', onLostCapture);
			track.removeEventListener('click', onClickCapture, true);
			track.removeEventListener('dragstart', onDragStart);
			track.removeEventListener('focusin', onFocusIn);
			track.removeEventListener('focusout', onFocusOut);
			pendingProgress = null;
			gsap.ticker.remove(flushDrag);
			stopMomentum();
			if (tween) { tween.kill(); }
			clones.forEach(function (clone) { clone.remove(); });
			track.classList.remove('is-marquee', 'is-dragging');
			gsap.set(track, { clearProps: 'transform' });
		};
	}

	gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', function () {
		revealNewCards();
		var heroCleanup = revealHero();
		var marqueeCleanup = setupBrandsMarquee();

		// El buscador propio (akb-search.js) emite el evento nativo `akb:pjax`
		// tras reemplazar la grilla por su swap; re-revelamos las cards nuevas
		// y refrescamos las posiciones de ScrollTrigger.
		function onAkbPjax() {
			requestAnimationFrame(function () {
				revealNewCards();
				ScrollTrigger.refresh();
			});
		}
		document.addEventListener('akb:pjax', onAkbPjax);

		// Cleanup si el usuario activa "reducir movimiento": revertir a visible.
		return function () {
			document.removeEventListener('akb:pjax', onAkbPjax);
			akbCardTriggers.forEach(function (trigger) { trigger.kill(); });
			akbCardTriggers = [];
			gsap.set('ul.products li.product', { clearProps: 'opacity,transform' });
			if (heroCleanup) {
				heroCleanup();
			}
			if (marqueeCleanup) {
				marqueeCleanup();
			}
		};
	});

	// Aviso al cargador (animations-loader.js): ya puede pedir vuelos. Va al
	// final a propósito: si algo de arriba lanzara, el cargador no recibe un
	// «listo» a medias y abre el cajón sin vuelo, que es lo seguro.
	window.akbAnimations = { volarAlCarrito: volarAlCarrito };
	document.dispatchEvent(new CustomEvent('akb:animaciones-listas'));
})();
