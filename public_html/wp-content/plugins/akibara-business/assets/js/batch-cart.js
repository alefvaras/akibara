/** Shared batch purchase transport for series and collection. */
( function () {
	'use strict';
	let queue = Promise.resolve();

	async function send( endpoint, nonce, ids ) {
		const body = new URLSearchParams();
		body.set( 'action', 'akb_batch_add_to_cart' );
		body.set( 'nonce', nonce );
		ids.forEach( ( id ) => body.append( 'product_ids[]', String( id ) ) );
		const response = await window.fetch( endpoint, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} );
		let payload;
		try {
			payload = await response.json();
		} catch ( _error ) {
			throw new Error( 'No pudimos confirmar la compra. Revisa tu carrito antes de volver a añadir.' );
		}
		const data = payload && payload.data;
		if ( ! response.ok || ! payload.success || ! data ) {
			throw new Error( data && data.message || 'No se pudieron añadir los tomos. Tu selección sigue disponible.' );
		}
		if ( ! Array.isArray( data.added_ids ) || ! Array.isArray( data.already_ids ) || ! Array.isArray( data.failed_ids ) ) {
			throw new Error( 'No pudimos confirmar los tomos añadidos. Revisa tu carrito antes de reintentar.' );
		}
		const acknowledged = new Set( [ ...data.added_ids, ...data.already_ids, ...data.failed_ids ] );
		if ( ids.some( ( id ) => ! acknowledged.has( id ) ) ) {
			throw new Error( 'La respuesta del carrito está incompleta. Revisa tu carrito antes de reintentar.' );
		}
		if ( window.akbCart && typeof window.akbCart.open === 'function' ) {
			window.akbCart.refresh( true );
			window.akbCart.open();
		} else {
			document.body.dispatchEvent( new CustomEvent( 'akb:cart:updated', { bubbles: true, detail: data } ) );
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'added_to_cart', [ data.fragments || {}, data.cart_hash || '' ] );
			}
		}
		return data;
	}

	window.akbBatchCart = {
		add( { endpoint, nonce, productIds } ) {
			const ids = [ ...new Set( productIds.map( Number ) ) ];
			if ( ! ids.length || ids.some( ( id ) => ! Number.isSafeInteger( id ) || id < 1 ) ) {
				return Promise.reject( new Error( 'Selecciona tomos válidos para comprar.' ) );
			}
			if ( ids.length > 150 ) {
				return Promise.reject( new Error( 'Selecciona hasta 150 tomos por compra en lote. Tu selección sigue disponible.' ) );
			}
			const request = queue.then( () => send( endpoint, nonce, ids ) );
			queue = request.catch( () => {} );
			return request;
		}
	};
} )();
