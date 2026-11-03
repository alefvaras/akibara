'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const themeRoot = path.resolve( __dirname, '..' );
const read = ( relative ) => fs.readFileSync(
	path.join( themeRoot, relative ),
	'utf8'
);

test( 'single posts render the editorial featured-image alt with the shared fallback', () => {
	const single = read( 'single.php' );
	const blog = read( 'inc/blog.php' );

	assert.match(
		single,
		/'alt'\s*=>\s*akibara_blog_image_alt\(\s*\$akb_thumb_id,\s*get_the_title\(\)\s*\)/
	);
	assert.doesNotMatch( single, /'alt'\s*=>\s*get_the_title\(\)/ );
	assert.match(
		blog,
		/get_post_meta\(\s*\$attachment_id,\s*'_wp_attachment_image_alt',\s*true\s*\)/
	);
	assert.match(
		blog,
		/if\s*\(\s*\$alt\s*!==\s*''\s*\)\s*\{\s*return\s+\$alt;/
	);
} );

test( 'Rank Math integration is editor-only and uses the documented analysis API', () => {
	const functions = read( 'functions.php' );
	const integration = read( 'inc/rank-math.php' );

	assert.match( functions, /require_once\s+\$akb_inc\s*\.\s*'rank-math\.php';/ );
	assert.doesNotMatch(
		functions,
		/require_once\s+\$akb_inc\s*\.\s*'seo-filtered-urls\.php';/,
		'Akibara Business must remain the only owner of filtered URL policy'
	);
	assert.match(
		integration,
		/add_action\(\s*'enqueue_block_editor_assets',\s*'akibara_gp_enqueue_rank_math_content_analysis'/
	);
	assert.match( integration, /'post'\s*!==\s*\$screen->post_type/ );
	assert.match( integration, /defined\(\s*'RANK_MATH_VERSION'\s*\)/ );
	assert.match(
		integration,
		/array\(\s*'wp-hooks',\s*'wp-data',\s*'wp-dom-ready',\s*'rank-math-analyzer'\s*\)/
	);
} );

test( 'analysis receives the rendered featured image without mutating editor or frontend content', () => {
	const source = read( 'assets/js/rank-math-content-analysis.js' );
	let contentFilter;
	let subscriber;
	const refreshes = [];
	const editorState = {
		featured_media: 23482,
		title: 'Atelier of Witch Hat: guía del manga',
	};
	const mediaState = {
		23482: {
			alt_text: 'Atelier of Witch Hat, la obra ilustrada por Kamome Shirahama',
			source_url: 'http://localhost:8080/wp-content/uploads/final-atelier.webp',
		},
	};

	const context = {
		window: {},
		rankMathEditor: {
			refresh: ( section ) => refreshes.push( section ),
		},
		wp: {
			domReady: ( callback ) => callback(),
			hooks: {
				addFilter: ( name, namespace, callback ) => {
					assert.equal( name, 'rank_math_content' );
					assert.equal( namespace, 'akibara-gp/featured-image' );
					contentFilter = callback;
				},
			},
			data: {
				select: ( store ) => {
					if ( store === 'core/editor' ) {
						return {
							getCurrentPostType: () => 'post',
							getEditedPostAttribute: ( attribute ) =>
								editorState[ attribute ],
						};
					}

					if ( store === 'core' ) {
						return {
							getMedia: ( id ) => mediaState[ id ],
						};
					}

					throw new Error( `unexpected store: ${ store }` );
				},
				subscribe: ( callback ) => {
					subscriber = callback;
					return () => {};
				},
			},
		},
	};
	context.window.wp = context.wp;
	context.window.rankMathEditor = context.rankMathEditor;

	vm.runInNewContext( source, context );

	assert.equal( typeof contentFilter, 'function' );
	assert.equal( typeof subscriber, 'function' );
	assert.deepEqual( refreshes, [ 'content' ] );

	const original = '<p>Contenido editorial</p>';
	const analyzed = contentFilter( original );

	assert.match( analyzed, /^<p>Contenido editorial<\/p><img / );
	assert.match( analyzed, /data-akibara-rank-math-featured="1"/ );
	assert.match( analyzed, /src="http:\/\/localhost:8080\/wp-content\/uploads\/final-atelier\.webp"/ );
	assert.match(
		analyzed,
		/alt="Atelier of Witch Hat, la obra ilustrada por Kamome Shirahama"/
	);
	assert.doesNotMatch( source, /\beditPost\b|\bsavePost\b|\bdispatch\b/ );
	assert.equal( original, '<p>Contenido editorial</p>' );

	mediaState[ 23482 ].alt_text = '';
	subscriber();
	const fallback = contentFilter( original );
	assert.match( fallback, /alt="Atelier of Witch Hat: guía del manga"/ );
	assert.deepEqual( refreshes, [ 'content', 'content' ] );
} );
