/** One representative per numbered volume within an edition. */
( function () {
	'use strict';

	function group( products, priority = ( product ) => product.variant ? 1 : 0 ) {
		const slots = new Map();
		products.forEach( ( product ) => {
			const number = Number.isInteger( product.volume ) && product.volume > 0 ? 'volume-' + product.volume : 'product-' + product.id;
			const key = String( product.series_id || '' ) + ':' + number;
			const current = slots.get( key );
			if ( ! current || priority( product ) < priority( current ) ) {
				slots.set( key, product );
			}
		} );
		return [ ...slots.values() ];
	}

	window.akbEditionProducts = Object.freeze( { group } );
} )();
