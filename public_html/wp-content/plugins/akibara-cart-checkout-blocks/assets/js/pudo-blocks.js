(function (wp, wc) {
	'use strict';

	var WIDGET_ORIGIN = 'https://widget-pudo.blue.cl';
	var WRITE_NAMESPACE = 'bluex_pudo';
	var VALIDATION_KEY = 'akibara-pudo-selection';
	var MAX_MESSAGE_BYTES = 8192;
	var UPDATE_TIMEOUT_MS = 15000;
	var FRAME_TIMEOUT_MS = 12000;
	var PUDO_METHOD = 'bluex-pudo';

	function isRecord(value) {
		return value !== null && typeof value === 'object' && !Array.isArray(value);
	}

	function hasOnlyKeys(value, allowed) {
		return Object.keys(value).every(function (key) {
			return allowed.indexOf(key) !== -1;
		});
	}

	function utf8Length(value) {
		var length = 0;
		var index;

		for (index = 0; index < value.length; index += 1) {
			var code = value.charCodeAt(index);
			if (code < 0x80) {
				length += 1;
			} else if (code < 0x800) {
				length += 2;
			} else if (code >= 0xD800 && code <= 0xDBFF && index + 1 < value.length) {
				length += 4;
				index += 1;
			} else {
				length += 3;
			}
		}

		return length;
	}

	function chileRegionCode(stateName) {
		var regions = {
			'Aysén': 'CL-AI',
			'Aisén del General Carlos Ibañez del Campo': 'CL-AI',
			'Antofagasta': 'CL-AN',
			'Arica y Parinacota': 'CL-AP',
			'Araucanía': 'CL-AR',
			'La Araucanía': 'CL-AR',
			'Atacama': 'CL-AT',
			'Bío - Bío': 'CL-BI',
			'Biobío': 'CL-BI',
			'Coquimbo': 'CL-CO',
			'Libertador General Bernardo O`Higgins': 'CL-LI',
			"Libertador General Bernardo O'Higgins": 'CL-LI',
			'Los Lagos': 'CL-LL',
			'Los Ríos': 'CL-LR',
			'Magallanes y la Antartica Chilena': 'CL-MA',
			'Magallanes': 'CL-MA',
			'Maule': 'CL-ML',
			'Ñuble': 'CL-NB',
			'Metropolitana de Santiago': 'CL-RM',
			'Región Metropolitana de Santiago': 'CL-RM',
			'Tarapacá': 'CL-TA',
			'Valparaiso': 'CL-VS',
			'Valparaíso': 'CL-VS'
		};

		return regions[stateName] || '';
	}

	function chileRegionName(regionCode) {
		var names = {
			'CL-AI': 'Aysén',
			'CL-AN': 'Antofagasta',
			'CL-AP': 'Arica y Parinacota',
			'CL-AR': 'La Araucanía',
			'CL-AT': 'Atacama',
			'CL-BI': 'Biobío',
			'CL-CO': 'Coquimbo',
			'CL-LI': "O'Higgins",
			'CL-LL': 'Los Lagos',
			'CL-LR': 'Los Ríos',
			'CL-MA': 'Magallanes',
			'CL-ML': 'Maule',
			'CL-NB': 'Ñuble',
			'CL-RM': 'Región Metropolitana',
			'CL-TA': 'Tarapacá',
			'CL-VS': 'Valparaíso'
		};

		return names[regionCode] || '';
	}

	function cleanWidgetString(value, maxLength) {
		if (typeof value !== 'string') {
			return '';
		}

		var clean = value.trim();
		if (!clean || clean.length > maxLength || /[\u0000-\u001F\u007F]/.test(clean)) {
			return '';
		}

		return clean;
	}

	function parseWidgetMessage(event, iframe) {
		if (
			!event
			|| event.origin !== WIDGET_ORIGIN
			|| !iframe
			|| iframe.isConnected !== true
			|| event.source !== iframe.contentWindow
			|| !isRecord(event.data)
		) {
			return null;
		}

		var serialized;
		try {
			serialized = JSON.stringify(event.data);
		} catch (error) {
			return null;
		}

		if (!serialized || utf8Length(serialized) > MAX_MESSAGE_BYTES) {
			return null;
		}

		if (
			!hasOnlyKeys(event.data, ['type', 'payload'])
			|| event.data.type !== 'pudo:select'
			|| !isRecord(event.data.payload)
			|| !isRecord(event.data.payload.location)
		) {
			return null;
		}

		var payload = event.data.payload;
		var location = payload.location;
		var agencyId = cleanWidgetString(payload.agency_id, 64);
		var street = cleanWidgetString(location.street_name || '', 100);
		var number = cleanWidgetString(location.street_number || '', 24);
		var city = cleanWidgetString(location.city_name || '', 100);
		var country = cleanWidgetString(location.country_name || '', 40);
		var region = cleanWidgetString(location.region_code || '', 5).toUpperCase();

		if (!region) {
			region = chileRegionCode(cleanWidgetString(location.state_name || '', 100));
		}

		if (
			!agencyId
			|| !/^[A-Za-z0-9][A-Za-z0-9._:-]*$/.test(agencyId)
			|| !street
			|| !city
			|| !/^CL-[A-Z]{2}$/.test(region)
			|| (country && ['Chile', 'CL'].indexOf(country) === -1)
		) {
			return null;
		}

		var address = [street, number, city].filter(Boolean).join(' ').trim();
		if (!address || address.length > 200) {
			return null;
		}

		return {
			agency_id: agencyId,
			address: address,
			region_code: region
		};
	}

	function buildWidgetUrl(agencyId) {
		var cleanId = cleanWidgetString(agencyId || '', 64);
		if (cleanId && !/^[A-Za-z0-9][A-Za-z0-9._:-]*$/.test(cleanId)) {
			cleanId = '';
		}
		return cleanId ? WIDGET_ORIGIN + '?id=' + encodeURIComponent(cleanId) : WIDGET_ORIGIN;
	}

	function markMessageProcessed(replayRef, request) {
		var replayKey = JSON.stringify(request);
		if (replayRef.current === replayKey) {
			return false;
		}

		replayRef.current = replayKey;
		return true;
	}

	function createAttemptId() {
		var bytes = new Uint8Array(16);
		if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
			throw new Error('Secure browser randomness is unavailable.');
		}

		window.crypto.getRandomValues(bytes);
		return Array.prototype.map.call(bytes, function (value) {
			return value.toString(16).padStart(2, '0');
		}).join('');
	}

	function shippingPackages(props) {
		var cart = props && props.cart ? props.cart : {};
		var packages = cart.shippingRates || cart.shipping_rates || (props && props.shippingRates) || [];
		return Array.isArray(packages) ? packages : [];
	}

	function ratesForPackage(packageData) {
		if (!packageData || !Array.isArray(packageData.shipping_rates)) {
			return [];
		}

		return packageData.shipping_rates;
	}

	function isPudoMethod(methodId) {
		return methodId === PUDO_METHOD
			|| (typeof methodId === 'string' && methodId.indexOf(PUDO_METHOD + ':') === 0);
	}

	function selectedPudoPackages(packages) {
		return packages.filter(function (packageData) {
			return ratesForPackage(packageData).some(function (rate) {
				return rate && rate.selected === true && isPudoMethod(rate.method_id || rate.rate_id || '');
			});
		});
	}

	/**
	 * Reads the server-side shipping destination attached to the PUDO package.
	 *
	 * Blue's widget always opens on Santiago and lists every point in Chile. The
	 * destination is used only to tell the customer where their order ships, never
	 * to restrict which point they may choose: Blue itself imposes no such rule.
	 */
	function pudoDestination(packages) {
		var packageData = Array.isArray(packages) && packages.length ? packages[0] : null;
		var destination = packageData && isRecord(packageData.destination)
			? packageData.destination
			: null;

		if (!destination) {
			return null;
		}

		var country = cleanWidgetString(String(destination.country || ''), 5).toUpperCase();
		var region = cleanWidgetString(String(destination.state || ''), 5).toUpperCase();
		var city = cleanWidgetString(String(destination.city || ''), 100);

		if (country !== 'CL' || !/^CL-[A-Z]{2}$/.test(region)) {
			return null;
		}

		return {
			city: city,
			region: region
		};
	}

	function destinationPlaceLabel(destination) {
		if (!destination) {
			return '';
		}

		var regionName = chileRegionName(destination.region);
		if (destination.city && regionName) {
			return destination.city + ', ' + regionName;
		}

		return destination.city || regionName;
	}

	function regionScopeHint(destination) {
		var place = destinationPlaceLabel(destination);
		if (!place) {
			return '';
		}

		return 'Tu pedido se despacha a ' + place + '. Busca tu punto de retiro en el mapa.';
	}

	function extensionData(props) {
		var extensions = props && props.extensions
			? props.extensions
			: props && props.cart && props.cart.extensions
				? props.cart.extensions
				: {};

		return extensions && isRecord(extensions[WRITE_NAMESPACE])
			? extensions[WRITE_NAMESPACE]
			: {};
	}

	function monitorTimeout(promise, timeoutMs, onTimeout) {
		var timeout = window.setTimeout(function () {
			onTimeout();
		}, timeoutMs);

		return Promise.resolve(promise).then(
			function (value) {
				window.clearTimeout(timeout);
				return value;
			},
			function (error) {
				window.clearTimeout(timeout);
				throw error;
			}
		);
	}

	function canDismissOperation(saving, timedOut) {
		return !saving || timedOut;
	}

	function createSerializedQueue() {
		var tail = Promise.resolve();

		return function enqueue(operation) {
			var current = tail.catch(function () {
				// A failed request must not poison the queue for the next valid state change.
			}).then(operation);

			tail = current.catch(function () {
				// Keep the internal tail settled while preserving rejection for the caller.
			});

			return current;
		};
	}

	var enqueueCartUpdateOperation = createSerializedQueue();

	function isolateDocumentBehindModal(modalElement) {
		if (!modalElement || !document || !document.body) {
			return function () {};
		}

		var records = [];
		var current = modalElement;

		while (current && current !== document.body) {
			var parent = current.parentElement;
			if (!parent) {
				break;
			}

			Array.prototype.slice.call(parent.children || []).forEach(function (sibling) {
				if (sibling === current || !sibling || typeof sibling.setAttribute !== 'function') {
					return;
				}

				records.push({
					element: sibling,
					hadAriaHidden: sibling.hasAttribute('aria-hidden'),
					hadInert: sibling.hasAttribute('inert'),
					previousAriaHidden: sibling.getAttribute('aria-hidden'),
					previousInert: sibling.getAttribute('inert'),
					previousInertProperty: typeof sibling.inert === 'boolean' ? sibling.inert : null
				});
				sibling.setAttribute('inert', '');
				sibling.setAttribute('aria-hidden', 'true');
				if (typeof sibling.inert === 'boolean') {
					sibling.inert = true;
				}
			});

			current = parent;
		}

		return function restoreDocument() {
			records.reverse().forEach(function (record) {
				var element = record.element;
				if (!element || typeof element.setAttribute !== 'function') {
					return;
				}

				if (record.hadAriaHidden) {
					element.setAttribute('aria-hidden', record.previousAriaHidden);
				} else {
					element.removeAttribute('aria-hidden');
				}

				if (record.hadInert) {
					element.setAttribute('inert', record.previousInert);
				} else {
					element.removeAttribute('inert');
				}

				if (record.previousInertProperty !== null) {
					element.inert = record.previousInertProperty;
				}
			});
		};
	}

	function validationActions() {
		if (!wp || !wp.data || typeof wp.data.dispatch !== 'function') {
			return null;
		}

		return wp.data.dispatch('wc/store/validation');
	}

	function setValidationError(message) {
		var actions = validationActions();
		if (!actions || typeof actions.setValidationErrors !== 'function') {
			return;
		}

		var errors = {};
		errors[VALIDATION_KEY] = {
			message: message,
			hidden: false
		};
		actions.setValidationErrors(errors);
	}

	function clearValidationError() {
		var actions = validationActions();
		if (actions && typeof actions.clearValidationError === 'function') {
			actions.clearValidationError(VALIDATION_KEY);
		}
	}

	/* Lista de códigos emitidos por PudoIntegration::throw_route_error para el endpoint bluex_pudo del Store API. Un código ajeno a esta lista no se considera mensaje de nuestro backend. */
	var PUDO_BACKEND_ERROR_CODES = [
		'akibara_pudo_checkout_invalid',
		'akibara_pudo_invalid_destination',
		'akibara_pudo_invalid_payload',
		'akibara_pudo_method_not_selected',
		'akibara_pudo_mismatched_selection',
		'akibara_pudo_multiple_packages',
		'akibara_pudo_mutation_conflict',
		'akibara_pudo_order_unavailable',
		'akibara_pudo_stale_attempt',
		'akibara_pudo_unavailable',
		'akibara_pudo_untrusted_payload',
		'akibara_pudo_validation_failed'
	];

	/** Extrae mensajes seguros de errores Store API con códigos propios de PUDO. Los fallos de red, DNS, CORS o JSON de api-fetch usan el mensaje en castellano de handleMessage. Se rechazan mensajes vacíos, largos o con marcado. */
	function serverMessage(error) {
		if (!error || typeof error.code !== 'string' || PUDO_BACKEND_ERROR_CODES.indexOf(error.code) === -1) {
			return '';
		}

		var candidate = error.message
			|| (error.data && error.data.message)
			|| (error.response && error.response.message)
			|| '';

		if (typeof candidate !== 'string') {
			return '';
		}

		candidate = candidate.trim();
		if (candidate === '' || candidate.length > 300) {
			return '';
		}

		return candidate;
	}

	if (window.akbPudoBlocksTestMode === true) {
		window.akbPudoBlocksTestApi = Object.freeze({
			WIDGET_ORIGIN: WIDGET_ORIGIN,
			MAX_MESSAGE_BYTES: MAX_MESSAGE_BYTES,
			buildWidgetUrl: buildWidgetUrl,
			canDismissOperation: canDismissOperation,
			chileRegionName: chileRegionName,
			destinationPlaceLabel: destinationPlaceLabel,
			pudoDestination: pudoDestination,
			regionScopeHint: regionScopeHint,
			clearValidationError: clearValidationError,
			createSerializedQueue: createSerializedQueue,
			createAttemptId: createAttemptId,
			isolateDocumentBehindModal: isolateDocumentBehindModal,
			markMessageProcessed: markMessageProcessed,
			monitorTimeout: monitorTimeout,
			parseWidgetMessage: parseWidgetMessage,
			serverMessage: serverMessage,
			setValidationError: setValidationError,
			selectedPudoPackages: selectedPudoPackages,
			shippingPackages: shippingPackages,
			utf8Length: utf8Length
		});
	}

	if (
		!wp
		|| !wp.element
		|| !wp.plugins
		|| !wc
		|| !wc.blocksCheckout
		|| typeof wp.plugins.registerPlugin !== 'function'
	) {
		return;
	}

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var ExperimentalOrderShippingPackages = wc.blocksCheckout.ExperimentalOrderShippingPackages;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;

	if (
		typeof ExperimentalOrderShippingPackages !== 'function'
		|| typeof extensionCartUpdate !== 'function'
		|| typeof useEffect !== 'function'
		|| typeof useRef !== 'function'
		|| typeof useState !== 'function'
	) {
		return;
	}

	function CloseIcon() {
		return createElement(
			'svg',
			{
				'aria-hidden': 'true',
				focusable: 'false',
				viewBox: '0 0 24 24',
				width: '24',
				height: '24'
			},
			createElement('path', {
				d: 'M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4Z',
				fill: 'currentColor'
			})
		);
	}

	function PudoModal(props) {
		var dialogRef = props.dialogRef;
		var iframeRef = props.iframeRef;
		var dismissible = canDismissOperation(props.saving, props.timedOut);
		var interactionBlocked = props.loading || props.saving || Boolean(props.error);

		function handleKeyDown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				if (dismissible) {
					props.onClose();
				}
				return;
			}

			if (event.key !== 'Tab' || !dialogRef.current) {
				return;
			}

			var focusable = Array.prototype.slice.call(
				dialogRef.current.querySelectorAll(
					'button:not([disabled]):not([tabindex="-1"]), iframe:not([tabindex="-1"]), [href]:not([tabindex="-1"]), input:not([disabled]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
				)
			);
			if (!focusable.length) {
				event.preventDefault();
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		return createElement(
			'div',
				{
					className: 'akb-pudo-modal',
					onMouseDown: function (event) {
						if (event.target === event.currentTarget && dismissible) {
							props.onClose();
						}
				},
				ref: props.modalRef
			},
			createElement(
				'section',
				{
					'aria-describedby': 'akb-pudo-dialog-description',
					'aria-labelledby': 'akb-pudo-dialog-title',
					'aria-modal': 'true',
					className: 'akb-pudo-dialog',
					onKeyDown: handleKeyDown,
					ref: dialogRef,
					role: 'dialog',
					tabIndex: -1
				},
				createElement(
					'p',
					{
						className: 'akb-pudo-sr-only',
						id: 'akb-pudo-dialog-description'
					},
					'Explora el mapa y selecciona el punto donde retirarás tu pedido. Puedes cerrar este diálogo con Escape.'
				),
				createElement(
					'header',
					{ className: 'akb-pudo-dialog__header' },
					createElement('h2', { id: 'akb-pudo-dialog-title' }, 'Selecciona tu Punto Blue Express'),
					createElement(
						'button',
							{
								'aria-label': 'Cerrar selector de Punto Blue Express',
								className: 'akb-pudo-dialog__close',
								disabled: !dismissible,
								onClick: props.onClose,
							type: 'button'
						},
						createElement(CloseIcon, null)
					)
				),
				props.scopeHint
					? createElement(
						'p',
						{ className: 'akb-pudo-dialog__scope' },
						props.scopeHint
					)
					: null,
				createElement(
					'div',
					{
						'aria-busy': props.loading || props.saving ? 'true' : 'false',
						className: 'akb-pudo-dialog__body'
					},
					props.loading
						? createElement(
							'div',
							{
								'aria-atomic': 'true',
								'aria-live': 'polite',
								className: 'akb-pudo-dialog__loading',
								role: 'status'
							},
							createElement('span', {
								'aria-label': 'Carga del mapa de puntos de retiro',
								'aria-valuetext': 'Cargando mapa de puntos de retiro',
								className: 'akb-pudo-spinner',
								role: 'progressbar'
							}),
							createElement('span', null, 'Cargando puntos de retiro…')
						)
						: null,
					createElement('iframe', {
						allow: 'geolocation',
						'aria-hidden': interactionBlocked ? 'true' : undefined,
						className: 'akb-pudo-dialog__iframe',
						key: props.frameKey,
						loading: 'eager',
						onLoad: props.onFrameLoad,
						ref: iframeRef,
						referrerPolicy: 'strict-origin-when-cross-origin',
						src: props.src,
						tabIndex: interactionBlocked ? -1 : 0,
						title: 'Mapa para seleccionar un Punto Blue Express'
					}),
					props.error
						? createElement(
							'div',
							{
								'aria-atomic': 'true',
								'aria-live': 'assertive',
								className: 'akb-pudo-dialog__error',
								role: 'alert'
							},
							createElement('p', null, props.error),
							createElement(
								'button',
								{
									className: 'akb-pudo-button akb-pudo-button--secondary',
									disabled: props.saving,
									onClick: props.onRetry,
									ref: props.retryButtonRef,
									type: 'button'
								},
								'Reintentar'
							)
						)
						: null,
					props.saving
						? createElement(
							'div',
							{
								'aria-atomic': 'true',
								'aria-live': 'polite',
								className: 'akb-pudo-dialog__saving',
								ref: props.savingStatusRef,
								role: 'status',
								tabIndex: -1
							},
							createElement('span', {
								'aria-label': 'Validación del punto de retiro',
								'aria-valuetext': props.savingMessage,
								className: 'akb-pudo-spinner',
								role: 'progressbar'
							}),
							createElement('span', null, props.savingMessage)
						)
						: null
				)
			)
		);
	}

	function PudoShippingFill(props) {
		var packages = shippingPackages(props);
		var pudoPackages = selectedPudoPackages(packages);
		var extension = extensionData(props);
		var agencyId = cleanWidgetString(extension.agency_id || '', 64);
		var agencyName = cleanWidgetString(extension.agency_name || '', 160);
		var agencyAddress = cleanWidgetString(extension.agency_address || '', 240);
		var hasPudo = pudoPackages.length > 0;
		var isMultiPackage = hasPudo && packages.length !== 1;
		var destination = pudoDestination(pudoPackages);
		var destinationRegion = destination ? destination.region : '';
		var destinationPlace = destinationPlaceLabel(destination);
		var scopeHint = regionScopeHint(destination);
		var stateOpen = useState(false);
		var open = stateOpen[0];
		var setOpen = stateOpen[1];
		var stateLoading = useState(true);
		var loading = stateLoading[0];
		var setLoading = stateLoading[1];
		var stateSaving = useState(false);
		var saving = stateSaving[0];
		var setSaving = stateSaving[1];
		var stateTimedOut = useState(false);
		var timedOut = stateTimedOut[0];
		var setTimedOut = stateTimedOut[1];
		var stateError = useState('');
		var error = stateError[0];
		var setError = stateError[1];
		var stateFrameKey = useState(0);
		var frameKey = stateFrameKey[0];
		var setFrameKey = stateFrameKey[1];
		var dialogRef = useRef(null);
		var iframeRef = useRef(null);
		var modalRef = useRef(null);
		var retryButtonRef = useRef(null);
		var savingStatusRef = useRef(null);
		var selectionButtonRef = useRef(null);
		var multiPackageErrorRef = useRef(null);
		var triggerRef = useRef(null);
		var previousActiveRef = useRef(null);
			var replayRef = useRef('');
			var savingRef = useRef(false);
			var timedOutRef = useRef(false);
			var clearedAgencyRef = useRef('');
			var invalidFocusRef = useRef('');

		/* serverMessage es independiente de las props y del estado del componente; comparte el contrato de PUDO_BACKEND_ERROR_CODES. */

		function queueCartUpdate(data) {
			return enqueueCartUpdateOperation(function () {
				return extensionCartUpdate({
					namespace: WRITE_NAMESPACE,
					data: data
				});
			});
			}

			function closeModal() {
				if (!canDismissOperation(savingRef.current, timedOutRef.current)) {
					return;
				}

				setOpen(false);
				setError('');
				if (!savingRef.current) {
					timedOutRef.current = false;
					setTimedOut(false);
				}
			}

			function openModal(event) {
				if (savingRef.current) {
					return;
				}

				triggerRef.current = event.currentTarget;
				previousActiveRef.current = document.activeElement;
				replayRef.current = '';
				setError('');
				timedOutRef.current = false;
				setTimedOut(false);
			setLoading(true);
			setOpen(true);
		}

		function retryFrame() {
				replayRef.current = '';
				setError('');
				timedOutRef.current = false;
				setTimedOut(false);
			setLoading(true);
			setFrameKey(function (current) {
				return current + 1;
			});
		}

		useEffect(function () {
			var focusTimeout;
			var invalidKind = isMultiPackage ? 'multi-package' : hasPudo && !agencyId ? 'missing-selection' : '';

			if (isMultiPackage) {
				setValidationError('El retiro en Punto Blue Express no admite pedidos divididos en más de un envío.');
			} else if (hasPudo && !agencyId) {
				setValidationError('Selecciona un Punto Blue Express antes de realizar el pedido.');
			} else {
				clearValidationError();
			}

			/* El pedido dividido mueve el foco al aviso porque requiere una decisión del cliente. No haber elegido todavía un punto de retiro es un estado inicial normal y conserva el foco actual. */
			if (invalidKind === 'multi-package' && invalidFocusRef.current !== invalidKind) {
				invalidFocusRef.current = invalidKind;
				focusTimeout = window.setTimeout(function () {
					var focusTarget = multiPackageErrorRef.current;
					if (focusTarget && typeof focusTarget.focus === 'function') {
						focusTarget.focus();
					}
				}, 0);
			} else if (invalidKind) {
				invalidFocusRef.current = invalidKind;
			} else if (!invalidKind) {
				invalidFocusRef.current = '';
			}

			return function () {
				if (focusTimeout) {
					window.clearTimeout(focusTimeout);
				}
			};
		}, [hasPudo, isMultiPackage, agencyId]);

		useEffect(function () {
			return function () {
				clearValidationError();
			};
		}, []);

		useEffect(function () {
			if (hasPudo) {
				clearedAgencyRef.current = '';
				return;
			}

			if (!agencyId || clearedAgencyRef.current === agencyId) {
				return;
			}

			clearedAgencyRef.current = agencyId;
			Promise.resolve(
				queueCartUpdate({ agency_id: '', attempt_id: createAttemptId() })
			).catch(function () {
				clearedAgencyRef.current = '';
			});
		}, [hasPudo, agencyId]);

		useEffect(function () {
			if (!open) {
				return undefined;
			}

			document.body.classList.add('akb-pudo-modal-open');
			var restoreDocument = function () {};
			var focusTimeout = window.setTimeout(function () {
				restoreDocument = isolateDocumentBehindModal(modalRef.current);

				document.addEventListener('focusin', keepFocusInDialog, true);
				// Land on the dialog itself, not the close button: a tabindex="-1"
				// container takes focus for screen readers without painting the
				// keyboard focus ring on a control the pointer user never chose.
				focusDialog();
			}, 0);

			function focusDialog() {
				if (dialogRef.current && typeof dialogRef.current.focus === 'function') {
					dialogRef.current.focus();
				}
			}

			function keepFocusInDialog(event) {
				if (
					!dialogRef.current
					|| !event
					|| !event.target
					|| dialogRef.current === event.target
					|| dialogRef.current.contains(event.target)
				) {
					return;
				}

				focusDialog();
			}

			return function () {
				window.clearTimeout(focusTimeout);
				document.removeEventListener('focusin', keepFocusInDialog, true);
				restoreDocument();
				document.body.classList.remove('akb-pudo-modal-open');
				var focusTarget = triggerRef.current || previousActiveRef.current;
				if (focusTarget && typeof focusTarget.focus === 'function') {
					focusTarget.focus();
				}
			};
		}, [open]);

		useEffect(function () {
			if (!open) {
				return undefined;
			}

			var focusTimeout = window.setTimeout(function () {
				if (error && retryButtonRef.current) {
					retryButtonRef.current.focus();
					return;
				}

				if (saving && savingStatusRef.current) {
					savingStatusRef.current.focus();
					return;
				}

				if (
					loading
					&& iframeRef.current
					&& document.activeElement === iframeRef.current
				) {
					iframeRef.current.blur();
					if (dialogRef.current && typeof dialogRef.current.focus === 'function') {
						dialogRef.current.focus();
					}
				}
			}, 0);

			return function () {
				window.clearTimeout(focusTimeout);
			};
		}, [open, loading, saving, error]);

		useEffect(function () {
			if (!open || !loading) {
				return undefined;
			}

			var timeout = window.setTimeout(function () {
				setLoading(false);
				setError('El mapa tardó demasiado en responder. Comprueba tu conexión y vuelve a intentarlo.');
			}, FRAME_TIMEOUT_MS);

			return function () {
				window.clearTimeout(timeout);
			};
		}, [open, loading, frameKey]);

		useEffect(function () {
			if (!open) {
				return undefined;
			}

			async function handleMessage(event) {
				var request = parseWidgetMessage(event, iframeRef.current);
				if (!request || savingRef.current) {
					return;
				}

				if (!markMessageProcessed(replayRef, request)) {
					return;
				}

				request.attempt_id = createAttemptId();

				savingRef.current = true;
					setSaving(true);
					setError('');
					timedOutRef.current = false;
					setTimedOut(false);

				try {
					await monitorTimeout(
						queueCartUpdate(request),
							UPDATE_TIMEOUT_MS,
							function () {
								timedOutRef.current = true;
								setTimedOut(true);
							}
					);
					clearValidationError();
					setOpen(false);
				} catch (requestError) {
					// El servidor manda un mensaje distinto por cada causa —punto
					// en otra región, selección reemplazada por otra pestaña,
					// Blue Express sin confirmar, conflicto de escritura— y todos
					// se descartaban para mostrar siempre el mismo texto. El
					// cliente leía «vuelve a intentarlo» y volvía a fallar igual,
					// porque el reintento no era la solución de ninguno de ellos.
					setError(serverMessage(requestError) || 'No pudimos guardar ese punto de retiro. Vuelve a intentarlo o elige otro punto.');
					} finally {
						savingRef.current = false;
						timedOutRef.current = false;
						setSaving(false);
						setTimedOut(false);
				}
			}

			window.addEventListener('message', handleMessage);
			return function () {
				window.removeEventListener('message', handleMessage);
			};
		}, [open, frameKey, destinationRegion, destinationPlace]);

		if (!hasPudo) {
			return null;
		}

		if (isMultiPackage) {
			return createElement(
				'div',
				{
					className: 'akb-pudo-package-error',
					ref: multiPackageErrorRef,
					role: 'alert',
					tabIndex: -1
				},
				'El retiro en Punto Blue Express no admite pedidos divididos. Elige un envío a domicilio para continuar.'
			);
		}

		var packageCards = packages.map(function (packageData, index) {
			var selected = selectedPudoPackages([packageData]).length === 1;
			if (!selected) {
				return null;
			}
			var packageId = String(packageData.package_id === undefined ? index : packageData.package_id);
			var packageSlug = packageId.replace(/[^A-Za-z0-9_-]/g, '-');
			var headingId = 'akb-pudo-package-title-' + packageSlug;
			var helpId = 'akb-pudo-package-help-' + packageSlug;
			var errorId = 'akb-pudo-package-error-' + packageSlug;
			var selectionId = 'akb-pudo-package-selection-' + packageSlug;
			var descriptionId = agencyId ? selectionId : helpId + ' ' + errorId;

			return createElement(
				'section',
				{
					'aria-labelledby': headingId,
					className: 'akb-pudo-package',
					'data-package-id': packageId,
					key: packageId
				},
				createElement('h3', { id: headingId }, 'Punto de retiro Blue Express'),
				agencyId
					? createElement(
						'div',
						{
							'aria-atomic': 'true',
							'aria-live': 'polite',
							className: 'akb-pudo-selection',
							id: selectionId,
							role: 'status'
						},
						createElement('strong', null, agencyName || 'Punto Blue Express seleccionado'),
						agencyAddress ? createElement('span', null, agencyAddress) : null
					)
					: createElement(
						'p',
						{
							className: 'akb-pudo-package__hint',
							id: helpId
						},
						'Selecciona el punto donde retirarás tu pedido antes de continuar.'
					),
				!agencyId
					? createElement(
						'p',
						{
							'aria-atomic': 'true',
							/* La indicación inicial de elegir punto usa aria-live polite sin role=alert: se anuncia cuando el lector hace una pausa, sin interrumpir la edición del formulario. */
							'aria-live': 'polite',
							className: 'akb-pudo-package__validation',
							id: errorId
						},
						'Falta seleccionar el punto de retiro. Usa el botón siguiente para elegirlo.'
					)
					: null,
				createElement(
					'button',
					{
						'aria-describedby': descriptionId,
						'aria-errormessage': !agencyId ? errorId : undefined,
							'aria-invalid': !agencyId ? 'true' : 'false',
							className: 'akb-pudo-button',
							disabled: saving,
							onClick: openModal,
						ref: selectionButtonRef,
						type: 'button'
					},
					agencyId ? 'Cambiar punto de retiro' : 'Seleccionar punto de retiro'
				)
			);
		}).filter(Boolean);

		return createElement(
			Fragment,
			null,
			packageCards,
			open
				? createElement(PudoModal, {
					dialogRef: dialogRef,
					error: error,
					frameKey: frameKey,
					iframeRef: iframeRef,
					loading: loading,
					modalRef: modalRef,
					onClose: closeModal,
					onFrameLoad: function () {
						setLoading(false);
					},
					onRetry: retryFrame,
						scopeHint: scopeHint,
						retryButtonRef: retryButtonRef,
						saving: saving,
						savingStatusRef: savingStatusRef,
						savingMessage: timedOut
							? 'Blue Express aún está validando el punto. Puedes cerrar este selector y elegir otro método de envío o recargar la página.'
							: 'Validando el punto con Blue Express…',
						timedOut: timedOut,
						src: buildWidgetUrl(agencyId)
				})
				: null
		);
	}

	wp.plugins.registerPlugin('akibara-blocks-pudo', {
		render: function () {
			return createElement(
				ExperimentalOrderShippingPackages,
				null,
				createElement(PudoShippingFill, null)
			);
		},
		scope: 'woocommerce-checkout'
	});
})(window.wp, window.wc);
