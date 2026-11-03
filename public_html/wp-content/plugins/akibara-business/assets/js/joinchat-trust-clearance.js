/**
 * Hides Joinchat only while its fixed mobile button physically covers the
 * home trust grid.
 *
 * A static offset is unsafe here: at the initial viewport it avoids the trust
 * card, but after a short scroll it moves the button over the hero CTA. The
 * observer root is cropped to the button's live rectangle, so its intersection
 * state follows both responsive layout and Joinchat's delayed appearance.
 */
( function ( window, document ) {
	'use strict';

	var BREAKPOINT = 768;
	var MAX_GEOMETRY_RETRIES = 8;
	var OVERLAP_CLASS = 'akb-joinchat--trust-overlap';
	var initialized = false;

	function rectanglesOverlap( first, second ) {
		return (
			first.left < second.right
			&& first.right > second.left
			&& first.top < second.bottom
			&& first.bottom > second.top
		);
	}

	function init() {
		var trustSection = document.querySelector( '.akb-trust-section' );
		var joinchat = document.querySelector( '.joinchat' );
		var button = joinchat && joinchat.querySelector( '.joinchat__button' );

		if (
			initialized
			|| ! trustSection
			|| ! joinchat
			|| ! button
			|| ! ( 'IntersectionObserver' in window )
		) {
			return;
		}

		initialized = true;

		var observer = null;
		var animationFrame = 0;
		var geometryRetryCount = 0;
		var lastJoinchatState = '';

		function joinchatState() {
			return [
				joinchat.hidden ? 'hidden' : 'visible',
				joinchat.classList.contains( 'joinchat--show' ) ? 'shown' : 'not-shown',
				joinchat.classList.contains( 'joinchat--chatbox' ) ? 'open' : 'closed'
			].join( ':' );
		}

		function interactionIsActive() {
			return (
				joinchat.classList.contains( 'joinchat--chatbox' )
				|| joinchat.contains( document.activeElement )
			);
		}

		function setOverlapState( overlaps ) {
			joinchat.classList.toggle(
				OVERLAP_CLASS,
				Boolean( overlaps ) && ! interactionIsActive()
			);
		}

		function disconnectObserver() {
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
		}

		function rebuildObserver() {
			var buttonRect;
			var trustRect;
			var viewportWidth;
			var viewportHeight;
			var left;
			var right;
			var top;
			var bottom;
			var rootMargin;

			animationFrame = 0;
			disconnectObserver();

			if (
				window.innerWidth > BREAKPOINT
				|| interactionIsActive()
				|| joinchat.hidden
				|| ! joinchat.classList.contains( 'joinchat--show' )
			) {
				geometryRetryCount = 0;
				setOverlapState( false );
				return;
			}

			buttonRect = button.getBoundingClientRect();
			trustRect = trustSection.getBoundingClientRect();
			viewportWidth = window.innerWidth;
			viewportHeight = window.innerHeight;

			left = Math.max( 0, buttonRect.left );
			right = Math.min( viewportWidth, buttonRect.right );
			top = Math.max( 0, buttonRect.top );
			bottom = Math.min( viewportHeight, buttonRect.bottom );

			if (
				buttonRect.width <= 0
				|| buttonRect.height <= 0
				|| right <= left
				|| bottom <= top
			) {
				setOverlapState( false );

				// Joinchat can add `joinchat--show` one or two layout frames
				// before its fixed button receives a non-zero box. Retry only
				// during that bounded transition; never poll indefinitely.
				if ( geometryRetryCount < MAX_GEOMETRY_RETRIES ) {
					++geometryRetryCount;
					scheduleRebuild();
				}
				return;
			}

			geometryRetryCount = 0;

			// Apply the measured state immediately; IntersectionObserver then
			// keeps it synchronized without scroll handlers or layout polling.
			setOverlapState( rectanglesOverlap( buttonRect, trustRect ) );

			rootMargin = [
				-top,
				-( viewportWidth - right ),
				-( viewportHeight - bottom ),
				-left
			].map( function ( value ) {
				return value.toFixed( 2 ) + 'px';
			} ).join( ' ' );

			observer = new window.IntersectionObserver(
				function ( entries ) {
					setOverlapState( entries.some( function ( entry ) {
						return entry.isIntersecting;
					} ) );
				},
				{
					root: null,
					rootMargin: rootMargin,
					threshold: 0
				}
			);
			observer.observe( trustSection );
		}

		function scheduleRebuild() {
			if ( animationFrame ) {
				window.cancelAnimationFrame( animationFrame );
			}
			animationFrame = window.requestAnimationFrame( rebuildObserver );
		}

		function revealForInteraction() {
			setOverlapState( false );
		}

		function handleJoinchatMutation() {
			var nextJoinchatState = joinchatState();

			// Toggling OVERLAP_CLASS also changes the `class` attribute. Ignore
			// that mutation unless one of Joinchat's own lifecycle classes (or
			// its hidden state) changed at the same time.
			if ( nextJoinchatState === lastJoinchatState ) {
				return;
			}

			lastJoinchatState = nextJoinchatState;
			geometryRetryCount = 0;
			scheduleRebuild();
		}

		window.addEventListener( 'resize', scheduleRebuild, { passive: true } );
		document.addEventListener( 'joinchat:show', revealForInteraction );
		document.addEventListener( 'joinchat:hide', scheduleRebuild );
		joinchat.addEventListener( 'focusin', revealForInteraction );
		joinchat.addEventListener( 'focusout', scheduleRebuild );

		// Depending on optimization/timing, Joinchat may add `joinchat--show`
		// after the frame scheduled by `hidden`. Watch both official lifecycle
		// signals and compare their normalized state so our own class cannot
		// trigger a rebuild loop.
		if ( 'MutationObserver' in window ) {
			lastJoinchatState = joinchatState();
			new window.MutationObserver( handleJoinchatMutation ).observe(
				joinchat,
				{
					attributes: true,
					attributeFilter: [ 'hidden', 'class' ]
				}
			);
		}

		// Joinchat's responsive `--s` variable changes the measured button size.
		if ( 'ResizeObserver' in window ) {
			new window.ResizeObserver( scheduleRebuild ).observe( button );
		}

		scheduleRebuild();
	}

	// `joinchat:start` is the official signal emitted after the plugin binds its
	// markup. It covers optimized footer ordering where DOMContentLoaded can run
	// before the Joinchat node/lifecycle is ready.
	document.addEventListener( 'joinchat:start', init, { once: true } );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}( window, document ) );
