( function ( wp, window ) {
	'use strict';

	var previousMarkup = '';

	function escapeAttribute( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function plainText( value ) {
		return String( value || '' )
			.replace( /<[^>]*>/g, '' )
			.trim();
	}

	function featuredImageMarkup() {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor || editor.getCurrentPostType() !== 'post' ) {
			return '';
		}

		var attachmentId = Number(
			editor.getEditedPostAttribute( 'featured_media' )
		);
		if ( ! attachmentId ) {
			return '';
		}

		var mediaStore = wp.data.select( 'core' );
		var media = mediaStore && mediaStore.getMedia( attachmentId );
		if ( ! media || ! media.source_url ) {
			return '';
		}

		var alt = plainText( media.alt_text );
		if ( ! alt ) {
			alt = plainText( editor.getEditedPostAttribute( 'title' ) );
		}

		if ( ! alt ) {
			return '';
		}

		return '<img data-akibara-rank-math-featured="1" src="'
			+ escapeAttribute( media.source_url )
			+ '" alt="'
			+ escapeAttribute( alt )
			+ '">';
	}

	wp.hooks.addFilter(
		'rank_math_content',
		'akibara-gp/featured-image',
		function ( content ) {
			var markup = featuredImageMarkup();
			return markup ? content + markup : content;
		}
	);

	wp.domReady( function () {
		previousMarkup = featuredImageMarkup();

		if (
			window.rankMathEditor
			&& typeof window.rankMathEditor.refresh === 'function'
		) {
			window.rankMathEditor.refresh( 'content' );
		}

		wp.data.subscribe( function () {
			var markup = featuredImageMarkup();
			if ( markup === previousMarkup ) {
				return;
			}

			previousMarkup = markup;
			if (
				window.rankMathEditor
				&& typeof window.rankMathEditor.refresh === 'function'
			) {
				window.rankMathEditor.refresh( 'content' );
			}
		} );
	} );
}( window.wp, window ) );
