/**
 * Akibara — Address Autocomplete (Google Places New).
 *
 * Lazy-load del SDK Google Maps: el script solo se inyecta cuando el cliente
 * hace foco/touch por primera vez en un input de dirección. Hasta ese momento
 * la página es 0KB pesada de Google.
 *
 * Adaptado del módulo legacy `akibara-core/address-autocomplete.js`:
 *   - ES6+ syntax, sin jQuery hard dependency (solo para hooks WC opcionales).
 *   - MutationObserver scopeado al form (no al body completo).
 *   - Set en vez de WeakSet para poder reset si hace falta.
 *   - Sin pickup-lock ni auto-cap (otros concerns; viven en otros módulos).
 */
( function () {
	'use strict';

	const config = window.akbAddrAuto;
	if ( ! config || ! Array.isArray( config.targets ) || ! config.targets.length ) {
		return;
	}

	const initialized = new Set();
	let   loaderState = 'idle'; // idle | loading | ready

	function loadMapsSdk() {
		if ( loaderState !== 'idle' || ! config.loaderUrl ) {
			return;
		}
		loaderState = 'loading';
		const s   = document.createElement( 'script' );
		s.src     = config.loaderUrl;
		s.async   = true;
		s.defer   = true;
		s.onerror = () => {
			loaderState = 'idle';
			console.warn( '[akibara] Failed to load Google Maps JS API.' );
		};
		document.head.appendChild( s );
	}

	function selectorFromTargets() {
		return config.targets.map( ( t ) => t.input ).join( ',' );
	}

	function setupLazyTrigger() {
		const sel = selectorFromTargets();
		if ( ! sel ) return;

		const onFirst = ( e ) => {
			if ( ! e.target || ! e.target.matches || ! e.target.matches( sel ) ) return;
			document.removeEventListener( 'focusin',     onFirst, true );
			document.removeEventListener( 'pointerdown', onFirst, true );
			document.removeEventListener( 'touchstart',  onFirst, true );
			loadMapsSdk();
		};

		document.addEventListener( 'focusin',     onFirst, true );
		document.addEventListener( 'pointerdown', onFirst, true );
		document.addEventListener( 'touchstart',  onFirst, true );
	}

	// Callback que dispara el loader de Google Maps (?callback=akbAddrAutoBoot).
	window.akbAddrAutoBoot = function () {
		loaderState = 'ready';
		if ( ! window.google || ! google.maps ) return;

		const importLib = google.maps.importLibrary
			? google.maps.importLibrary( 'places' )
			: Promise.resolve();

		importLib.then( bootstrap ).catch( ( err ) => {
			console.warn( '[akibara] Places import failed:', err );
		} );
	};

	function bootstrap() {
		if ( ! google.maps.places || ! google.maps.places.PlaceAutocompleteElement ) {
			console.warn( '[akibara] PlaceAutocompleteElement no disponible. Habilitar Places API (New) en Google Cloud Console.' );
			return;
		}

		attachAll();

		// Debounce attach con rAF — checkout dispara cientos de mutaciones por click.
		let scheduled = false;
		const schedule = () => {
			if ( scheduled ) return;
			scheduled = true;
			requestAnimationFrame( () => {
				scheduled = false;
				attachAll();
			} );
		};

		// MutationObserver scopeado al form de checkout (no al body entero).
		const form = document.querySelector( 'form.checkout, form.woocommerce-edit-address-form, form.woocommerce-address-fields' )
			|| document.body;
		new MutationObserver( schedule ).observe( form, { childList: true, subtree: true } );

		if ( window.jQuery ) {
			jQuery( document.body ).on( 'updated_checkout country_to_state_changed', schedule );
		}
	}

	function attachAll() {
		config.targets.forEach( ( target ) => {
			document.querySelectorAll( target.input ).forEach( ( input ) => {
				attachOne( input, target.prefix );
			} );
		} );
	}

	function attachOne( input, prefix ) {
		if ( ! input || initialized.has( input ) ) return;
		// Web Component no inicializa shadow DOM si está fuera del render tree
		// (ancestor con display:none). Dejamos que el MO reintente cuando aparezca.
		if ( input.offsetParent === null && input !== document.body ) return;

		initialized.add( input );

		let pac;
		try {
			pac = new google.maps.places.PlaceAutocompleteElement( {
				includedRegionCodes: config.country || [ 'cl' ],
			} );
		} catch ( e ) {
			console.warn( '[akibara] PlaceAutocompleteElement init failed:', e );
			initialized.delete( input );
			return;
		}

		pac.id        = input.id ? `${ input.id }-pac` : '';
		pac.className = 'akb-pac-element';
		if ( input.placeholder ) {
			pac.setAttribute( 'placeholder', input.placeholder );
		}

		// Al crear el widget en Mi cuenta, hereda el valor que WooCommerce ya cargó en el input de
		// dirección. La sincronización inversa evita mostrar vacío un campo con datos guardados.
		if ( input.value ) {
			pac.value = input.value;
		}

		// Web Component reemplaza visualmente al input, pero el input nativo
		// sigue en el DOM (oculto) para que WC reciba el value en el POST.
		input.parentNode && input.parentNode.insertBefore( pac, input );
		input.style.display = 'none';
		input.setAttribute( 'aria-hidden', 'true' );
		input.tabIndex = -1;

		pac.addEventListener( 'gmp-select', async ( event ) => {
			const prediction = event.placePrediction;
			if ( ! prediction ) return;

			try {
				const place = prediction.toPlace();
				await place.fetchFields( { fields: [ 'addressComponents', 'formattedAddress' ] } );
				applyPlace( place, input, prefix );
			} catch ( err ) {
				console.warn( '[akibara] gmp-select failed:', err );
			}
		} );

		// Mirror libre: si Google no encuentra la calle (zonas rurales, calles menores,
		// nombres alternativos), el cliente escribe igual y el texto se replica al input
		// nativo oculto. WC valida ese input y pinta verde el form-row vía CSS.
		const syncFromPac = () => {
			const raw = ( pac.value != null ? String( pac.value ) : '' ).trim();
			if ( input.value !== raw ) {
				setValue( input, raw );
				if ( window.jQuery ) {
					try { jQuery( input ).trigger( 'validate' ); } catch ( e ) {}
				}
			}
		};
		pac.addEventListener( 'input',  syncFromPac );
		pac.addEventListener( 'change', syncFromPac );
		// `capture: true` para alcanzar el blur del input interno del shadow root.
		pac.addEventListener( 'blur',   syncFromPac, true );
	}

	function applyPlace( place, addressInput, prefix ) {
		const parsed = parseComponents( place.addressComponents );

		const street = [ parsed.route, parsed.streetNumber ].filter( Boolean ).join( ' ' );
		if ( street ) setValue( addressInput, street );

		if ( parsed.city ) setCityValue( prefix, parsed.city );

		const regionCode = mapRegion( parsed.stateShort, parsed.stateLong );
		if ( regionCode ) setStateValue( prefix, regionCode );

		const post = document.querySelector( `#${ prefix }_postcode, input[name="${ prefix }_postcode"]` );
		if ( post && parsed.postcode ) setValue( post, parsed.postcode );

		// Forzar recalculo de envío en checkout shortcode.
		if ( window.jQuery ) {
			try { jQuery( document.body ).trigger( 'update_checkout' ); } catch ( e ) {}
		}
	}

	function parseComponents( components ) {
		const out = {};
		if ( ! components || ! components.length ) return out;

		components.forEach( ( c ) => {
			const types  = Array.isArray( c.types ) ? c.types : [];
			const longT  = c.longText  || c.long_name  || '';
			const shortT = c.shortText || c.short_name || '';

			if ( types.includes( 'street_number' ) ) out.streetNumber = longT;
			if ( types.includes( 'route' ) )         out.route        = longT;
			if ( types.includes( 'locality' ) )      out.city         = longT;
			if ( ! out.city && types.includes( 'administrative_area_level_3' ) ) out.city = longT;
			if ( ! out.city && types.includes( 'administrative_area_level_2' ) ) out.city = longT;
			if ( types.includes( 'administrative_area_level_1' ) ) {
				out.stateShort = shortT;
				out.stateLong  = longT;
			}
			if ( types.includes( 'postal_code' ) ) out.postcode = longT;
		} );
		return out;
	}

	const FALLBACK_REGIONS = {
		'region metropolitana de santiago': 'CL-RM',
		'region metropolitana':             'CL-RM',
		'arica y parinacota':               'CL-AP',
		'tarapaca':                         'CL-TA',
		'antofagasta':                      'CL-AN',
		'atacama':                          'CL-AT',
		'coquimbo':                         'CL-CO',
		'valparaiso':                       'CL-VS',
		"o'higgins":                        'CL-LI',
		'maule':                            'CL-ML',
		'nuble':                            'CL-NB',
		'biobio':                           'CL-BI',
		'la araucania':                     'CL-AR',
		'araucania':                        'CL-AR',
		'los rios':                         'CL-LR',
		'los lagos':                        'CL-LL',
		'aysen':                            'CL-AI',
		'magallanes':                       'CL-MA',
	};

	function mapRegion( shortName, longName ) {
		const map = config.regionMap || {};
		if ( shortName && map[ shortName ] ) return map[ shortName ];

		if ( longName ) {
			const norm = longName.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
			if ( FALLBACK_REGIONS[ norm ] ) return FALLBACK_REGIONS[ norm ];
		}
		return null;
	}

	function setStateValue( prefix, code ) {
		const el = document.querySelector( `#${ prefix }_state, select[name="${ prefix }_state"], input[name="${ prefix }_state"]` );
		if ( ! el ) return;

		if ( el.tagName === 'SELECT' ) {
			const sufix = code.replace( /^CL-/, '' );
			let matched = false;
			for ( let i = 0; i < el.options.length; i++ ) {
				const v = el.options[ i ].value;
				if ( v === code || v === sufix ) {
					el.selectedIndex = i;
					matched = true;
					break;
				}
			}
			if ( matched ) {
				dispatchChange( el );
				if ( window.jQuery && jQuery.fn.select2 ) {
					try { jQuery( el ).trigger( 'change' ); } catch ( e ) {}
				}
			}
		} else {
			setValue( el, code );
		}
	}

	function setCityValue( prefix, cityName ) {
		const el = document.querySelector( `#${ prefix }_city, select[name="${ prefix }_city"], input[name="${ prefix }_city"]` );
		if ( ! el ) return;

		if ( el.tagName === 'SELECT' ) {
			const normCity = cityName.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
			let matched = false;
			for ( let i = 0; i < el.options.length; i++ ) {
				const opt = el.options[ i ];
				const v = opt.value.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
				const t = opt.text.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
				
				// Hacemos match suave (ej: "san miguel" == "san miguel")
				if ( v === normCity || t === normCity ) {
					el.selectedIndex = i;
					matched = true;
					break;
				}
			}
			if ( matched ) {
				dispatchChange( el );
				if ( window.jQuery && jQuery.fn.select2 ) {
					try { jQuery( el ).trigger( 'change' ); } catch ( e ) {}
				}
			}
		} else {
			setValue( el, cityName );
		}
	}

	function setValue( el, val ) {
		el.value = val;
		dispatchChange( el );
	}

	function dispatchChange( el ) {
		el.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		if ( window.jQuery ) {
			try { jQuery( el ).trigger( 'change' ); } catch ( e ) {}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setupLazyTrigger );
	} else {
		setupLazyTrigger();
	}
} )();
