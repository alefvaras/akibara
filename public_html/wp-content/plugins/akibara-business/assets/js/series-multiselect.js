/** Complete-edition selection with a shared cart transport and commercial preview. */
( function () {
	'use strict';

	const config = window.akbSeriesBatchConfig || {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: '',
		i18n: {
			selected_singular: '1 tomo seleccionado',
			selected_plural: '%s tomos seleccionados',
			add_selection: 'Añadir selección (%s)',
			adding: 'Añadiendo tomos...'
		}
	};

	function init() {
		const actionBar = document.getElementById( 'akb-series-action-bar' );
		if ( ! actionBar ) {
			return;
		}
		const checkboxes = Array.prototype.slice.call( document.querySelectorAll( '[data-akb-volume-checkbox]' ) );
		const toolbar = document.querySelector( '[data-akb-series-select-toolbar]' );
		const selectAllBtn = toolbar ? toolbar.querySelector( '[data-akb-select-all]' ) : null;
		const clearBtn = toolbar ? toolbar.querySelector( '[data-akb-clear-selection]' ) : null;
		const toolbarStatus = toolbar ? toolbar.querySelector( '[data-akb-toolbar-status]' ) : null;
		const rangeSelector = toolbar ? toolbar.querySelector( '[data-akb-range-selector]' ) : null;
		const rangeFromInput = rangeSelector ? rangeSelector.querySelector( '[data-akb-range-from]' ) : null;
		const rangeToInput = rangeSelector ? rangeSelector.querySelector( '[data-akb-range-to]' ) : null;
		const rangeApplyBtn = rangeSelector ? rangeSelector.querySelector( '[data-akb-range-apply]' ) : null;

		const preview = document.querySelector( '[data-akb-series-purchase-preview]' );
		let cartState = config.cart_state || null;
		const countEl = actionBar.querySelector( '[data-akb-action-bar-count]' );
		const totalEl = actionBar.querySelector( '[data-akb-action-bar-total]' );
		const submitBtn = actionBar.querySelector( '[data-akb-batch-submit]' );
		const submitLabel = actionBar.querySelector( '[data-akb-submit-label]' );

		let isSubmitting = false;
		const completeEdition = Array.isArray( config.editionProducts );
		const items = new Map( ( completeEdition ? config.editionProducts : [] ).map( ( product ) => [ product.id, product ] ) );
		checkboxes.forEach( ( cb ) => {
			const id = Number( cb.dataset.productId );
			if ( ! items.has( id ) ) {
				items.set( id, { id, volume: Number( cb.dataset.volumeNumber ) || null, price: cb.dataset.price, purchasable: ! cb.disabled, stock_status: 'instock', type: 'simple', variant: false, owned: cb.dataset.owned === '1' } );
			}
		} );
		const available = ( product ) => product.purchasable && product.stock_status === 'instock' && product.type === 'simple';
		const unownedAvailable = ( product ) => available( product ) && ! product.owned;
		const bulkItems = window.akbEditionProducts.group( [ ...items.values() ].filter( unownedAvailable ) );
		let selectedIds = new Set( checkboxes.filter( ( cb ) => cb.checked && ! cb.disabled ).map( ( cb ) => Number( cb.dataset.productId ) ) );
		const catalogNotice = completeEdition ? '' : 'La selección completa se está actualizando. Puedes elegir los tomos visibles; recarga en unos instantes para seleccionar un tramo.';
		if ( ! completeEdition ) {
			if ( selectAllBtn ) {
				selectAllBtn.disabled = true;
			}
			if ( rangeApplyBtn ) {
				rangeApplyBtn.disabled = true;
			}
		}

		function formatMoney( amount ) {
			try {
				return '$' + new Intl.NumberFormat( 'es-CL' ).format( Math.round( amount ) );
			} catch ( _e ) {
				return '$' + String( Math.round( amount ) );
			}
		}

		function getSelectedProducts() {
			return [ ...selectedIds ].map( ( id ) => items.get( id ) ).filter( ( product ) => product && available( product ) );
		}

		function updateState() {
			const selected = getSelectedProducts();
			const count = selected.length;

			// Sincronizar estado visual de tarjeta seleccionada
			checkboxes.forEach( function ( cb ) {
				cb.checked = selectedIds.has( Number( cb.dataset.productId ) );
				const card = cb.closest( 'li.product' );
				if ( card ) {
					if ( cb.checked && ! cb.disabled ) {
						card.classList.add( 'akb-volume-selected' );
					} else {
						card.classList.remove( 'akb-volume-selected' );
					}
				}
			} );

			if ( count === 0 ) {
				actionBar.setAttribute( 'hidden', '' );
				if ( preview ) {
					preview.hidden = true;
				}
				if ( clearBtn ) {
					clearBtn.setAttribute( 'hidden', '' );
				}
				if ( toolbarStatus ) {
					toolbarStatus.textContent = catalogNotice;
					toolbarStatus.hidden = ! catalogNotice;
				}
				if ( selectAllBtn ) {
					selectAllBtn.setAttribute( 'aria-pressed', 'false' );
				}
				if ( document.body ) {
					document.body.classList.remove( 'akb-has-series-action-bar' );
				}
				return;
			}

			let total = 0;
			selected.forEach( function ( product ) {
				const price = parseFloat( product.price || '0' );
				total += isNaN( price ) ? 0 : price;
			} );


			// Resumen
			if ( countEl ) {
				const countStr = count === 1
					? config.i18n.selected_singular
					: config.i18n.selected_plural.replace( '%s', String( count ) );
				countEl.textContent = countStr;
			}
			if ( totalEl ) {
				totalEl.textContent = formatMoney( total );
			}
			if ( submitLabel ) {
				submitLabel.textContent = config.i18n.add_selection.replace( '%s', formatMoney( total ) );
			}

			if ( preview ) {
				window.akbPurchaseIncentives.renderPreview( preview, window.akbPurchaseIncentives.estimate( { products: selected, config: config.purchase_incentives, cartState } ) );
			}

			// Toolbar superior
			if ( clearBtn ) {
				clearBtn.removeAttribute( 'hidden' );
			}
			if ( toolbarStatus ) {
				const offPage = selected.filter( ( product ) => ! checkboxes.some( ( cb ) => Number( cb.dataset.productId ) === product.id ) ).length;
				toolbarStatus.textContent = count + ' seleccionados' + ( offPage ? ' · ' + offPage + ' en otras páginas' : '' ) + ( count > 150 ? '. Elige hasta 150 tomos por compra.' : '' );
				toolbarStatus.removeAttribute( 'hidden' );
			}
			if ( selectAllBtn ) {
				const allChecked = bulkItems.length > 0 && bulkItems.every( ( product ) => selectedIds.has( product.id ) );
				selectAllBtn.setAttribute( 'aria-pressed', allChecked ? 'true' : 'false' );
			}

			if ( submitBtn ) {
				submitBtn.disabled = isSubmitting || count > 150;
			}
			actionBar.removeAttribute( 'hidden' );
			if ( document.body ) {
				document.body.classList.add( 'akb-has-series-action-bar' );
			}
		}

		checkboxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				const id = Number( cb.dataset.productId );
				if ( cb.checked && ! cb.disabled ) {
					selectedIds = new Set( window.akbEditionProducts.group( [ ...getSelectedProducts(), items.get( id ) ], ( product ) => product.id === id ? 0 : 1 ).map( ( product ) => product.id ) );
				} else {
					selectedIds.delete( id );
				}
				updateState();
			} );
		} );

		if ( selectAllBtn ) {
			selectAllBtn.addEventListener( 'click', function () {
				if ( ! completeEdition ) {
					return;
				}
				const allChecked = bulkItems.every( ( product ) => selectedIds.has( product.id ) );
				selectedIds = new Set( allChecked ? [] : bulkItems.map( ( product ) => product.id ) );
				updateState();
			} );
		}

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				selectedIds.clear();
				updateState();
			} );
		}

		if ( rangeApplyBtn && rangeFromInput && rangeToInput ) {
			const applyRange = function () {
				if ( ! completeEdition ) {
					return;
				}
				const fromVal = rangeFromInput.value === '' ? 1 : Number( rangeFromInput.value );
				const toVal = rangeToInput.value === '' ? Math.max( 1, ...[ ...items.values() ].filter( available ).map( ( product ) => product.volume || 0 ) ) : Number( rangeToInput.value );
				if ( ! Number.isSafeInteger( fromVal ) || ! Number.isSafeInteger( toVal ) || fromVal < 1 || toVal < 1 ) {
					if ( toolbarStatus ) {
						toolbarStatus.textContent = 'Usa números enteros mayores que cero para el tramo.';
						toolbarStatus.hidden = false;
					}
					return;
				}

				const min = Math.min( fromVal, toVal );
				const max = Math.max( fromVal, toVal );

				selectedIds = new Set( bulkItems.filter( ( product ) => Number.isInteger( product.volume ) && product.volume >= min && product.volume <= max ).map( ( product ) => product.id ) );

				updateState();
			};

			rangeApplyBtn.addEventListener( 'click', applyRange );

			const handleRangeEnter = function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					if ( e.isComposing || e.keyCode === 229 ) {
						return;
					}
					applyRange();
				}
			};

			rangeFromInput.addEventListener( 'keydown', handleRangeEnter );
			rangeToInput.addEventListener( 'keydown', handleRangeEnter );
		}

		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				if ( isSubmitting ) {
					return;
				}

				const selected = getSelectedProducts();
				if ( ! selected.length ) {
					return;
				}

				const productIds = selected.map( ( product ) => product.id );

				if ( ! productIds.length ) {
					return;
				}

				isSubmitting = true;
				submitBtn.disabled = true;
				if ( submitLabel ) {
					submitLabel.textContent = config.i18n.adding;
				}

				window.akbBatchCart.add( { endpoint: config.ajaxUrl, nonce: config.nonce, productIds } )
				.then( function ( result ) {
					isSubmitting = false;
					submitBtn.disabled = false;
					const failed = new Set( result.failed_ids );
					productIds.forEach( ( id ) => {
						if ( ! failed.has( id ) ) {
							selectedIds.delete( id );
						}
					} );
					updateState();
					const pendingFailures = [ ...failed ].filter( ( id ) => selectedIds.has( id ) ).length;
					if ( pendingFailures && toolbarStatus ) {
						toolbarStatus.textContent = pendingFailures + ( pendingFailures === 1 ? ' tomo sigue seleccionado porque no pudo agregarse.' : ' tomos siguen seleccionados porque no pudieron agregarse.' );
					}
				} )
				.catch( function ( error ) {
					isSubmitting = false;
					submitBtn.disabled = false;
					updateState();
					if ( toolbarStatus ) {
						toolbarStatus.textContent = error.message || 'Error de conexión al agregar tomos. Por favor intenta de nuevo.';
						toolbarStatus.hidden = false;
					}
				} );
			} );
		}
		document.addEventListener( 'akb:cart:state', ( event ) => {
			if ( event.detail && event.detail.purchase_incentives ) {
				cartState = event.detail.purchase_incentives;
				updateState();
			}
		} );
		updateState();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
