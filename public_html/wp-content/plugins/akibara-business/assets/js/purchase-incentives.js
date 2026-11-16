/** Shared selection estimates; checkout and the native cart confirm the final benefits. */
( function () {
	'use strict';
	const money = new Intl.NumberFormat( 'es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 } );
	const numbers = new Intl.NumberFormat( 'es-CL', { maximumFractionDigits: 0 } );
	const nonnegative = ( value ) => Number.isFinite( Number( value ) ) ? Math.max( 0, Number( value ) ) : 0;

	/**
	 * Project a selection without querying or changing a cart. The two point roundings
	 * mirror Calculator::points_for_base() then Tiers::apply_multiplier(); an executable
	 * PHP oracle verifies this display-only projection against those authorities.
	 * @param {Object} input Selection products, configuration and optional fresh cart state.
	 * @return {Object} Plain text and progress values, never an eligibility decision.
	 */
	function estimate( input ) {
		const config = input.config || {};
		const cart = input.cartState || null;
		const existing = new Set( cart && Array.isArray( cart.product_ids ) ? cart.product_ids.map( Number ) : [] );
		const seen = new Set();
		let total = 0;
		let count = 0;
		( input.products || [] ).forEach( function ( product ) {
			const id = Number( product.id );
			if ( ! Number.isSafeInteger( id ) || id < 1 || seen.has( id ) || existing.has( id ) ) {
				return;
			}
			seen.add( id );
			count++;
			total += nonnegative( product.price );
		} );
		const model = { visible: count > 0, points: 0, points_text: '', cart_points_text: '', shipping_text: '', percent: null, remaining: null, note: '', program_url: '', total };
		if ( ! model.visible ) {
			return model;
		}
		const rewards = config.rewards || {};
		const rate = nonnegative( rewards.points );
		const amount = nonnegative( rewards.amount );
		if ( rate > 0 && amount > 0 ) {
			const basePoints = Math.round( total / amount * rate );
			model.points = Math.round( basePoints * Math.max( 1, nonnegative( rewards.multiplier ) ) );
			if ( model.points > 0 ) {
				model.points_text = '≈ ' + numbers.format( model.points ) + ' Puntos Akibara por esta selección';
				model.note = 'Estimación antes de descuentos y canjes; el carrito confirma los puntos de la compra.';
				model.program_url = rewards.program_url || '';
			}
			if ( cart && cart.rewards && nonnegative( cart.rewards.points ) > 0 ) {
				model.cart_points_text = 'Tu carrito actual suma ' + numbers.format( cart.rewards.points ) + ' Puntos Akibara.';
			}
		}
		const shipping = config.shipping || {};
		const resolved = cart ? cart.shipping : null;
		// An empty cart has no shipping package yet. It can still show the public,
		// explicitly conditional reference for this selection; it has no cart progress.
		const emptyUnresolved = resolved && resolved.reason === 'unavailable' && Array.isArray( cart.product_ids ) && cart.product_ids.length === 0;
		const current = emptyUnresolved ? null : resolved;
		if ( shipping.enabled && ( ! current || ( current.reason === 'order_amount' && [ 'progress', 'qualified' ].includes( current.state ) ) ) ) {
			const threshold = nonnegative( current ? current.threshold : shipping.threshold );
			if ( threshold > 0 ) {
				const reference = total + ( current ? nonnegative( current.total ) : 0 );
				model.remaining = Math.max( 0, threshold - reference );
				model.percent = Math.min( 100, reference / threshold * 100 );
				const scope = current ? 'Carrito + selección: ' : 'Esta selección: ';
				model.shipping_text = scope + ( model.remaining > 0
					? 'faltan ≈ ' + money.format( model.remaining ) + ' para la referencia de envío gratis.'
					: 'referencia cubierta; confirma el envío en el carrito.' );
				model.note += ( model.note ? ' ' : '' ) + 'El envío se confirma según tu dirección y los métodos disponibles.';
			}
		}
		return model;
	}

	/**
	 * Paint only text and native progress. The consumer controls the surrounding layout.
	 * @param {HTMLElement} container Dedicated preview container.
	 * @param {Object}      model     Result from estimate().
	 */
	function renderPreview( container, model ) {
		container.replaceChildren();
		container.classList.add( 'akb-purchase-incentives' );
		container.hidden = ! model.visible || ( ! model.points_text && ! model.shipping_text );
		if ( container.hidden ) {
			return;
		}
		const appendText = function ( target, modifier, content ) {
			if ( content ) {
				const line = document.createElement( 'p' );
				line.className = 'akb-purchase-incentives__' + modifier;
				line.textContent = content;
				target.appendChild( line );
			}
		};
		appendText( container, 'points', model.points_text );
		appendText( container, 'shipping', model.shipping_text );
		if ( model.percent !== null ) {
			const progress = document.createElement( 'progress' );
			progress.className = 'akb-purchase-incentives__progress';
			progress.max = 100;
			progress.value = model.percent;
			progress.setAttribute( 'aria-label', 'Monto de referencia de envío gratis a domicilio' );
			container.appendChild( progress );
		}
		const details = document.createElement( 'details' );
		details.className = 'akb-purchase-incentives__details';
		const summary = document.createElement( 'summary' );
		summary.textContent = 'Ver condiciones';
		details.appendChild( summary );
		appendText( details, 'cart', model.cart_points_text );
		appendText( details, 'note', model.note );
		let programUrl = null;
		try {
			if ( model.program_url ) {
				programUrl = new URL( model.program_url, window.location.href );
			}
		} catch ( _error ) {
			programUrl = null;
		}
		if ( programUrl && [ 'http:', 'https:' ].includes( programUrl.protocol ) ) {
			const link = document.createElement( 'a' );
			link.className = 'akb-purchase-incentives__link';
			link.href = programUrl.href;
			link.textContent = 'Cómo funcionan los Puntos Akibara';
			details.appendChild( link );
		}
		container.appendChild( details );
	}

	window.akbPurchaseIncentives = Object.freeze( { estimate, renderPreview } );
}() );
