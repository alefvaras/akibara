/*
 * Series Bundles — JS del BuilderWidget "Arma tu pack" en single product.
 * Carga condicional desde Akibara\Business\Modules\SeriesBundles (Req 12.10).
 */
( function () {
	'use strict';

	function onReady() {
		initBuilders();
		initLandingRails();
	}

	// Espejo único del DiscountEngine para las dos superficies: BYP llega como
	// máximo al precio objetivo regular×(1-pct) y cede ante un precio vigente
	// mejor. `items` usa el mismo shape en ficha y landing.
	function pricedTotals( items, pct ) {
		var currentTotal = 0;
		var finalTotal = 0;
		var regularTotal = 0;

		items.forEach( function ( item ) {
			var current = Number( item.current ) || 0;
			var regular = Number( item.regular ) || current;
			var discountEligible = item.discountEligible !== false;
			var target = pct > 0 && discountEligible ? regular * ( 1 - ( pct / 100 ) ) : current;
			// CLP se liquida sin decimales. Redondear cada aporte por SKU antes de sumar, igual que el
			// backend, para prometer el mismo total incluso con precios no divisibles por 100.
			var contribution = pct > 0 && discountEligible
				? Math.round( Math.max( 0, current - target ) )
				: 0;
			var finalPrice = current - contribution;

			currentTotal += current;
			finalTotal += finalPrice;
			regularTotal += regular;
		} );

		var savings = Math.max( 0, currentTotal - finalTotal );
		var totalSavingsVsRegular = Math.max( 0, regularTotal - finalTotal );
		var hasPreorderSavings = regularTotal > currentTotal;

		return {
			currentTotal: currentTotal,
			regularTotal: regularTotal,
			finalTotal: finalTotal,
			savings: savings,
			totalSavingsVsRegular: totalSavingsVsRegular,
			hasPreorderSavings: hasPreorderSavings
		};
	}

	// % del tramo de mayor `min` ≤ cantidad (espejo de VolumeTierResolver).
	function resolvePct( tiers, qty ) {
		var best = 0;
		var bestMin = -1;
		tiers.forEach( function ( tier ) {
			if ( qty >= tier.min && tier.min > bestMin ) {
				bestMin = tier.min;
				best = tier.pct;
			}
		} );
		return best;
	}

	/* ----------------------------------------------------------------------
	 * Builder "Arma tu pack" (single product): selección de tomos, cálculo del
	 * descuento por volumen en vivo y alta conjunta al carrito vía AJAX.
	 * -------------------------------------------------------------------- */
	function initBuilders() {
		var cfg = window.akbSbBuilder || {};
		var i18n = cfg.i18n || {};

		var builders = document.querySelectorAll( '[data-akb-sb-builder]' );

		builders.forEach( function ( root ) {
			var tiers = parseTiers( root.getAttribute( 'data-tiers' ) );
				var termId = parseInt( root.getAttribute( 'data-term-id' ), 10 ) || 0;
				var checks = root.querySelectorAll( '[data-akb-sb-vol]' );
				var countEl = root.querySelector( '[data-akb-sb-count]' );
				var discEl = root.querySelector( '[data-akb-sb-disc]' );
				var totalEl = root.querySelector( '[data-akb-sb-total]' );
				var savingsEl = root.querySelector( '[data-akb-sb-savings]' );
				var addBtn = root.querySelector( '[data-akb-sb-add]' );
			var msgEl = root.querySelector( '[data-akb-sb-msg]' );
			var freeShip = parseFloat( root.getAttribute( 'data-free-ship' ) ) || 0;
			var prevReached = 0; // #23: tramos alcanzados en el refresh previo (detecta cruce nuevo)
			var volumeItems = buildVolumeItems();
			var activeRange = { min: null, max: null };

			function selected() {
				var ids = [];
				var items = [];
				checks.forEach( function ( c ) {
					if ( c.checked ) {
						var current = parseFloat( c.getAttribute( 'data-price' ) ) || 0;
						var regular = parseFloat( c.getAttribute( 'data-regular-price' ) ) || current;
						ids.push( parseInt( c.value, 10 ) );
						items.push( {
							current: current,
							regular: regular,
							discountEligible: c.getAttribute( 'data-discount-eligible' ) === '1'
						} );
					}
				} );
				return { ids: ids, items: items };
			}

			function buildVolumeItems() {
				var items = [];
				checks.forEach( function ( c ) {
					var card = c.closest( '.akb-sb-builder__card' );
					var volume = parseInt( c.getAttribute( 'data-akb-sb-volume' ), 10 );
					items.push( {
						check: c,
							card: card,
							volume: isNaN( volume ) ? null : volume,
							current: c.getAttribute( 'data-akb-sb-current' ) === '1',
						// BYP-PREVENTA-CLARIDAD (S1): marca de preventa para el filtro "solo en stock".
						preventa: c.getAttribute( 'data-akb-sb-preventa' ) === '1'
					} );
				} );
				return items;
			}

			function initPickerTools() {
				var tools = root.querySelector( '[data-akb-sb-tools]' );
				if ( ! tools ) {
					return;
				}

				var rangeButtons = tools.querySelectorAll( '[data-akb-sb-range]' );
				var bulkButtons = tools.querySelectorAll( '[data-akb-sb-visible-action]' );
				var statusEl = tools.querySelector( '[data-akb-sb-filter-status]' );
				var rangeStrip = tools.querySelector( '.akb-sb-builder__ranges' );
				var stockToggle = tools.querySelector( '[data-akb-sb-stock-toggle]' );
				// BYP-PREVENTA-CLARIDAD (S1): filtro client-side "solo en stock".
				var onlyInStock = false;

				// Series largas: la tira de chips de rango scrollea horizontal. Al activar
				// uno, lo centramos DENTRO de la tira (solo scroll horizontal de la tira,
				// nunca de la página) para que no quede tapado fuera de vista.
				function centerChip( btn ) {
					if ( ! rangeStrip ) {
						return;
					}
					var stripRect = rangeStrip.getBoundingClientRect();
					var btnRect = btn.getBoundingClientRect();
					var delta = ( btnRect.left + btnRect.width / 2 ) - ( stripRect.left + stripRect.width / 2 );
					rangeStrip.scrollLeft += delta;
				}

				function matchesFilters( item ) {
					var matchesRange = activeRange.min === null || ( item.volume !== null && item.volume >= activeRange.min && item.volume <= activeRange.max );
					// S1: "solo en stock" oculta los tomos en preventa (AND con el rango).
					var matchesStock = ! onlyInStock || ! item.preventa;
					return matchesRange && matchesStock;
				}

				function activateRange( btn ) {
					rangeButtons.forEach( function ( other ) {
						other.classList.remove( 'is-active' );
						other.setAttribute( 'aria-pressed', 'false' );
					} );
					btn.classList.add( 'is-active' );
					btn.setAttribute( 'aria-pressed', 'true' );
					centerChip( btn );

					if ( btn.getAttribute( 'data-akb-sb-range' ) === 'all' ) {
						activeRange = { min: null, max: null };
						return;
					}
					activeRange = {
						min: parseInt( btn.getAttribute( 'data-akb-sb-range-min' ), 10 ) || 0,
						max: parseInt( btn.getAttribute( 'data-akb-sb-range-max' ), 10 ) || 0
					};
					var wrap = root.querySelector( '.akb-sb-builder__grid-wrap' );
					if ( wrap ) {
						wrap.classList.add( 'is-expanded' );
						var expandBtn = root.querySelector( '[data-akb-sb-expand-btn]' );
						if ( expandBtn ) {
							expandBtn.setAttribute( 'aria-expanded', 'true' );
							var textEl = expandBtn.querySelector( '.akb-sb-builder__expand-text' );
							var iconEl = expandBtn.querySelector( '.akb-sb-builder__expand-icon' );
							if ( textEl ) {
								textEl.textContent = 'Ocultar tomos';
							}
							if ( iconEl ) {
								iconEl.textContent = '▴';
							}
						}
					}
				}

				function applyFilters() {
					var visible = 0;
					var selectable = 0;
					volumeItems.forEach( function ( item ) {
						if ( ! item.card ) {
							return;
						}
						var show = matchesFilters( item );
						item.card.hidden = ! show;
						item.card.classList.toggle( 'is-filtered-out', ! show );
						if ( show ) {
							visible++;
							if ( item.check && ! item.check.disabled ) {
								selectable++;
							}
						}
					} );

					if ( statusEl ) {
						if ( visible === 0 ) {
							statusEl.textContent = 'Sin resultados';
						} else if ( visible === volumeItems.length ) {
							statusEl.textContent = 'Mostrando ' + visible + ( visible === 1 ? ' tomo' : ' tomos' );
						} else {
							statusEl.textContent = 'Mostrando ' + visible + ' de ' + volumeItems.length + ' tomos';
						}
					}

					bulkButtons.forEach( function ( btn ) {
						if ( btn.getAttribute( 'data-akb-sb-visible-action' ) !== 'select' ) {
							return;
						}
						if ( selectable === 0 ) {
							btn.textContent = visible === 0 ? 'Sin tomos visibles' : 'Sin tomos disponibles';
							btn.disabled = true;
						} else {
							btn.textContent = selectable === 1
								? 'Seleccionar el tomo disponible'
								: 'Seleccionar los ' + selectable + ' tomos disponibles';
							btn.disabled = false;
						}
					} );
				}

				rangeButtons.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						activateRange( btn );
						applyFilters();
					} );
				} );

				bulkButtons.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var action = btn.getAttribute( 'data-akb-sb-visible-action' );
						var shouldSelect = action === 'select';
						volumeItems.forEach( function ( item ) {
							if ( item.check.disabled ) {
								return;
							}
							if ( action === 'clear-all' || ( item.card && ! item.card.hidden ) ) {
								item.check.checked = shouldSelect;
							}
						} );
						refresh();
					} );
				} );

				var shortcutButtons = tools.querySelectorAll( '[data-akb-sb-shortcut]' );
				shortcutButtons.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var action = btn.getAttribute( 'data-akb-sb-shortcut' );
						if ( action === 'stock' ) {
							volumeItems.forEach( function ( item ) {
								if ( ! item.check.disabled ) {
									item.check.checked = ! item.preventa;
								}
							} );
						} else if ( action === 'last5' ) {
							volumeItems.forEach( function ( item ) {
								if ( ! item.check.disabled ) {
									item.check.checked = false;
								}
							} );
							var purchasable = volumeItems.filter( function ( item ) {
								return ! item.check.disabled;
							} );
							purchasable.slice( -5 ).forEach( function ( item ) {
								item.check.checked = true;
							} );
						} else if ( action === 'clear' ) {
							volumeItems.forEach( function ( item ) {
								if ( ! item.check.disabled ) {
									item.check.checked = false;
								}
							} );
						}
						refresh();
					} );
				} );

				if ( stockToggle ) {
					stockToggle.addEventListener( 'change', function () {
						onlyInStock = stockToggle.checked;
						// Al ocultar preventa, desmarcar los que queden fuera de vista para
						// no dejar un "pack fantasma" (tomos seleccionados que no se ven).
						if ( onlyInStock ) {
							volumeItems.forEach( function ( item ) {
								if ( item.preventa && item.check.checked ) {
									item.check.checked = false;
								}
							} );
						}
						applyFilters();
						refresh();
					} );
				}

				applyFilters();
			}

			function refresh() {
				var sel = selected();
				var qty = sel.ids.length;
				var eligibleQty = sel.items.filter( function ( item ) { return item.discountEligible; } ).length;
				var pct = resolvePct( tiers, eligibleQty );

					// Progress bar update
					var progressBar = root.querySelector( '[data-akb-sb-progress]' );
				if ( progressBar ) {
					var maxQty = parseInt( progressBar.getAttribute( 'data-max' ), 10 ) || 1;
					var fill = Math.min( 100, Math.round( ( eligibleQty / maxQty ) * 100 ) );
					progressBar.style.width = fill + '%';
				}

				// #23: la celebración irradia de la barra al cruzar un tramo NUEVO
				// (flash rojo->amarillo + pulse del builder), no solo el chip.
				var reachedNow = 0;
				tiers.forEach( function ( t ) { if ( eligibleQty >= t.min ) { reachedNow++; } } );
				if ( reachedNow > prevReached ) {
					if ( progressBar ) {
						progressBar.classList.remove( 'akb-animate-flash' );
						void progressBar.offsetWidth;
						progressBar.classList.add( 'akb-animate-flash' );
					}
					root.classList.remove( 'akb-animate-pulse' );
					void root.offsetWidth;
					root.classList.add( 'akb-animate-pulse' );
				}
				prevReached = reachedNow;

				// Price updates per item
					checks.forEach( function ( c ) {
						var itemWrap = c.closest( '.akb-sb-builder__card' );
						var priceEl = itemWrap.querySelector( '[data-akb-sb-vol-price]' );
						if ( ! priceEl ) return;
						if ( c.disabled ) {
							itemWrap.classList.remove( 'is-selected' );
							return;
						}

					var current = parseFloat( c.getAttribute( 'data-price' ) ) || 0;
					var regular = parseFloat( c.getAttribute( 'data-regular-price' ) ) || current;
					var discountEligible = c.getAttribute( 'data-discount-eligible' ) === '1';

					if ( c.checked ) {
						itemWrap.classList.add( 'is-selected' );
					} else {
						itemWrap.classList.remove( 'is-selected' );
					}

					// If unchecked or no tier met, reset to original markup (which is just current price)
					if ( ! c.checked || pct === 0 || ! discountEligible ) {
						priceEl.innerHTML = formatMoney( current );
						return;
					}

					var target = regular * ( 1 - ( pct / 100 ) );
					var contribution = Math.round( Math.max( 0, current - target ) );
					var finalPrice = current - contribution;
					if ( contribution > 0 ) {
						priceEl.innerHTML = '<del>' + formatMoney( regular ) + '</del> <ins>' + formatMoney( finalPrice ) + '</ins>';
					} else {
						priceEl.innerHTML = formatMoney( current );
					}
				} );

					var totals = pricedTotals( sel.items, pct );

					// Gamification: Glow tiers
				var tierNodes = root.querySelectorAll('.akb-sb-builder__tier');
				tierNodes.forEach( function( node ) {
					var nodeMin = parseInt( node.getAttribute('data-min'), 10 ) || 0;
					if ( eligibleQty >= nodeMin ) {
						if ( !node.classList.contains('is-reached') ) {
							node.classList.add('is-reached');
							node.classList.remove('akb-animate-unlock');
							void node.offsetWidth; // trigger reflow
							node.classList.add('akb-animate-unlock');
						}
					} else {
						node.classList.remove('is-reached');
					}
				});

				if ( countEl ) {
					countEl.textContent = qty + ( qty === 1 ? ' tomo seleccionado' : ' tomos seleccionados' );
				}
				if ( discEl ) {
					if ( pct > 0 ) {
						// Animating text if changed to a new discount
						var newText = totals.savings > 0
							? format( i18n.discFmt || '%1$d tomos: %2$d%% off', eligibleQty, pct )
							: 'Tramo de ' + pct + '% alcanzado con ' + eligibleQty + ( eligibleQty === 1 ? ' tomo elegible' : ' tomos elegibles' ) + ' · Ya tienen un precio igual o mejor';
						if ( discEl.textContent !== newText ) {
							discEl.classList.remove('akb-animate-pop');
							void discEl.offsetWidth; // trigger reflow
							discEl.classList.add('akb-animate-pop');
						}
						discEl.textContent = newText;
						discEl.classList.add( 'is-active' );
						
							if ( savingsEl ) {
								if ( totals.hasPreorderSavings && totals.totalSavingsVsRegular > totals.savings ) {
									savingsEl.textContent = 'Ahorras ' + formatMoney( totals.totalSavingsVsRegular ) + ' sobre precio normal (' + formatMoney( totals.savings ) + ' extra con pack)';
								} else {
									savingsEl.textContent = 'Ahorras ' + formatMoney( totals.savings );
								}
								savingsEl.hidden = ( totals.totalSavingsVsRegular || totals.savings ) <= 0;
							}
						} else {
						// Goal-gradient: si hay un próximo tramo alcanzable, mostrar cuánto
						// falta (espejo del aviso del carrito). En amarillo (is-active).
						var nextMin = 0, nextPct = 0;
						tiers.forEach( function ( t ) {
							if ( t.min > eligibleQty && ( nextMin === 0 || t.min < nextMin ) ) {
								nextMin = t.min;
								nextPct = t.pct;
							}
						} );
						if ( qty > 0 && nextMin > 0 ) {
							var falta = nextMin - eligibleQty;
							discEl.textContent = 'Suma ' + falta + ( falta === 1 ? ' tomo elegible' : ' tomos elegibles' ) + ' más y obtén ' + nextPct + '% de descuento';
								discEl.classList.add( 'is-active' );
						} else {
							discEl.textContent = i18n.noDisc || '';
							discEl.classList.remove( 'is-active' );
						}
							if ( savingsEl ) {
								savingsEl.hidden = true;
							}
						}
					}
					if ( totalEl ) {
						var formattedTotal = formatMoney( totals.finalTotal );
						totalEl.textContent = qty > 0 ? 'Total estimado: ' + formattedTotal : '';
						totalEl.hidden = qty === 0;
						if ( qty > 0 ) {
							totalEl.setAttribute( 'data-akb-sb-total-value', String( Math.round( totals.finalTotal ) ) );
							totalEl.setAttribute( 'data-akb-sb-total-label', formattedTotal );
						} else {
							totalEl.removeAttribute( 'data-akb-sb-total-value' );
							totalEl.removeAttribute( 'data-akb-sb-total-label' );
						}
					}
				// Envío gratis (#17): sobre el total CON descuento del pack. Como el
				// carrito total >= el pack, si el pack llega al umbral el envío gratis
				// está garantizado (conservador; nunca lo promete de más).
				if ( freeShip > 0 ) {
					var fsEl = root.querySelector( '.akb-sb-builder__freeship' );
					var summaryEl = root.querySelector( '[data-akb-sb-summary]' );
					if ( qty === 0 ) {
						if ( fsEl ) { fsEl.style.display = 'none'; }
					} else if ( summaryEl ) {
						if ( ! fsEl ) {
							fsEl = document.createElement( 'div' );
							fsEl.className = 'akb-sb-builder__freeship';
							summaryEl.appendChild( fsEl );
						}
						fsEl.style.display = '';
						if ( totals.finalTotal >= freeShip ) {
							if ( ! fsEl.classList.contains( 'is-reached' ) ) {
								fsEl.classList.add( 'is-reached' );
								fsEl.classList.remove( 'akb-animate-pop' );
								void fsEl.offsetWidth;
								fsEl.classList.add( 'akb-animate-pop' );
							}
							fsEl.textContent = 'Envío gratis desbloqueado';
						} else {
							fsEl.classList.remove( 'is-reached' );
							fsEl.textContent = 'Te faltan ' + formatMoney( freeShip - totals.finalTotal ) + ' para envío gratis';
						}
					}
				}

					if ( addBtn ) {
						addBtn.disabled = qty === 0;
					}
					try {
						document.dispatchEvent( new CustomEvent( 'akb-sb-refresh', {
							detail: {
								qty: qty,
								total: totals.finalTotal,
								formattedTotal: formatMoney( totals.finalTotal ),
								seriesName: root.getAttribute( 'data-serie' ) || ''
							}
						} ) );
					} catch ( e ) {}
			}

			checks.forEach( function ( c ) {
				c.addEventListener( 'change', refresh );
			} );

			if ( addBtn ) {
				addBtn.addEventListener( 'click', function () {
					var sel = selected();
					if ( sel.ids.length === 0 ) {
						setMsg( msgEl, i18n.empty || 'Elige al menos un tomo.', 'error' );
						return;
					}
					submit( cfg, sel.ids, addBtn, msgEl, i18n, checks, refresh, termId );
				} );
			}

			// «Completá con 1 clic» selecciona los faltantes en stock y reutiliza refresh(). Sólo se
			// ofrece si el servidor confirma todos los faltantes disponibles; conserva los tomos ya
			// adquiridos y los agotados sin seleccionar.
			var completeBtn = root.querySelector( '[data-akb-sb-complete]' );
			if ( completeBtn ) {
				completeBtn.addEventListener( 'click', function () {
					var ids = [];
					try {
						ids = JSON.parse( completeBtn.getAttribute( 'data-akb-sb-complete' ) ) || [];
					} catch ( e ) {
						ids = [];
					}
					ids.forEach( function ( id ) {
						checks.forEach( function ( c ) {
							if ( parseInt( c.value, 10 ) === id && ! c.disabled ) {
								c.checked = true;
							}
						} );
					} );
					refresh();
					completeBtn.classList.remove( 'akb-animate-pop' );
					void completeBtn.offsetWidth; // reflow para re-disparar la animación
					completeBtn.classList.add( 'akb-animate-pop' );
						if ( addBtn ) {
							var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
							addBtn.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' } );
						}
				} );
			}

			function initExpandControl() {
				var wrap = root.querySelector( '.akb-sb-builder__grid-wrap' );
				var expandBtn = root.querySelector( '[data-akb-sb-expand-btn]' );
				if ( ! wrap || ! expandBtn ) {
					return;
				}
				var total = parseInt( expandBtn.getAttribute( 'data-total' ), 10 ) || 0;
				expandBtn.addEventListener( 'click', function () {
					var isExp = wrap.classList.toggle( 'is-expanded' );
					expandBtn.setAttribute( 'aria-expanded', isExp ? 'true' : 'false' );
					var textEl = expandBtn.querySelector( '.akb-sb-builder__expand-text' );
					var iconEl = expandBtn.querySelector( '.akb-sb-builder__expand-icon' );
					if ( isExp ) {
						if ( textEl ) {
							textEl.textContent = 'Ocultar tomos';
						}
						if ( iconEl ) {
							iconEl.textContent = '▴';
						}
					} else {
						if ( textEl ) {
							textEl.textContent = total > 0 ? 'Ver los ' + total + ' tomos de la serie' : 'Ver todos los tomos';
						}
						if ( iconEl ) {
							iconEl.textContent = '▾';
						}
						var wrapRect = wrap.getBoundingClientRect();
						if ( wrapRect.top < 0 ) {
							var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
							wrap.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' } );
						}
					}
				} );
			}

			initPickerTools();
			initExpandControl();
			refresh();
			initScrollAffordance( root );
			root.querySelectorAll( '[data-akb-sb-interactive]' ).forEach( function ( interactive ) {
				interactive.hidden = false;
			} );
			root.classList.add( 'is-js-ready' );
		} );
	}

	/* ----------------------------------------------------------------------
	 * Landing de serie: activa una capa de selección sobre las cards existentes.
	 * No clona portadas y no pide la colección personal hasta que hay intención.
	 * -------------------------------------------------------------------- */
	var LANDING_STATE_VERSION = 3;
	var LANDING_STATE_TTL = 6 * 60 * 60 * 1000;

	function normalizeLandingItem( raw ) {
		if ( ! raw || typeof raw !== 'object' ) {
			return null;
		}
		var id = parseInt( raw.id, 10 );
		var current = Number( raw.current );
		var regular = Number( raw.regular );
		if ( ! id || id < 1 || ! isFinite( current ) || current < 0 ) {
			return null;
		}
		if ( ! isFinite( regular ) || regular <= 0 ) {
			regular = current;
		}
		return {
			id: id,
			current: current,
			regular: regular,
			title: String( raw.title || '' ).slice( 0, 220 ),
			status: String( raw.status || '' ).slice( 0, 24 ),
			discountEligible: raw.discountEligible === true || raw.discountEligible === 1 || raw.discountEligible === '1' || raw.discount_eligible === true || raw.discount_eligible === 1 || raw.discount_eligible === '1'
		};
	}

	function normalizeLandingState( raw, termId, maxSelection, now ) {
		var empty = {
			version: LANDING_STATE_VERSION,
			termId: termId,
			updatedAt: now,
			active: false,
			items: {}
		};
		if ( ! raw || typeof raw !== 'object' || parseInt( raw.version, 10 ) !== LANDING_STATE_VERSION || parseInt( raw.termId, 10 ) !== termId ) {
			return empty;
		}
		var updatedAt = Number( raw.updatedAt ) || 0;
		if ( updatedAt <= 0 || now - updatedAt > LANDING_STATE_TTL ) {
			return empty;
		}

		var source = raw.items && typeof raw.items === 'object' ? raw.items : {};
		var items = {};
		Object.keys( source ).some( function ( key ) {
			if ( Object.keys( items ).length >= maxSelection ) {
				return true;
			}
			var item = normalizeLandingItem( source[ key ] );
			if ( item ) {
				items[ String( item.id ) ] = item;
			}
			return false;
		} );

		return {
			version: LANDING_STATE_VERSION,
			termId: termId,
			updatedAt: updatedAt,
			active: raw.active === true,
			items: items
		};
	}

	function reconcileLandingItems( items, addedIds ) {
		var next = {};
		var added = {};
		( addedIds || [] ).forEach( function ( id ) {
			added[ String( parseInt( id, 10 ) || 0 ) ] = true;
		} );
		Object.keys( items || {} ).forEach( function ( key ) {
			if ( ! added[ key ] ) {
				next[ key ] = items[ key ];
			}
		} );
		return next;
	}

	function reconcileSelectedIds( selectedIds, addedIds ) {
		var added = {};
		( addedIds || [] ).forEach( function ( id ) {
			added[ String( parseInt( id, 10 ) || 0 ) ] = true;
		} );
		return ( selectedIds || [] ).filter( function ( id ) {
			return ! added[ String( parseInt( id, 10 ) || 0 ) ];
		} );
	}

	// Tras un alta múltiple, desmarcar lo agregado y los rejected_ids definitivos de
	// SelectionCart: unidades ya presentes al máximo o agotadas. Mantener seleccionados únicamente
	// los fallos que un reintento pueda resolver.
	function settledIds( data, addedIds ) {
		var rejected = data && Array.isArray( data.rejected_ids ) ? data.rejected_ids : [];
		return ( addedIds || [] ).concat( rejected );
	}

	// En un alta parcial, Y suma lo agregado y lo que permanece seleccionado para reintentar.
	// Excluir settledIds, que ya estaban en el carrito al máximo o se agotaron, para que el
	// mensaje coincida con la selección visible.
	function partialMessage( tpl, added, remaining ) {
		return format( tpl, added, added + remaining );
	}

	function reconcileVisibleLandingItems( items, visibleItems, unavailableIds ) {
		var next = Object.assign( {}, items || {} );
		( visibleItems || [] ).forEach( function ( raw ) {
			var item = normalizeLandingItem( raw );
			var key = item ? String( item.id ) : '';
			if ( key && Object.prototype.hasOwnProperty.call( next, key ) ) {
				next[ key ] = item;
			}
		} );
		( unavailableIds || [] ).forEach( function ( id ) {
			delete next[ String( parseInt( id, 10 ) || 0 ) ];
		} );
		return next;
	}

	function initLandingRails() {
		var cfg = window.akbSbLanding || {};
		var roots = document.querySelectorAll( '[data-akb-sb-landing]' );
		var initializedTerms = {};
		roots.forEach( function ( root ) {
			if ( root.getAttribute( 'data-akb-sb-initialized' ) === '1' ) {
				return;
			}
			var termId = parseInt( root.getAttribute( 'data-term-id' ), 10 ) || 0;
			if ( termId <= 0 || initializedTerms[ String( termId ) ] ) {
				return;
			}
			initializedTerms[ String( termId ) ] = true;
			root.setAttribute( 'data-akb-sb-initialized', '1' );

			var tiers = parseTiers( root.getAttribute( 'data-tiers' ) );
			var maxSelection = Math.max( 1, parseInt( cfg.maxSelection, 10 ) || 300 );
			var storageKey = 'akb:series-bundle:' + termId;
			var panel = root.querySelector( '[data-akb-sb-landing-panel]' );
			var start = root.querySelector( '[data-akb-sb-landing-start]' );
			var closeBtn = root.querySelector( '[data-akb-sb-landing-close]' );
			var addBtn = root.querySelector( '[data-akb-sb-landing-add]' );
			var clearBtn = root.querySelector( '[data-akb-sb-landing-clear]' );
			var countEl = root.querySelector( '[data-akb-sb-landing-count]' );
			var totalEl = root.querySelector( '[data-akb-sb-landing-total]' );
			var discountEl = root.querySelector( '[data-akb-sb-landing-discount]' );
			var packCounter = root.querySelector( '[data-akb-sb-pack-counter]' );
			var packPill = root.querySelector( '[data-akb-sb-pack-pill]' );
			var packBar = root.querySelector( '[data-akb-sb-pack-bar]' );
			var msgEl = root.querySelector( '[data-akb-sb-landing-message]' );
			var collection = root.querySelector( '[data-akb-sb-landing-collection]' );
			var collectionStatus = root.querySelector( '[data-akb-sb-landing-collection-status]' );
			var login = root.querySelector( '[data-akb-sb-landing-login]' );
			var completeBtn = root.querySelector( '[data-akb-sb-landing-complete]' );
			var ownedIds = {};
			var collectionLoaded = false;
			var restoreStartFocusAfterPjax = false;
			var productLinkStates = new WeakMap();
			var state = readState();

			function readState() {
				var raw = null;
				try {
					raw = JSON.parse( window.sessionStorage.getItem( storageKey ) || 'null' );
				} catch ( error ) {
					raw = null;
				}
				return normalizeLandingState( raw, termId, maxSelection, Date.now() );
			}

			function writeState() {
				state.updatedAt = Date.now();
				try {
					window.sessionStorage.setItem( storageKey, JSON.stringify( state ) );
				} catch ( error ) {
					// Safari privado/cuota: el estado en memoria sigue funcionando.
				}
			}

			function selectedItems() {
				return Object.keys( state.items ).map( function ( key ) {
					return state.items[ key ];
				} );
			}

			function itemFromCheck( check ) {
				return normalizeLandingItem( {
					id: check.value,
					current: check.getAttribute( 'data-price' ),
					regular: check.getAttribute( 'data-regular-price' ),
					title: check.getAttribute( 'data-title' ),
					status: check.getAttribute( 'data-status' ),
					discountEligible: check.getAttribute( 'data-discount-eligible' )
				} );
			}

			function currentChecks() {
				return document.querySelectorAll( '[data-akb-sb-landing-vol][data-term-id="' + termId + '"]' );
			}

			function reconcileCurrentItems() {
				var visibleItems = [];
				var unavailableIds = [];
				currentChecks().forEach( function ( check ) {
					var item = itemFromCheck( check );
					if ( check.disabled || ! item ) {
						unavailableIds.push( check.value );
						return;
					}
					visibleItems.push( item );
				} );
				state.items = reconcileVisibleLandingItems( state.items, visibleItems, unavailableIds );
			}

			function attributeState( element, name ) {
				return {
					exists: element.hasAttribute( name ),
					value: element.getAttribute( name )
				};
			}

			function restoreAttribute( element, name, original ) {
				if ( original.exists ) {
					element.setAttribute( name, original.value );
				} else {
					element.removeAttribute( name );
				}
			}

			function syncProductLink( check, card ) {
				if ( ! card ) {
					return;
				}
				var link = card.querySelector( 'a.woocommerce-LoopProduct-link' );
				if ( ! link ) {
					return;
				}

				if ( state.active && ! check.disabled ) {
					if ( ! productLinkStates.has( link ) ) {
						productLinkStates.set( link, {
							href: attributeState( link, 'href' )
						} );
					}
					link.removeAttribute( 'href' );
					link.setAttribute( 'data-akb-sb-card-toggle', String( termId ) );
					return;
				}

				var original = productLinkStates.get( link );
				if ( original ) {
					restoreAttribute( link, 'href', original.href );
					productLinkStates.delete( link );
				}
				link.removeAttribute( 'data-akb-sb-card-toggle' );
			}

			function toggleProductLinkSelection( link ) {
				if ( ! state.active || ! link ) {
					return false;
				}
				var card = link.closest( 'li.product' );
				var check = card ? card.querySelector( '[data-akb-sb-landing-vol]:not([disabled])' ) : null;
				if ( ! check ) {
					return false;
				}
				check.checked = ! check.checked;
				check.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				return true;
			}

			function syncCards() {
				currentChecks().forEach( function ( check ) {
					var selector = check.closest( '[data-akb-sb-card-selector]' );
					var card = check.closest( 'li.product' );
					var grid = card && card.closest( 'ul.products' );
					if ( grid ) {
						grid.classList.toggle( 'akb-sb-selection-grid', state.active );
					}
					var key = String( parseInt( check.value, 10 ) || 0 );
					var owned = !! ownedIds[ key ];
					check.checked = !! state.items[ key ];
					if ( selector ) {
						selector.hidden = ! state.active;
						if ( selector.getAttribute( 'data-akb-sb-click-guard' ) !== '1' ) {
							selector.setAttribute( 'data-akb-sb-click-guard', '1' );
							selector.addEventListener( 'click', function ( event ) {
								event.stopPropagation();
							} );
						}
						var label = selector.querySelector( '.akb-sb-card-select__text' );
						if ( label && ! label.getAttribute( 'data-default-label' ) ) {
							label.setAttribute( 'data-default-label', label.textContent );
						}
						if ( label ) {
							label.textContent = check.checked
								? 'Seleccionado'
								: ( owned && ! check.disabled
									? '+ Sumar'
									: label.getAttribute( 'data-default-label' ) );
						}
						selector.classList.toggle( 'is-selected', check.checked );
						var badge = selector.querySelector( '.akb-sb-card-owned' );
						if ( owned && ! badge ) {
							badge = document.createElement( 'span' );
							badge.className = 'akb-sb-card-owned';
							badge.textContent = '✓ En tu colección';
							selector.appendChild( badge );
						} else if ( ! owned && badge ) {
							badge.remove();
						}
					}
					if ( card ) {
						card.classList.toggle( 'akb-sb-is-selected', check.checked );
						card.classList.toggle( 'akb-sb-is-owned', owned );
					}
					syncProductLink( check, card );
				} );
			}

			function nextTier( qty ) {
				var next = null;
				tiers.forEach( function ( tier ) {
					if ( tier.min > qty && ( ! next || tier.min < next.min ) ) {
						next = tier;
					}
				} );
				return next;
			}

			function refresh() {
				reconcileCurrentItems();
				var items = selectedItems();
				var qty = items.length;
				var eligibleQty = items.filter( function ( item ) { return item.discountEligible; } ).length;
				var pct = resolvePct( tiers, eligibleQty );
				var totals = pricedTotals( items, pct );
				var upcoming = nextTier( eligibleQty );

				// Con algo ya elegido, la bandeja deja de explicar cómo elegir: el
				// cliente lo demostró. En móvil la bandeja es fija y, con la barra
				// inferior, ocupaba el 40% de la pantalla dejando ver dos filas de
				// tomos; las instrucciones eran su bloque más alto. La clase la
				// consume el CSS (`.has-selection`), acá sólo se publica el estado.
				if ( panel ) {
					panel.classList.toggle( 'has-selection', qty > 0 );
				}

				if ( countEl ) {
					countEl.textContent = qty + ( qty === 1 ? ' tomo seleccionado' : ' tomos seleccionados' );
				}
				if ( totalEl ) {
					totalEl.textContent = 'Total estimado del pack: ' + formatMoney( totals.finalTotal );
				}
				if ( discountEl ) {
					if ( pct > 0 ) {
						if ( totals.hasPreorderSavings && totals.totalSavingsVsRegular > totals.savings ) {
							discountEl.textContent = pct + '% OFF sobre precio lista ' + formatMoney( totals.regularTotal ) + ' · Ahorras ' + formatMoney( totals.totalSavingsVsRegular ) + ' en total (' + formatMoney( totals.savings ) + ' extra con pack)';
						} else if ( totals.savings > 0 ) {
							discountEl.textContent = pct + '% de descuento en ' + eligibleQty + ( eligibleQty === 1 ? ' tomo elegible' : ' tomos elegibles' ) + ' · Ahorras aprox. ' + formatMoney( totals.savings );
						} else {
							discountEl.textContent = 'Tramo de ' + pct + '% alcanzado con ' + eligibleQty + ( eligibleQty === 1 ? ' tomo elegible' : ' tomos elegibles' ) + ' · Ya tienen un precio igual o mejor';
						}
					} else if ( qty > 0 && upcoming ) {
						var missing = upcoming.min - eligibleQty;
						discountEl.textContent = 'Suma ' + missing + ( missing === 1 ? ' tomo elegible' : ' tomos elegibles' ) + ' más para obtener ' + upcoming.pct + '% de descuento.';
					} else if ( tiers.length === 0 ) {
						discountEl.textContent = qty > 0 ? 'El carrito confirmará precio y stock antes de agregar.' : 'Selecciona los tomos que quieres comprar.';
					} else {
						discountEl.textContent = qty > 0 ? 'El carrito respetará el mejor precio de cada tomo.' : 'Selecciona tomos para ver tu descuento.';
					}
				}
				if ( tiers.length > 0 && packCounter && packPill ) {
					if ( qty === 0 ) {
						packCounter.hidden = true;
						packCounter.classList.remove( 'is-unlocked' );
					} else {
						packCounter.hidden = false;
						var firstTier = tiers[0];
						var topTier = tiers[ tiers.length - 1 ];
						var progressPct = Math.min( 100, Math.round( ( eligibleQty / topTier.min ) * 100 ) );

						if ( eligibleQty < firstTier.min ) {
							packCounter.classList.remove( 'is-unlocked' );
							var nextMin = firstTier.min;
							var nextPct = firstTier.pct;
							var faltan = nextMin - eligibleQty;
							packPill.innerHTML = '⚡ Llevas <strong>' + eligibleQty + ' de ' + nextMin + ' tomos</strong> para tu ' + nextPct + '% dto' + ( topTier.min > nextMin ? ' (o ' + topTier.min + ' para ' + topTier.pct + '% dto)' : '' ) + ' · Suma ' + faltan + ( faltan === 1 ? ' tomo más' : ' tomos más' );
						} else if ( eligibleQty >= firstTier.min && eligibleQty < topTier.min ) {
							packCounter.classList.add( 'is-unlocked' );
							var faltanTop = topTier.min - eligibleQty;
							packPill.innerHTML = '🎉 ¡' + pct + '% dto activado! Llevas <strong>' + eligibleQty + ' de ' + topTier.min + ' tomos</strong> para subir al ' + topTier.pct + '% dto · Suma ' + faltanTop + ( faltanTop === 1 ? ' tomo más' : ' tomos más' );
						} else {
							packCounter.classList.add( 'is-unlocked' );
							packPill.innerHTML = '🔥 ¡' + topTier.pct + '% de descuento máximo alcanzado! (' + eligibleQty + ( eligibleQty === 1 ? ' tomo seleccionado)' : ' tomos seleccionados)' );
							progressPct = 100;
						}
						if ( packBar ) {
							packBar.style.width = progressPct + '%';
						}
					}
				}
				if ( start ) {
					var startLabel = start.querySelector( '[data-akb-sb-landing-start-label]' );
					if ( startLabel ) {
						if ( state.active ) {
							startLabel.textContent = qty > 0 ? 'Ocultar selección (' + qty + ')' : 'Ocultar selección';
						} else {
							startLabel.textContent = qty > 0 ? 'Ver selección (' + qty + ')' : 'Elegir tomos';
						}
					}
				}
				if ( addBtn ) {
					addBtn.disabled = qty === 0;
				}
				if ( clearBtn ) {
					clearBtn.disabled = qty === 0;
				}
				syncCards();
				writeState();
			}

			function setActive( active, updateHistory ) {
				if ( active ) {
					restoreStartFocusAfterPjax = false;
				}
				state.active = active;
				if ( panel ) {
					panel.hidden = ! active;
				}
				if ( start ) {
					start.setAttribute( 'aria-expanded', active ? 'true' : 'false' );
					var startLabel = start.querySelector( '[data-akb-sb-landing-start-label]' );
					if ( startLabel ) {
						startLabel.textContent = active ? 'Ocultar selección' : 'Elegir tomos';
					}
				}
				document.body.classList.toggle( 'akb-sb-selection-active', active );
				// Reabrir desde el hash o después de un filtro también debe restaurar
				// el subtotal y las acciones, no sólo el check visual de cada card.
				refresh();

				if ( active ) {
					if ( updateHistory && window.location.hash !== '#completar-coleccion' ) {
						window.history.pushState(
							Object.assign( {}, window.history.state || {}, { akbSeriesSelectionTermId: termId } ),
							'',
							window.location.pathname + window.location.search + '#completar-coleccion'
						);
					} else if ( ! updateHistory && window.location.hash !== '#completar-coleccion' ) {
						window.history.replaceState( window.history.state, '', window.location.pathname + window.location.search + '#completar-coleccion' );
					}
					loadCollection();
				} else if ( updateHistory && window.location.hash === '#completar-coleccion' ) {
					if ( window.history.state && window.history.state.akbSeriesSelectionTermId === termId ) {
						restoreStartFocusAfterPjax = true;
						window.history.back();
					} else {
						window.history.replaceState( window.history.state, '', window.location.pathname + window.location.search );
					}
				}
			}

			function showCollectionMessage( text ) {
				if ( collection ) {
					collection.hidden = false;
				}
				if ( collectionStatus ) {
					collectionStatus.textContent = text;
				}
			}

			function mergeMissingItems( items ) {
				( items || [] ).forEach( function ( raw ) {
					if ( Object.keys( state.items ).length >= maxSelection ) {
						return;
					}
					var item = normalizeLandingItem( raw );
					if ( item && ! ownedIds[ String( item.id ) ] ) {
						state.items[ String( item.id ) ] = item;
					}
				} );
				refresh();
				if ( addBtn ) {
					addBtn.focus();
				}
			}

			function applyCollection( data ) {
				collectionLoaded = true;
				if ( ! data || ! data.success ) {
					showCollectionMessage( ( cfg.i18n && cfg.i18n.collectionError ) || 'No pudimos revisar tus compras ahora.' );
					return;
				}
				if ( ! data.authenticated ) {
					showCollectionMessage( '¿Ya tienes algunos tomos?' );
					if ( login ) {
						login.hidden = false;
						// El destino lo resuelve el PHP del render, donde la URL de la
						// petición ES la landing. La respuesta de este endpoint corre
						// sobre admin-ajax.php y no puede saber dónde está el cliente.
						login.href = cfg.loginUrl || login.href;
					}
					return;
				}

				( data.owned_ids || [] ).forEach( function ( id ) {
					ownedIds[ String( parseInt( id, 10 ) || 0 ) ] = true;
				} );
				var ownedCount = parseInt( data.owned_count, 10 ) || 0;
				var total = parseInt( data.total, 10 ) || 0;
				var missing = parseInt( data.missing_count, 10 ) || 0;
				if ( total > 0 && missing === 0 ) {
					showCollectionMessage( '¡Felicitaciones! Ya tienes todos los tomos de esta serie 🎉' );
				} else if ( total > 0 ) {
					showCollectionMessage( '¡Ya tienes ' + ownedCount + ' de esta serie! Te ' + ( missing === 1 ? 'falta solo 1 para completarla con descuento' : 'faltan solo ' + missing + ' para completarla con descuento' ) + '.' );
				}

				var missingItems = Array.isArray( data.missing_items ) ? data.missing_items : [];
				if ( completeBtn && data.near_goal && missingItems.length > 0 ) {
					completeBtn.hidden = false;
					completeBtn.textContent = data.can_complete
						? ( missingItems.length === 1 ? '⚡ Llevar el tomo que te falta con descuento' : '⚡ Llevar los ' + missingItems.length + ' que te faltan con descuento' )
						: ( missingItems.length === 1 ? '⚡ Sumar el tomo disponible al pack' : '⚡ Sumar los ' + missingItems.length + ' disponibles al pack' );
					completeBtn.onclick = function () {
						mergeMissingItems( missingItems );
					};
				}
				syncCards();
			}

			function loadCollection() {
				if ( collectionLoaded || ! cfg.ajaxUrl || ! cfg.collectionAction ) {
					return;
				}
				collectionLoaded = true;
				showCollectionMessage( 'Revisando tu colección…' );
				var body = new URLSearchParams();
				body.append( 'action', cfg.collectionAction );
				body.append( 'term_id', termId );
				fetch( cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'collection_http_' + response.status );
						}
						return response.json();
					} )
					.then( applyCollection )
					.catch( function () {
						collectionLoaded = false;
						showCollectionMessage( ( cfg.i18n && cfg.i18n.collectionError ) || 'No pudimos revisar tus compras ahora.' );
					} );
			}

			function submitLanding() {
				var items = selectedItems();
				var ids = items.map( function ( item ) { return item.id; } );
				var i18n = cfg.i18n || {};
				if ( ids.length === 0 ) {
					setMsg( msgEl, i18n.empty || 'Elige al menos un tomo.', 'error' );
					return;
				}
				addBtn.disabled = true;
				addBtn.classList.add( 'is-loading' );
				addBtn.setAttribute( 'aria-busy', 'true' );
				setMsg( msgEl, i18n.adding || 'Agregando tus tomos…', '' );

				postSelection( cfg.ajaxUrl, cfg.addAction, cfg.addNonce, ids, termId )
					.then( function ( data ) {
						if ( ! data || ! data.success ) {
							var failed = new Error( 'add_failed' );
							failed.userMessage = data && data.message ? data.message : '';
							failed.settledIds = settledIds( data, [] );
							throw failed;
						}
						var added = parseInt( data.added, 10 ) || 0;
						var addedIds = Array.isArray( data.added_ids ) ? data.added_ids : ( added >= ids.length ? ids : [] );
						var settled = settledIds( data, addedIds );
						var remaining = reconcileSelectedIds( ids, settled ).length;
						state.items = reconcileLandingItems( state.items, settled );
						refresh();
						document.body.dispatchEvent( new CustomEvent( 'akb:commerce:add_to_cart', {
							detail: { source: 'series_landing', requested: ids.length, added: added }
						} ) );
						if ( remaining > 0 ) {
							if ( window.akbCart && typeof window.akbCart.refresh === 'function' ) {
								window.akbCart.refresh( true );
							}
							setMsg( msgEl, partialMessage( i18n.partial || 'Agregamos %1$d de %2$d tomos.', added, remaining ), 'error' );
							return;
						}
						// Nada queda por reintentar, pero algo se agotó: el motivo del
						// servidor, y el cliente se queda a leerlo.
						if ( data.message ) {
							if ( window.akbCart && typeof window.akbCart.refresh === 'function' ) {
								window.akbCart.refresh( true );
							}
							setMsg( msgEl, data.message, 'error' );
							return;
						}
						setMsg( msgEl, i18n.added || '¡Listo! Agregamos tu selección al carrito.', 'success' );
						if ( window.akbCart && typeof window.akbCart.open === 'function' ) {
							window.akbCart.open( addBtn );
						} else if ( window.jQuery ) {
							window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
							if ( cfg.cartUrl ) {
								window.setTimeout( function () { window.location.href = cfg.cartUrl; }, 320 );
							}
						}
					} )
					.catch( function ( error ) {
						var message = error && error.userMessage
							? error.userMessage
							: ( i18n.error || 'No pudimos agregar la selección.' );
						if ( error && error.settledIds && error.settledIds.length ) {
							state.items = reconcileLandingItems( state.items, error.settledIds );
							refresh();
						}
						setMsg( msgEl, message, 'error' );
					} )
					.finally( function () {
						addBtn.classList.remove( 'is-loading' );
						addBtn.removeAttribute( 'aria-busy' );
						addBtn.disabled = selectedItems().length === 0;
					} );
			}

			function syncFilterButtons() {
				var values = [];
				try {
					values = new URL( window.location.href ).searchParams.getAll( '_disponibilidad' );
				} catch ( error ) {
					values = [];
				}
				if ( values.length === 1 && values[0].indexOf( ',' ) >= 0 ) {
					values = values[0].split( ',' );
				}
				root.querySelectorAll( '.akb-sb-landing__filter' ).forEach( function ( button ) {
					var value = button.value || '';
					var active = values.length === 0 ? value === '' : values.length === 1 && value === values[0];
					button.classList.toggle( 'is-active', active );
					button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
			}

			// Mostrar entero el chip que recibe foco en el riel horizontal, incluso si ya asoma
			// parcialmente. Consultar los chips en vivo mediante delegación, igual que
			// syncFilterButtons(), y evitar que el scroll recorte su anillo de foco.
			root.addEventListener( 'focusin', function ( event ) {
				var chip = event.target && event.target.closest ? event.target.closest( '.akb-sb-landing__filter' ) : null;
				if ( chip && typeof chip.scrollIntoView === 'function' ) {
					chip.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
				}
			} );

			if ( start ) {
				start.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					setActive( ! state.active, true );
					if ( state.active && panel ) {
						panel.scrollIntoView( { block: 'nearest' } );
					}
				} );
			}
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function () {
					setActive( false, true );
					if ( start ) {
						// `preventScroll`, igual que los otros dos sitios que
						// devuelven el foco a este botón. Sin él, cerrar el modo
						// arranca la página hasta «Elegir tomos», que vive por
						// encima de la grilla: quien iba por el tomo 40 de 107
						// aparece de vuelta arriba del todo y tiene que rebuscar
						// dónde estaba. El foco tiene que volver al botón; la
						// página no tiene por qué moverse con él.
						start.focus( { preventScroll: true } );
					}
				} );
			}
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					state.items = {};
					setMsg( msgEl, 'Selección limpia.', '' );
					refresh();
				} );
			}
			if ( addBtn ) {
				addBtn.addEventListener( 'click', submitLanding );
			}

			document.addEventListener( 'change', function ( event ) {
				var check = event.target && event.target.closest ? event.target.closest( '[data-akb-sb-landing-vol]' ) : null;
				if ( ! check || check.disabled || check.getAttribute( 'data-term-id' ) !== String( termId ) ) {
					return;
				}
				var item = itemFromCheck( check );
				if ( ! item ) {
					check.checked = false;
					return;
				}
				var key = String( item.id );
				if ( check.checked ) {
					if ( ! state.items[ key ] && Object.keys( state.items ).length >= maxSelection ) {
						check.checked = false;
						setMsg( msgEl, 'Puedes seleccionar hasta ' + maxSelection + ' tomos por vez.', 'error' );
						return;
					}
					state.items[ key ] = item;
				} else {
					delete state.items[ key ];
				}
				setMsg( msgEl, '', '' );
				refresh();
			} );

			document.addEventListener( 'click', function ( event ) {
				var link = event.target && event.target.closest
					? event.target.closest( 'a[data-akb-sb-card-toggle="' + termId + '"]' )
					: null;
				if ( ! link || ! state.active ) {
					return;
				}
				event.preventDefault();
				toggleProductLinkSelection( link );
			} );

			document.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' && state.active ) {
					setActive( false, true );
					if ( start ) {
						// Mismo motivo que en el botón de cerrar: Escape devuelve
						// el foco, no la posición de lectura.
						start.focus( { preventScroll: true } );
					}
				}
			} );

			document.addEventListener( 'akb:pjax', function () {
				syncFilterButtons();
				if ( state.active && window.location.hash !== '#completar-coleccion' ) {
					window.history.replaceState( window.history.state, '', window.location.pathname + window.location.search + '#completar-coleccion' );
				}
				refresh();
				if ( restoreStartFocusAfterPjax && ! state.active && start ) {
					restoreStartFocusAfterPjax = false;
					window.requestAnimationFrame( function () {
						start.focus( { preventScroll: true } );
					} );
				}
			} );
			function syncSelectionModeFromLocation() {
				var hashActive = window.location.hash === '#completar-coleccion';
				if ( hashActive !== state.active ) {
					var focusedSelectionControl = ! hashActive && document.activeElement && (
						( panel && panel.contains( document.activeElement ) )
						|| !! document.activeElement.closest( '[data-akb-sb-card-selector]' )
					);
					setActive( hashActive, false );
					if ( focusedSelectionControl && start ) {
						restoreStartFocusAfterPjax = true;
						start.focus( { preventScroll: true } );
					}
				}
			}
			// Sincronizar en popstate antes del swap PJAX de Search. WebKit puede entregar hashchange
			// después; cerrar el modo aquí impide que akb:pjax restaure un hash obsoleto.
			window.addEventListener( 'popstate', syncSelectionModeFromLocation );
			window.addEventListener( 'hashchange', syncSelectionModeFromLocation );

			syncFilterButtons();
			if ( state.active || window.location.hash === '#completar-coleccion' ) {
				setActive( true, false );
			} else {
				refresh();
			}
		} );
	}

	/* ----------------------------------------------------------------------
	 * Afordancia de scroll de la grilla de tomos: el grid tiene max-height fijo
	 * con overflow, así que la última fila queda cortada a la mitad. Un fade
	 * inferior (overlay ::after en el wrapper, togglado por clase) convierte ese
	 * corte duro en un "hay más, scrolleá" y desaparece al llegar al final.
	 * Mejora progresiva: sin JS no hay fade (el grid igual scrollea nativo).
	 * -------------------------------------------------------------------- */
	function initScrollAffordance( root ) {
		var grid = root.querySelector( '.akb-sb-builder__grid' );
		if ( ! grid ) {
			return;
		}
		var wrap = grid.closest( '.akb-sb-builder__grid-wrap' ) || grid.parentNode;
		var raf = null;

		function update() {
			raf = null;
			var scrollable = grid.scrollHeight - grid.clientHeight > 2;
			var atEnd = grid.scrollTop + grid.clientHeight >= grid.scrollHeight - 2;
			// Estado en el WRAPPER (padre, fuera del subtree observado) → el
			// MutationObserver de abajo no se auto-dispara por este toggle.
			wrap.classList.toggle( 'is-scrollable', scrollable );
			wrap.classList.toggle( 'is-scroll-end', atEnd );
		}

		function schedule() {
			if ( ! raf ) {
				raf = window.requestAnimationFrame( update );
			}
		}

		grid.addEventListener( 'scroll', schedule, { passive: true } );
		window.addEventListener( 'resize', schedule );
		// Reflow tardío de portadas/fuentes cambia scrollHeight sin scroll/resize.
		window.addEventListener( 'load', schedule );

		if ( window.ResizeObserver ) {
			new window.ResizeObserver( schedule ).observe( grid );
		}
		// Los filtros de rango/búsqueda togglean `hidden`/`is-filtered-out` en las
		// cards (cambian scrollHeight sin scroll ni resize). Observamos el grid y
		// su subtree; el estado vive en el wrapper (no observado) → sin loop.
		if ( window.MutationObserver ) {
			new window.MutationObserver( schedule ).observe( grid, {
				childList: true,
				subtree: true,
				attributeFilter: [ 'class', 'hidden' ]
			} );
		}

		update();
	}

	function postSelection( ajaxUrl, action, nonce, ids, seriesId ) {
		var body = new URLSearchParams();
		body.append( 'action', action || '' );
		body.append( 'nonce', nonce || '' );
		if ( seriesId ) {
			body.append( 'series_id', seriesId );
		}
		ids.forEach( function ( id ) {
			body.append( 'product_ids[]', id );
		} );

		return fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'add_http_' + response.status );
				}
				return response.json();
			} );
	}

	function submit( cfg, ids, addBtn, msgEl, i18n, checks, refreshBuilder, termId ) {
		if ( ! cfg.ajaxUrl ) {
			return;
		}
		var original = addBtn.textContent;
		addBtn.disabled = true;
		addBtn.setAttribute( 'aria-busy', 'true' );
		addBtn.classList.add( 'is-loading' );
		setMsg( msgEl, i18n.adding || 'Sumando tu pack…', '' );

		// Deja marcados sólo `remainingIds` entre los que se enviaron.
		function keepChecked( remainingIds ) {
			var remaining = {};
			remainingIds.forEach( function ( id ) { remaining[ String( id ) ] = true; } );
			if ( checks ) {
				checks.forEach( function ( check ) {
					check.checked = !! remaining[ String( parseInt( check.value, 10 ) || 0 ) ];
				} );
			}
			if ( typeof refreshBuilder === 'function' ) {
				refreshBuilder();
			}
		}

		postSelection( cfg.ajaxUrl, cfg.action, cfg.nonce, ids, termId )
			.then( function ( data ) {
				if ( ! data || ! data.success ) {
					var failed = new Error( 'add_failed' );
					failed.userMessage = data && data.message ? data.message : '';
					failed.settledIds = settledIds( data, [] );
					throw failed;
				}
				var parsedAdded = parseInt( data.added, 10 );
				var added = Number.isNaN( parsedAdded ) ? ids.length : Math.max( 0, parsedAdded );
				var hasExactAddedIds = Array.isArray( data.added_ids );
				var addedIds = hasExactAddedIds ? data.added_ids : ( added >= ids.length ? ids : [] );
				var remainingIds = reconcileSelectedIds( ids, settledIds( data, addedIds ) );
				var isPartial = remainingIds.length > 0;
				if ( hasExactAddedIds ) {
					added = ids.length - reconcileSelectedIds( ids, addedIds ).length;
				}
				keepChecked( remainingIds );
				// Sin nada que reintentar, `message` sólo llega si algo se agotó: el
				// cliente se queda a leerlo, como en un parcial.
				var notice = isPartial ? '' : ( data.message || '' );
				var successMessage = isPartial
					? partialMessage( i18n.partial || 'Agregamos %1$d de %2$d tomos. Dejamos marcados los que faltan.', added, remainingIds.length )
					: ( notice || i18n.added || '¡Listo, pack en el carrito!' );
				setMsg( msgEl, successMessage, isPartial || notice ? 'error' : 'success' );
				addBtn.classList.add( 'akb-animate-stamp' );
				document.body.dispatchEvent( new CustomEvent( 'akb:commerce:add_to_cart', {
					detail: { source: 'series_bundle', requested: ids.length, added: added }
				} ) );
				// En un alta parcial o con agotados, refrescar el carrito sin abrirlo ni redirigir. Los éxitos
				// ya se desmarcaron; conservar la ficha para leer el motivo y reintentar los fallos
				// seleccionados.
				if ( isPartial || notice ) {
					if ( window.akbCart && typeof window.akbCart.refresh === 'function' ) {
						window.akbCart.refresh( true );
					}
					return;
				}

				if ( window.akbCart && typeof window.akbCart.open === 'function' ) {
					window.akbCart.open( addBtn );
					return;
				}
				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
				}
				if ( cfg.cartUrl ) {
					setTimeout( function () { window.location.href = cfg.cartUrl; }, 320 );
				}
			} )
			.catch( function ( error ) {
				var message = error && error.userMessage
					? error.userMessage
					: ( i18n.error || 'Error' );
				if ( error && error.settledIds && error.settledIds.length ) {
					keepChecked( reconcileSelectedIds( ids, error.settledIds ) );
				}
				setMsg( msgEl, message, 'error' );
			} )
			.finally( function () {
				addBtn.disabled = checks
					? ! Array.prototype.some.call( checks, function ( check ) { return check.checked; } )
					: false;
				addBtn.classList.remove( 'is-loading' );
				addBtn.removeAttribute( 'aria-busy' );
				addBtn.textContent = original;
			} );
	}

	function parseTiers( raw ) {
		if ( ! raw ) {
			return [];
		}
		try {
			var arr = JSON.parse( raw );
			return Array.isArray( arr ) ? arr.map( function ( t ) {
				return { min: parseInt( t.min, 10 ) || 0, pct: parseInt( t.pct, 10 ) || 0 };
			} ) : [];
		} catch ( e ) {
			return [];
		}
	}

	function setMsg( el, text, type ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		var base = el.getAttribute( 'data-akb-sb-msg-class' ) || 'akb-sb-builder__msg';
		el.className = base + ( type ? ' is-' + type : '' );
	}

	function format( tpl, a, b ) {
		// Colapsa `%%` -> `%` (semántica sprintf), porque los formatos vienen de
		// PHP con escape sprintf (p.ej. '%2$d%% de descuento'). Sin esto el % se
		// duplica en pantalla ("10%%").
		return tpl.replace( '%1$d', a ).replace( '%2$d', b ).replace( /%%/g, '%' );
	}

	function formatMoney( amount ) {
		// Formato simple CLP: $1.234 sin decimales.
		var rounded = Math.round( amount );
		return '$' + rounded.toString().replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	// API pura para pruebas Node. En navegador no publica globals nuevos.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = {
			parseTiers: parseTiers,
			pricedTotals: pricedTotals,
			resolvePct: resolvePct,
			normalizeLandingItem: normalizeLandingItem,
			normalizeLandingState: normalizeLandingState,
			reconcileLandingItems: reconcileLandingItems,
			reconcileSelectedIds: reconcileSelectedIds,
			settledIds: settledIds,
			partialMessage: partialMessage,
			reconcileVisibleLandingItems: reconcileVisibleLandingItems,
			formatMoney: formatMoney
		};
	}

	if ( typeof document === 'undefined' || typeof window === 'undefined' ) {
		return;
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		onReady();
	}
}() );
