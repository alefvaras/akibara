'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );

test( 'Rank Math is the only automatic table-of-contents emitter for posts', () => {
	const blogPhp = fs.readFileSync( path.join( themeRoot, 'inc/blog.php' ), 'utf8' );

	assert.doesNotMatch( blogPhp, /function\s+akibara_generate_toc\b/ );
	assert.doesNotMatch( blogPhp, /add_filter\([^;]*akibara_generate_toc/ );
	assert.doesNotMatch( blogPhp, /class=["']post-toc/ );
} );

test( 'theme enhancement targets the official Rank Math block only', () => {
	const tocJs = fs.readFileSync( path.join( themeRoot, 'assets/js/page-toc.js' ), 'utf8' );
	const blogCss = fs.readFileSync( path.join( themeRoot, 'assets/css/blog.css' ), 'utf8' );
	const pagesCss = fs.readFileSync( path.join( themeRoot, 'assets/css/pages.css' ), 'utf8' );

	assert.match( tocJs, /#rank-math-toc/ );
	assert.doesNotMatch( tocJs, /\.post-toc/ );
	assert.match( blogCss, /#rank-math-toc/ );
	assert.match( blogCss, /\.akb-post__content\s+:is\(h2,\s*h3\)\[id\]\s*\{[^}]*scroll-margin-top:/s );
	assert.doesNotMatch( blogCss, /\.post-toc/ );
	assert.doesNotMatch( pagesCss, /\.akb-post__content/ );
} );
