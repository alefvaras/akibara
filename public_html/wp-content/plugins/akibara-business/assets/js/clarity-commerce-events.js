/**
 * Akibara commerce semantics for Microsoft Clarity.
 *
 * Clarity's official WordPress plugin owns `window.clarity`. Akibara's current
 * no-banner configuration does not gate analytics through a CMP. This bridge
 * emits stable WooCommerce lifecycle names plus coarse AI source/evidence
 * enums. It never sends URLs, queries, order/customer values or identifiers.
 */
(function (window, document) {
	'use strict';

	var config = window.akibaraClarityCommerce || {};
	var lastEvent = '';
	var orderEventsSent = {};
	var lastEventAt = 0;
	var aiStorageKey = 'akb_ai_attribution';
	var aiActivityTtl = 1800000;
	var aiActivityWriteInterval = 60000;
	var aiTaggedSource = '';
	var aiTaggedEvidence = '';
	var volatileAiRecord = null;
	var volatileAiRecordPendingStorage = false;
	var nextAiActivityWriteAt = 0;
	var aiSources = [ 'chatgpt', 'copilot', 'perplexity', 'claude', 'gemini' ];
	var aiUtmSources = {
		chatgpt: 'chatgpt',
		'chatgpt.com': 'chatgpt',
		copilot: 'copilot',
		'copilot.microsoft.com': 'copilot',
		perplexity: 'perplexity',
		'perplexity.ai': 'perplexity',
		claude: 'claude',
		'claude.ai': 'claude',
		gemini: 'gemini',
		'gemini.google.com': 'gemini'
	};
	var aiHosts = {
		chatgpt: [ 'chatgpt.com', 'chat.openai.com' ],
		copilot: [ 'copilot.microsoft.com' ],
		perplexity: [ 'perplexity.ai' ],
		claude: [ 'claude.ai' ],
		gemini: [ 'gemini.google.com' ]
	};

	function isAllowedAiSource(source) {
		return aiSources.indexOf(source) !== -1;
	}

	function copyAiRecord(record) {
		return {
			source: record.source,
			evidence: record.evidence,
			lastActivityAt: record.lastActivityAt,
			landingAt: record.landingAt,
			landingSentAt: record.landingSentAt
		};
	}

	function isValidTimestamp(value, now) {
		return typeof value === 'number'
			&& isFinite(value)
			&& Math.floor(value) === value
			&& value >= 0
			&& value <= now;
	}

	function isValidAiRecord(record, now) {
		var keys;

		if (!record || Object.prototype.toString.call(record) !== '[object Object]') {
			return false;
		}

		keys = Object.keys(record).sort();
		if (
			keys.length !== 5
			|| keys.join(',') !== 'evidence,landingAt,landingSentAt,lastActivityAt,source'
		) {
			return false;
		}

		if (
			!isAllowedAiSource(record.source)
			|| (record.evidence !== 'referrer' && record.evidence !== 'utm')
			|| !isValidTimestamp(record.lastActivityAt, now)
			|| !isValidTimestamp(record.landingAt, now)
			|| !isValidTimestamp(record.landingSentAt, now)
			|| record.landingAt === 0
			|| record.lastActivityAt < record.landingAt
			|| record.landingSentAt > record.landingAt
		) {
			return false;
		}

		return true;
	}

	function removeStoredAiRecord() {
		volatileAiRecord = null;
		volatileAiRecordPendingStorage = false;

		try {
			window.sessionStorage.removeItem(aiStorageKey);
		} catch (error) {
			// Storage may be unavailable in restricted browser contexts.
		}
	}

	function readAiRecord(now) {
		var parsed;
		var raw;

		try {
			raw = window.sessionStorage.getItem(aiStorageKey);
		} catch (error) {
			return volatileAiRecord && isValidAiRecord(volatileAiRecord, now)
				? copyAiRecord(volatileAiRecord)
				: null;
		}

		if (!raw) {
			if (
				volatileAiRecordPendingStorage
				&& volatileAiRecord
				&& isValidAiRecord(volatileAiRecord, now)
			) {
				return copyAiRecord(volatileAiRecord);
			}

			volatileAiRecord = null;
			volatileAiRecordPendingStorage = false;
			return null;
		}

		try {
			parsed = JSON.parse(raw);
		} catch (error) {
			removeStoredAiRecord();
			return null;
		}

		if (!isValidAiRecord(parsed, now)) {
			removeStoredAiRecord();
			return null;
		}

		volatileAiRecord = copyAiRecord(parsed);
		volatileAiRecordPendingStorage = false;
		return copyAiRecord(parsed);
	}

	function writeAiRecord(record, now) {
		if (!isValidAiRecord(record, now)) {
			removeStoredAiRecord();
			return false;
		}

		volatileAiRecord = copyAiRecord(record);

		try {
			window.sessionStorage.setItem(aiStorageKey, JSON.stringify(volatileAiRecord));
		} catch (error) {
			volatileAiRecordPendingStorage = true;
			return false;
		}

		volatileAiRecordPendingStorage = false;
		return true;
	}

	function readFreshAiRecord(now) {
		var record = readAiRecord(now);

		if (!record) {
			return null;
		}

		if (now - record.lastActivityAt >= aiActivityTtl) {
			removeStoredAiRecord();
			return null;
		}

		return record;
	}

	function safeHostname(value) {
		var parsed;

		if (!value || typeof window.URL !== 'function') {
			return '';
		}

		try {
			parsed = new window.URL(value);
		} catch (error) {
			return '';
		}

		if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
			return '';
		}

		return String(parsed.hostname || '').toLowerCase();
	}

	function hostnameMatches(hostname, allowedHostname) {
		return hostname === allowedHostname
			|| hostname.slice(-(allowedHostname.length + 1)) === '.' + allowedHostname;
	}

	function aiSourceFromHostname(hostname) {
		var sourceIndex;
		var hostIndex;

		if (!hostname) {
			return '';
		}

		for (sourceIndex = 0; sourceIndex < aiSources.length; sourceIndex++) {
			for (hostIndex = 0; hostIndex < aiHosts[aiSources[sourceIndex]].length; hostIndex++) {
				if (hostnameMatches(hostname, aiHosts[aiSources[sourceIndex]][hostIndex])) {
					return aiSources[sourceIndex];
				}
			}
		}

		return '';
	}

	function aiSourceFromUtm() {
		var params;
		var value;

		if (typeof window.URLSearchParams !== 'function') {
			return '';
		}

		try {
			params = new window.URLSearchParams(window.location.search || '');
			value = String(params.get('utm_source') || '').trim().toLowerCase();
		} catch (error) {
			return '';
		}

		return Object.prototype.hasOwnProperty.call(aiUtmSources, value)
			? aiUtmSources[value]
			: '';
	}

	function isReloadNavigation() {
		var entries;

		try {
			if (window.performance && typeof window.performance.getEntriesByType === 'function') {
				entries = window.performance.getEntriesByType('navigation');
				if (entries && entries[0]) {
					return entries[0].type === 'reload';
				}
			}

			return Boolean(
				window.performance
				&& window.performance.navigation
				&& window.performance.navigation.type === 1
			);
		} catch (error) {
			return false;
		}
	}

	function isInternalReferrer() {
		var currentHostname = String(window.location.hostname || '').toLowerCase();
		var referrerHostname = safeHostname(document.referrer);

		if (!currentHostname || !referrerHostname) {
			return false;
		}

		return currentHostname.replace(/^www\./, '') === referrerHostname.replace(/^www\./, '');
	}

	function createAiLanding(source, evidence, now) {
		var record = {
			source: source,
			evidence: evidence,
			lastActivityAt: now,
			landingAt: now,
			landingSentAt: 0
		};
		writeAiRecord(record, now);

		return record;
	}

	function resolveAiAttribution() {
		var now = Date.now();
		var reload = isReloadNavigation();
		var internal = isInternalReferrer();
		var referrerSource = aiSourceFromHostname(safeHostname(document.referrer));
		var utmSource = aiSourceFromUtm();
		var previousRecord = (reload || internal) ? readFreshAiRecord(now) : null;

		// An official AI referrer is stronger evidence than a conflicting UTM.
		if (!reload && referrerSource) {
			return createAiLanding(referrerSource, 'referrer', now);
		}

		if (!reload && !internal && utmSource) {
			return createAiLanding(utmSource, 'utm', now);
		}

		if ((reload || internal) && previousRecord) {
			previousRecord.lastActivityAt = now;
			writeAiRecord(previousRecord, now);
			return previousRecord;
		}

		removeStoredAiRecord();
		return null;
	}

	function markAiLandingSent(targetLandingAt) {
		var now = Date.now();
		var latest = readFreshAiRecord(now);

		if (
			!latest
			|| latest.landingAt !== targetLandingAt
			|| latest.landingSentAt === latest.landingAt
		) {
			return;
		}

		latest.landingSentAt = latest.landingAt;
		writeAiRecord(latest, now);
	}

	function scheduleAiRetry(targetLandingAt, pendingAtStart, attempt) {
		if (attempt >= 20) {
			return;
		}

		window.setTimeout(function () {
			emitAiAttribution(targetLandingAt, pendingAtStart, attempt + 1);
		}, 250);
	}

	function emitAiAttribution(targetLandingAt, pendingAtStart, attempt) {
		var now = Date.now();
		var record = readFreshAiRecord(now);
		var pending;

		// Re-read shared state on every attempt. A newer document may have
		// replaced this landing or completed the same pending event already.
		if (!record || record.landingAt !== targetLandingAt) {
			return;
		}

		pending = record.landingSentAt !== record.landingAt;
		if (pendingAtStart && !pending && !aiTaggedSource) {
			return;
		}

		if (typeof window.clarity !== 'function') {
			scheduleAiRetry(targetLandingAt, pendingAtStart, attempt);
			return;
		}

		try {
			if (aiTaggedSource !== record.source) {
				window.clarity('set', 'akb_ai_source', record.source);
				aiTaggedSource = record.source;
			}

			if (aiTaggedEvidence !== record.evidence) {
				window.clarity('set', 'akb_ai_evidence', record.evidence);
				aiTaggedEvidence = record.evidence;
			}

			if (pending) {
				window.clarity('event', 'ai_referral_landing');
				markAiLandingSent(targetLandingAt);
			}
		} catch (error) {
			scheduleAiRetry(targetLandingAt, pendingAtStart, attempt);
		}
	}

	function recordAiActivity() {
		var now = Date.now();
		var record;

		if (now < nextAiActivityWriteAt) {
			return;
		}

		record = readFreshAiRecord(now);

		if (!record || now - record.lastActivityAt < aiActivityWriteInterval) {
			nextAiActivityWriteAt = record
				? record.lastActivityAt + aiActivityWriteInterval
				: now + aiActivityWriteInterval;
			return;
		}

		record.lastActivityAt = now;
		writeAiRecord(record, now);
		nextAiActivityWriteAt = now + aiActivityWriteInterval;
	}

	function listenForAiActivity(record) {
		nextAiActivityWriteAt = record.lastActivityAt + aiActivityWriteInterval;

		[ 'pointerdown', 'keydown', 'touchstart', 'scroll' ].forEach(function (eventName) {
			document.addEventListener(eventName, recordAiActivity, {
				capture: eventName === 'scroll',
				passive: eventName !== 'keydown'
			});
		});
	}

	function emit(name, attempt) {
		if (!name) {
			return;
		}

		if (typeof window.clarity !== 'function') {
			if (attempt < 20) {
				window.setTimeout(function () {
					emit(name, attempt + 1);
				}, 250);
			}
			return;
		}

		window.clarity('event', name);
	}

	// The token is an opaque server HMAC, used only in this tab's storage. Never
	// forward it (or order/customer data) to Clarity. This is best-effort session
	// deduplication, not an exactly-once payment ledger across devices.
	function emitOrderEvent(name, attempt) {
		var token = config.orderToken;
		var key;
		if ((name !== 'order_created' && name !== 'purchase') || typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token)) {
			return;
		}
		key = 'akb_clarity_order:' + token + ':' + name;
		if (orderEventsSent[key]) {
			return;
		}
		try {
			if (window.sessionStorage.getItem(key) === '1') {
				return;
			}
		} catch (error) {
			// Blocked storage still permits one event in the current document.
		}
		try {
			if (typeof window.clarity !== 'function') {
				throw new Error('Clarity is not ready');
			}
			window.clarity('event', name);
		} catch (error) {
			if (attempt < 20) {
				window.setTimeout(function () {
					emitOrderEvent(name, attempt + 1);
				}, 250);
			}
			return;
		}
		orderEventsSent[key] = true;
		try {
			window.sessionStorage.setItem(key, '1');
		} catch (error) {
			// No persistent storage is required for checkout to work.
		}
		if (name === 'purchase') {
			try {
				window.clarity('upgrade', 'purchase');
			} catch (error) {
				// Recording prioritization must not retry a purchase already sent.
			}
		}
	}

	function tagInternalTraffic(type, attempt) {
		try {
			if (typeof window.clarity !== 'function') {
				throw new Error('Clarity is not ready');
			}
			window.clarity('set', 'akb_traffic_type', type);
		} catch (error) {
			if (attempt < 20) {
				window.setTimeout(function () {
					tagInternalTraffic(type, attempt + 1);
				}, 250);
			}
		}
	}

	function track(name) {
		var now = Date.now();

		// Woo Blocks may translate the classic jQuery add-to-cart event into a
		// native event. Collapse that pair into one analytical conversion.
		if (name === lastEvent && now - lastEventAt < 750) {
			return;
		}

		lastEvent = name;
		lastEventAt = now;
		emit(name, 0);
	}

	function trackAddToCart() {
		track('add_to_cart');
	}

	function trackWishlistChange(event) {
		if (event && event.detail && event.detail.state === 'added') {
			track('wishlist_add');
		}
	}

	function trackPaymentMethod(event) {
		var target = event && event.target;
		if (!target || !target.matches) {
			return;
		}

		if (
			target.matches('input[name="payment_method"]')
			|| target.matches('input[name="radio-control-wc-payment-method-options"]')
		) {
			track('payment_method_selected');
		}
	}

	function cartCouponCodes(cart) {
		var cartData = typeof cart.getCartData === 'function' ? cart.getCartData() : null;
		var coupons = cartData && Array.isArray(cartData.coupons) ? cartData.coupons : [];

		return coupons.map(function (coupon) {
			return String(coupon && coupon.code || '');
		});
	}

	function hasNewCoupon(before, after) {
		return after.some(function (code) {
			return before.indexOf(code) === -1;
		});
	}

	/**
	 * Lee cupón aplicado y error de checkout desde wp.data de WooCommerce Blocks. Compara
	 * conjuntos al terminar isApplyingCoupon para detectar sustituciones, omitiendo rechazos y
	 * cupones agregados por servidor fuera de esa acción. hasError se mide por flanco de subida
	 * con el antirrebote de track para fundir varias subidas del mismo intento. Resuelve stores en
	 * cada notificación por su registro tardío; sin wp.data no se suscribe. Los códigos y textos
	 * de error no se envían a Clarity, sólo los nombres de evento.
	 */
	function observeBlocksCommerce() {
		var data = window.wp && window.wp.data;
		var wasApplyingCoupon = false;
		var couponsBeforeApply = [];
		var hadCheckoutError = false;

		if (!data || typeof data.subscribe !== 'function' || typeof data.select !== 'function') {
			return;
		}

		data.subscribe(function () {
			var cart = data.select('wc/store/cart');
			var checkout = data.select('wc/store/checkout');
			var applying;
			var failed;

			if (cart && typeof cart.isApplyingCoupon === 'function') {
				applying = !!cart.isApplyingCoupon();
				if (applying && !wasApplyingCoupon) {
					couponsBeforeApply = cartCouponCodes(cart);
				} else if (
					!applying
					&& wasApplyingCoupon
					&& hasNewCoupon(couponsBeforeApply, cartCouponCodes(cart))
				) {
					track('coupon_applied');
				}
				wasApplyingCoupon = applying;
			}

			if (checkout && typeof checkout.hasError === 'function') {
				failed = !!checkout.hasError();
				if (failed && !hadCheckoutError) {
					track('checkout_error');
				}
				hadCheckoutError = failed;
			}
		});
	}

	function start() {
		var aiRecord;
		if (config.internalTraffic === 'qa' || config.internalTraffic === 'staff') {
			tagInternalTraffic(config.internalTraffic, 0);
			return;
		}
		aiRecord = resolveAiAttribution();

		if (aiRecord) {
			emitAiAttribution(
				aiRecord.landingAt,
				aiRecord.landingSentAt !== aiRecord.landingAt,
				0
			);
			listenForAiActivity(aiRecord);
		}
		// Receipt events only come from the validated server contract. Old cached
		// initialEvent=purchase configurations must not restore the former URL gate.
		if (config.initialEvent === 'view_product' || config.initialEvent === 'begin_checkout') {
			track(config.initialEvent);
		}
		if (Array.isArray(config.orderEvents)) {
			config.orderEvents.forEach(function (name) {
				emitOrderEvent(name, 0);
			});
		}
		document.body.addEventListener('akb:added_to_cart', trackAddToCart);
		document.body.addEventListener('wc-blocks_added_to_cart', trackAddToCart);
		document.body.addEventListener('akb:commerce:add_to_cart', trackAddToCart);
		document.body.addEventListener('akb:removed_from_cart', function () {
			track('remove_from_cart');
		});
		document.body.addEventListener('wc-blocks_removed_from_cart', function () {
			track('remove_from_cart');
		});
		observeBlocksCommerce();
		document.addEventListener('akb:wishlist:change', trackWishlistChange);
		document.addEventListener('change', trackPaymentMethod);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})(window, document);
