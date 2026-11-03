const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );
const source = fs.readFileSync( path.join( themeRoot, 'inc/trust-badges.php' ), 'utf8' );
const css = fs.readFileSync( path.join( themeRoot, 'assets/css/footer.css' ), 'utf8' );

test( 'packaging trust badge wraps icon and label in one native link', () => {
	assert.match(
		source,
		/<div class="akb-badge-trust akb-badge-trust--linked" role="listitem">[\s\S]*?<a class="akb-badge-trust__link"[^>]*>[\s\S]*?<svg[^>]*aria-hidden="true"[^>]*focusable="false"[\s\S]*?<span>Embalaje reforzado<\/span>[\s\S]*?<\/a>[\s\S]*?<\/div>/
	);
	assert.doesNotMatch( source, /<a[^>]*role="listitem"/ );
	assert.doesNotMatch( source, /Embalaje blindado|Atención personalizada/ );
} );

test( 'packaging link fills its cell and exposes stable pointer and keyboard states', () => {
	const selector = /\.akb-product-trust-badges \.akb-badge-trust > \.akb-badge-trust__link\s*\{([^}]*)\}/;
	const base = css.match( selector );
	assert.ok( base, 'falta la regla directa del enlace de embalaje' );
	assert.match( base[ 1 ], /display:\s*flex/ );
	assert.match( base[ 1 ], /width:\s*100%/ );
	assert.match( base[ 1 ], /min-width:\s*var\(--touch-target-min\)/ );
	assert.match( base[ 1 ], /min-height:\s*var\(--touch-target-min\)/ );
	assert.match( base[ 1 ], /text-decoration:\s*none/ );
	assert.doesNotMatch( base[ 1 ], /transform\s*:/ );
	assert.match( css, /\.akb-badge-trust__link:hover\s*\{/ );
	// Foco: el anillo amarillo GLOBAL de tokens.css (`a:focus-visible`). Desde el
	// 2026-09-23 (DS-5) la regla local sólo aclara el texto; si vuelve a pintar su
	// propio outline o sombra, el sello deja de tener el foco de toda la casa.
	assert.match( css, /\.akb-badge-trust__link:focus-visible\s*\{/ );
	assert.doesNotMatch( css, /\.akb-badge-trust__link:focus-visible\s*\{[^}]*(outline|box-shadow)\s*:/s );
	assert.match( css, /\.akb-badge-trust__link:active\s*\{/ );
	assert.doesNotMatch( css, /\.akb-badge-trust__link[^}]*!important/s );
} );

test( 'mobile breakpoints preserve the two-column grid and linked-cell geometry', () => {
	assert.match(
		css,
		/\.akb-product-trust-badges \.akb-badge-trust\s*\{[^}]*box-sizing:\s*border-box/
	);
	assert.match(
		css,
		/@media \(max-width: 768px\)[\s\S]*?grid-template-columns:\s*repeat\(auto-fit, minmax\(150px, 1fr\)\)/
	);
	assert.match(
		css,
		/@media \(max-width: 480px\)[\s\S]*?\.akb-badge-trust__link\s*\{[^}]*flex-direction:\s*column/s
	);
} );
