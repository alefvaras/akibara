'use strict';

/**
 * DOM mínimo para correr los bundles del buscador dentro de `node:vm`.
 *
 * Árbol, selectores simples (tag, #id, .clase, [attr], [attr="v"], :checked,
 * descendiente y listas con coma), las mutaciones que usa el swap del PJAX, eventos con fase
 * de captura y burbuja, el foco y `<template>` (su contenido, fuera del árbol). Lo comparten las pruebas del PJAX; no es una
 * prueba (no acaba en `.test.js`), así que ni `node --test` ni el gate
 * `qa-search.sh` lo corren solo.
 *
 * La geometría la dicta cada prueba (ver `mundo`): las cajas de los nodos, qué
 * hay en cada punto de la ventana (`elementFromPoint`), qué nodos recortan lo
 * que se les sale (`overflow`) y si el navegador trae `popover`. Sin decir
 * nada, todo mide cero, nada recorta y nada está a la vista.
 *
 * Lo que no modela, falla a la vista: un selector que no entiende lanza, para
 * que un bundle que empiece a usar otro no pase por una lista vacía.
 */

function parseCompound( text ) {
	const re = /^([a-z][a-z0-9-]*)?((?:#[\w-]+|\.[\w-]+|\[[^\]]+\]|:checked)*)$/i;
	const m = re.exec( text );
	if ( ! m ) {
		throw new Error( 'selector no soportado por el DOM de prueba: ' + text );
	}
	const parts = { tag: m[ 1 ] ? m[ 1 ].toUpperCase() : null, ids: [], classes: [], attrs: [], checked: false };
	( m[ 2 ].match( /#[\w-]+|\.[\w-]+|\[[^\]]+\]|:checked/g ) || [] ).forEach( ( p ) => {
		if ( ':checked' === p ) {
			// «Limpiar todo» desmarca con `input:checked`.
			parts.checked = true;
		} else if ( '#' === p[ 0 ] ) {
			parts.ids.push( p.slice( 1 ) );
		} else if ( '.' === p[ 0 ] ) {
			parts.classes.push( p.slice( 1 ) );
		} else {
			const a = /^\[([\w-]+)(?:="([^"]*)")?\]$/.exec( p );
			if ( ! a ) {
				throw new Error( 'atributo no soportado por el DOM de prueba: ' + p );
			}
			parts.attrs.push( { name: a[ 1 ], value: undefined === a[ 2 ] ? null : a[ 2 ] } );
		}
	} );
	return parts;
}

function matchesCompound( el, c ) {
	if ( c.tag && el.tagName !== c.tag ) {
		return false;
	}
	if ( c.ids.some( ( id ) => el.getAttribute( 'id' ) !== id ) ) {
		return false;
	}
	if ( c.checked && ! el.checked ) {
		return false;
	}
	const classes = ( el.getAttribute( 'class' ) || '' ).split( /\s+/ );
	if ( c.classes.some( ( k ) => ! classes.includes( k ) ) ) {
		return false;
	}
	return c.attrs.every( ( a ) => el.hasAttribute( a.name ) && ( null === a.value || el.getAttribute( a.name ) === a.value ) );
}

function matchesSelector( el, selector ) {
	return selector.split( ',' ).some( ( one ) => {
		const chain = one.trim().split( /\s+/ ).map( parseCompound );
		if ( ! matchesCompound( el, chain[ chain.length - 1 ] ) ) {
			return false;
		}
		let i = chain.length - 2;
		let anc = el.parentNode;
		while ( i >= 0 && anc && anc.tagName ) {
			if ( matchesCompound( anc, chain[ i ] ) ) {
				i--;
			}
			anc = anc.parentNode;
		}
		return i < 0;
	} );
}

/**
 * Estado compartido del «navegador»: quién tiene el foco y qué se desplazó.
 * `reiniciar()` al arrancar cada prueba.
 */
const mundo = {
	activo: null,
	desplazamientos: [],
	/** Tamaño de la ventana (`clientWidth`/`clientHeight` de <html>). */
	ventana: { ancho: 1440, alto: 900 },
	/** Caja de un nodo, `{ top, left, width, height }`, o null (todo a cero). */
	caja: () => null,
	/** Lo que hay en el punto (x, y) de la ventana: `document.elementFromPoint`. */
	alPunto: () => null,
	/** Popovers abiertos, en el orden en que se abrieron (la capa superior). */
	capaSuperior: [],
	/** Un navegador sin `popover` (Safari anterior a 17). */
	sinPopover: false,
	/** `--akb-franja-inferior` del elemento raíz, como la escribe el tema. */
	franja: '',
	/** `overflow` de un nodo (el mismo en los dos ejes): `auto` en una lista que se desplaza por dentro. */
	desborde: () => 'visible',
	/** MutationObserver puestos, con el nodo y los atributos que miran. */
	observadores: [],
	reiniciar() {
		this.activo = null;
		this.desplazamientos = [];
		this.ventana = { ancho: 1440, alto: 900 };
		this.caja = () => null;
		this.alPunto = () => null;
		this.capaSuperior = [];
		this.sinPopover = false;
		this.franja = '';
		this.desborde = () => 'visible';
		this.observadores = [];
	},
};

/**
 * MutationObserver de atributos: avisa en cuanto cambia uno que mira, sin
 * microtarea (lo que haga el oyente con el reloj lo corre la prueba).
 */
class MutationObserverFalso {
	constructor( fn ) {
		this.fn = fn;
	}
	observe( nodo, opciones ) {
		mundo.observadores.push( { mo: this, nodo, atributos: ( opciones && opciones.attributeFilter ) || null } );
	}
	disconnect() {
		mundo.observadores = mundo.observadores.filter( ( o ) => o.mo !== this );
	}
}

function avisarAtributo( nodo, nombre ) {
	mundo.observadores
		.filter( ( o ) => o.nodo === nodo && ( ! o.atributos || o.atributos.includes( nombre ) ) )
		.forEach( ( o ) => o.mo.fn( [ { type: 'attributes', target: nodo, attributeName: nombre } ], o.mo ) );
}

/** Atributo booleano reflejado como propiedad (`disabled`, `hidden`). */
function reflejarBooleano( clase, nombre ) {
	Object.defineProperty( clase.prototype, nombre, {
		get() {
			return this.hasAttribute( nombre );
		},
		set( v ) {
			if ( v ) {
				this.attrs[ nombre ] = '';
			} else {
				delete this.attrs[ nombre ];
			}
		},
	} );
}

/** Atributo de texto reflejado como propiedad (`id`, `className` → `class`, `value`). */
function reflejarTexto( clase, propiedad, atributo ) {
	Object.defineProperty( clase.prototype, propiedad, {
		get() {
			return this.getAttribute( atributo ) || '';
		},
		set( v ) {
			this.attrs[ atributo ] = String( v );
		},
	} );
}

class Nodo {
	constructor( tag, attrs = {}, children = [] ) {
		this.tagName = tag.toUpperCase();
		this.attrs = { ...attrs };
		this.children = [];
		this.parentNode = null;
		this.dataset = {};
		this.style = {};
		this.oyentes = [];
		const self = this;
		this.classList = {
			add: ( k ) => self.setAttribute( 'class', ( ( self.getAttribute( 'class' ) || '' ) + ' ' + k ).trim() ),
			remove: ( k ) => self.setAttribute( 'class', ( self.getAttribute( 'class' ) || '' ).split( /\s+/ ).filter( ( x ) => x !== k ).join( ' ' ) ),
			contains: ( k ) => ( self.getAttribute( 'class' ) || '' ).split( /\s+/ ).includes( k ),
			toggle: ( k, on ) => ( ( undefined === on ? ! self.classList.contains( k ) : on ) ? self.classList.add( k ) : self.classList.remove( k ) ),
		};
		// Como en el navegador, lo de dentro de un <template> vive en `content`,
		// fuera del árbol: ningún selector del documento lo encuentra.
		const destino = 'TEMPLATE' === this.tagName ? ( this.content = new Nodo( '#fragmento' ) ) : this;
		children.forEach( ( c ) => destino.appendChild( 'string' === typeof c ? new Texto( c ) : c ) );
	}
	get firstElementChild() {
		return this.children.find( ( c ) => c.tagName ) || null;
	}
	cloneNode( profundo ) {
		const copia = new Nodo( this.tagName.toLowerCase(), this.attrs );
		if ( profundo ) {
			this.children.forEach( ( c ) => copia.appendChild( c.cloneNode( true ) ) );
		}
		return copia;
	}
	getAttribute( n ) {
		return Object.prototype.hasOwnProperty.call( this.attrs, n ) ? this.attrs[ n ] : null;
	}
	setAttribute( n, v ) {
		this.attrs[ n ] = String( v );
		avisarAtributo( this, n );
	}
	removeAttribute( n ) {
		delete this.attrs[ n ];
		avisarAtributo( this, n );
	}
	hasAttribute( n ) {
		return Object.prototype.hasOwnProperty.call( this.attrs, n );
	}
	appendChild( c ) {
		c.remove();
		c.parentNode = this;
		this.children.push( c );
		return c;
	}
	remove() {
		if ( this.parentNode ) {
			const hermanos = this.parentNode.children;
			hermanos.splice( hermanos.indexOf( this ), 1 );
			this.parentNode = null;
		}
	}
	replaceWith( n ) {
		const p = this.parentNode;
		n.remove();
		p.children.splice( p.children.indexOf( this ), 1, n );
		n.parentNode = p;
		this.parentNode = null;
	}
	after( n ) {
		const p = this.parentNode;
		n.remove();
		p.children.splice( p.children.indexOf( this ) + 1, 0, n );
		n.parentNode = p;
	}
	insertBefore( n, ref ) {
		if ( ! ref ) {
			return this.appendChild( n );
		}
		n.remove();
		this.children.splice( this.children.indexOf( ref ), 0, n );
		n.parentNode = this;
		return n;
	}
	get parentElement() {
		return this.parentNode && this.parentNode.tagName ? this.parentNode : null;
	}
	get nextSibling() {
		const p = this.parentNode;
		return p ? p.children[ p.children.indexOf( this ) + 1 ] || null : null;
	}
	matches( selector ) {
		return matchesSelector( this, selector );
	}
	closest( selector ) {
		for ( let e = this; e && e.tagName; e = e.parentNode ) {
			if ( e.matches( selector ) ) {
				return e;
			}
		}
		return null;
	}
	querySelectorAll( selector ) {
		const out = [];
		const walk = ( e ) => e.children.forEach( ( c ) => {
			if ( c.tagName && c.matches( selector ) ) {
				out.push( c );
			}
			walk( c );
		} );
		walk( this );
		return out;
	}
	querySelector( selector ) {
		return this.querySelectorAll( selector )[ 0 ] || null;
	}
	get textContent() {
		return this.children.map( ( c ) => c.textContent ).join( '' );
	}
	set textContent( v ) {
		this.children.forEach( ( c ) => {
			c.parentNode = null;
		} );
		this.children = [];
		if ( '' !== String( v ) ) {
			this.appendChild( new Texto( String( v ) ) );
		}
	}
	addEventListener( type, fn, opts ) {
		this.oyentes.push( { type, fn, capture: true === opts || !! ( opts && opts.capture ), once: !! ( opts && opts.once ) } );
	}
	removeEventListener( type, fn, opts ) {
		const capture = true === opts || !! ( opts && opts.capture );
		this.oyentes = this.oyentes.filter( ( o ) => ! ( o.type === type && o.fn === fn && o.capture === capture ) );
	}
	/** Enfoca como el navegador: un control deshabilitado no toma el foco. */
	focus() {
		if ( ! this.disabled ) {
			mundo.activo = this;
		}
	}
	blur() {
		if ( mundo.activo === this ) {
			mundo.activo = null;
		}
	}
	scrollIntoView( opts ) {
		mundo.desplazamientos.push( { nodo: this, opts: opts } );
	}
	contains( n ) {
		for ( let e = n; e; e = e.parentNode ) {
			if ( e === this ) {
				return true;
			}
		}
		return false;
	}
	getBoundingClientRect() {
		const c = mundo.caja( this ) || {};
		const top = c.top || 0;
		const left = c.left || 0;
		const width = c.width || 0;
		const height = c.height || 0;
		return { top, left, width, height, bottom: top + height, right: left + width, x: left, y: top };
	}
	get offsetWidth() {
		return this.getBoundingClientRect().width;
	}
	get offsetHeight() {
		return this.getBoundingClientRect().height;
	}
	/** En <html>, la ventana; en los demás, su caja. */
	get clientWidth() {
		return 'HTML' === this.tagName ? mundo.ventana.ancho : this.getBoundingClientRect().width;
	}
	get clientHeight() {
		return 'HTML' === this.tagName ? mundo.ventana.alto : this.getBoundingClientRect().height;
	}
	/** Sin bordes: la caja de relleno empieza donde la del borde. */
	get clientTop() {
		return 0;
	}
	get clientLeft() {
		return 0;
	}
	/** `popover` existe salvo que la prueba modele un navegador sin él. */
	get showPopover() {
		return mundo.sinPopover ? undefined : () => {
			mundo.capaSuperior.push( this );
		};
	}
}
reflejarBooleano( Nodo, 'hidden' );
reflejarBooleano( Nodo, 'disabled' );

/**
 * Estado que el cliente cambia (`checked`, `selected`) separado del atributo
 * que pintó el servidor (`defaultChecked`, `defaultSelected`), como en el
 * navegador: el atributo sólo da el valor inicial, y `:checked` mira el
 * estado. El PJAX vuelve a lo pintado leyendo el atributo tras un fallo de
 * red (volverALoPintado()).
 */
function reflejarEstado( clase, estado, atributo ) {
	const propio = Symbol( estado );
	Object.defineProperty( clase.prototype, estado, {
		get() {
			return undefined === this[ propio ] ? this.hasAttribute( atributo ) : this[ propio ];
		},
		set( v ) {
			this[ propio ] = !! v;
		},
	} );
	Object.defineProperty( clase.prototype, atributo.replace( /^./, ( l ) => 'default' + l.toUpperCase() ), {
		get() {
			return this.hasAttribute( atributo );
		},
		set( v ) {
			if ( v ) {
				this.attrs[ atributo ] = '';
			} else {
				delete this.attrs[ atributo ];
			}
		},
	} );
}
reflejarEstado( Nodo, 'checked', 'checked' );
reflejarEstado( Nodo, 'selected', 'selected' );
reflejarTexto( Nodo, 'id', 'id' );
reflejarTexto( Nodo, 'className', 'class' );
reflejarTexto( Nodo, 'value', 'value' );

/** Nodo de texto: sólo lo que hace falta para `textContent`. */
class Texto {
	constructor( texto ) {
		this.texto = texto;
		this.parentNode = null;
		this.children = [];
	}
	get textContent() {
		return this.texto;
	}
	cloneNode() {
		return new Texto( this.texto );
	}
	remove() {
		Nodo.prototype.remove.call( this );
	}
}

const h = ( tag, attrs, ...children ) => new Nodo( tag, attrs || {}, children );

/**
 * `document` sobre un árbol `html > body`. `readyState` decide si el bundle
 * arranca al evaluarse (`complete`) o espera a un DOMContentLoaded que nunca
 * llega (`loading`).
 */
function crearDocumento( root, readyState ) {
	return {
		readyState: readyState || 'complete',
		title: '',
		body: root.querySelector( 'body' ),
		documentElement: root,
		oyentes: [],
		get activeElement() {
			return mundo.activo || this.body;
		},
		addEventListener: Nodo.prototype.addEventListener,
		removeEventListener: Nodo.prototype.removeEventListener,
		// Un evento propio sobre `document` (p. ej. `akb:pjax`): lo oyen sus
		// oyentes, en las dos fases, como en el navegador.
		dispatchEvent( e ) {
			this.oyentes.filter( ( o ) => o.type === e.type ).forEach( ( o ) => o.fn.call( this, e ) );
			return true;
		},
		querySelector: ( s ) => root.querySelector( s ),
		querySelectorAll: ( s ) => root.querySelectorAll( s ),
		getElementById: ( id ) => root.querySelector( '#' + id ),
		elementFromPoint: ( x, y ) => mundo.alPunto( x, y ),
		createElement: ( tag ) => new Nodo( tag ),
		importNode: ( n ) => n,
	};
}

/**
 * Dispara un evento como el navegador: captura desde `document` hacia el
 * objetivo, luego burbuja hasta `document`. Devuelve el evento.
 */
function disparar( doc, target, type, props ) {
	let detenido = false;
	const e = Object.assign( {
		type: type,
		target: target,
		defaultPrevented: false,
		preventDefault() {
			this.defaultPrevented = true;
		},
		stopPropagation() {
			detenido = true;
		},
		stopImmediatePropagation() {
			detenido = true;
		},
	}, props || {} );
	const camino = [];
	for ( let n = target; n && n.tagName; n = n.parentNode ) {
		camino.push( n );
	}
	const correr = ( quien, captura ) => {
		for ( const o of quien.oyentes.slice() ) {
			if ( o.type !== type || o.capture !== captura ) {
				continue;
			}
			if ( o.once ) {
				quien.removeEventListener( type, o.fn, o.capture );
			}
			o.fn.call( quien, e );
			if ( detenido ) {
				return true;
			}
		}
		return false;
	};
	if ( correr( doc, true ) ) {
		return e;
	}
	for ( const n of camino.slice().reverse() ) {
		if ( correr( n, true ) ) {
			return e;
		}
	}
	for ( const n of camino ) {
		if ( correr( n, false ) ) {
			return e;
		}
	}
	correr( doc, false );
	return e;
}

/** `FormData` de un form del árbol: lo que enviaría el navegador. */
class FormDataFalso {
	constructor( form ) {
		this.pares = [];
		form.querySelectorAll( 'input, select' ).forEach( ( el ) => {
			const nombre = el.getAttribute( 'name' );
			const tipo = el.getAttribute( 'type' ) || 'text';
			if ( ! nombre || el.disabled || ( ( 'checkbox' === tipo || 'radio' === tipo ) && ! el.checked ) ) {
				return;
			}
			this.pares.push( [ nombre, el.getAttribute( 'value' ) || '' ] );
		} );
	}
	forEach( fn ) {
		this.pares.forEach( ( [ k, v ] ) => fn( v, k ) );
	}
	[ Symbol.iterator ]() {
		return this.pares[ Symbol.iterator ]();
	}
}

module.exports = { Nodo, Texto, h, mundo, matchesSelector, crearDocumento, disparar, FormDataFalso, MutationObserverFalso };
