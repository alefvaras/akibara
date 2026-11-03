'use strict';

// El campo de búsqueda de selectWoo recibe nombre accesible (admin-panel.js).
const assert = require( 'node:assert/strict' );

global.document = { addEventListener() {}, querySelectorAll() { return []; }, getElementById() { return null; }, querySelector() { return null; } };
const { labelSelect2Search } = require( '../../../assets/js/admin-panel.js' );

function input( attrs, container ) {
	const el = { attrs: { ...attrs }, getAttribute( k ) { return this.attrs[ k ] ?? null; }, setAttribute( k, v ) { this.attrs[ k ] = v; } };
	el.closest = ( sel ) => ( '.select2-container' === sel ? container : null );
	return el;
}
function root( inputs, labels ) {
	return {
		querySelectorAll( sel ) { return '.select2-search__field:not([aria-label])' === sel ? inputs.filter( ( i ) => ! i.attrs[ 'aria-label' ] ) : []; },
		querySelector( sel ) { return labels[ sel ] ?? null; },
	};
}

// Con placeholder: el placeholder es el nombre.
const conPlaceholder = input( { placeholder: 'Seleccionar categorías…' }, null );
assert.equal( labelSelect2Search( root( [ conPlaceholder ], {} ) ), 1 );
assert.equal( conPlaceholder.attrs[ 'aria-label' ], 'Seleccionar categorías…' );

// Sin placeholder: el label del <select> al que pertenece el contenedor.
const select = { tagName: 'SELECT', id: 'akb_series_bundles_exclude_cats' };
const contenedor = { previousElementSibling: select };
const sinPlaceholder = input( {}, contenedor );
const r = root( [ sinPlaceholder ], { 'label[for="akb_series_bundles_exclude_cats"]': { textContent: '  Categorías excluidas ' } } );
assert.equal( labelSelect2Search( r ), 1 );
assert.equal( sinPlaceholder.attrs[ 'aria-label' ], 'Categorías excluidas' );

// Sin ninguna fuente de nombre no se inventa nada, y el ya etiquetado no se toca.
const huerfano = input( {}, null );
const etiquetado = input( { 'aria-label': 'Ya tiene', placeholder: 'otro' }, null );
assert.equal( labelSelect2Search( root( [ huerfano, etiquetado ], {} ) ), 0 );
assert.equal( huerfano.attrs[ 'aria-label' ], undefined );
assert.equal( etiquetado.attrs[ 'aria-label' ], 'Ya tiene' );

console.log( 'select2-label: ok' );
