const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );

test( 'footer describes an online store without publishing a walk-in location', () => {
	const source = fs.readFileSync( path.join( themeRoot, 'template-parts/footer/main.php' ), 'utf8' );
	const iconLibrary = fs.readFileSync( path.join( themeRoot, 'inc/icons.php' ), 'utf8' );
	const siteCopy = fs.readFileSync( path.join( themeRoot, 'inc/site-copy.php' ), 'utf8' );

	assert.match( source, /Tienda 100% online · Sin local abierto al público/ );
	assert.match( source, /Retiro de pedidos con coordinación previa en San Miguel/ );
	assert.match( source, /Envíos a todo Chile/ );
	assert.doesNotMatch( source, /envío nacional/i );
	// El horario dejó de ser un literal de la plantilla: se edita en Akibara →
	// Textos → «Pie del sitio». Lo que se fija es que el pie lo pida al helper y
	// que el valor de fábrica siga siendo el mismo.
	assert.match( source, /akibara_gp_footer_support_hours\(\)/ );
	assert.match( siteCopy, /Horario de soporte: Lun a Vie/ );
	assert.match( source, /akibara_gp_icon\(\s*'cart'\s*,/ );
	assert.match( source, /akibara_gp_icon\(\s*'package'\s*,/ );
	assert.doesNotMatch( source, /akibara_gp_icon\(\s*'map-pin'\b/ );
	assert.match( iconLibrary, /data-akibara-icon/ );
	assert.doesNotMatch( source, /(?:maps\.google\.|google\.[^/]+\/maps|maps\.apple\.com|(?:www\.)?waze\.(?:com|to)|(?:www\.)?bing\.com\/maps|maps\.bing\.com|openstreetmap\.org)/i );
	assert.doesNotMatch( source, /<address\b/i );
} );

test( 'WooCommerce email footer never expands the private store address', () => {
	const source     = fs.readFileSync( path.join( themeRoot, 'woocommerce/emails/email-footer.php' ), 'utf8' );
	// S-e9309aea (2026-09-19): la línea pública ya no vive duplicada como
	// literal en esta plantilla, así que su TEXTO se comprueba en la fuente
	// única (`inc/woocommerce.php`); esta plantilla sólo se comprueba por que
	// LLAME a esa fuente (más abajo).
	const woocommerce = fs.readFileSync( path.join( themeRoot, 'inc/woocommerce.php' ), 'utf8' );

	assert.match( woocommerce, /Sin local abierto al público/ );
	assert.match( woocommerce, /Retiro con coordinación previa en San Miguel/ );
	assert.match( source, /\$footer_text\s+=\s+\$store_online_line/ );
	// S-e9309aea (2026-09-19): fuente única. `$store_online_line` tiene que
	// venir de LLAMAR a `akibara_gp_email_footer_public_line()`
	// (`inc/woocommerce.php`), no de repetir el literal — o un cambio futuro
	// que edite un solo fichero vuelve a dejar el correo diciendo dos cosas
	// distintas en HTML y en texto plano.
	assert.match( source, /\$store_online_line\s*=\s*akibara_gp_email_footer_public_line\(\)\s*;/ );
	assert.doesNotMatch(
		source,
		/\$store_online_line\s*=\s*'Tienda 100% online/,
		'la línea pública no puede volver a estar duplicada como literal en este fichero'
	);
	assert.doesNotMatch( source, /get_option\(\s*'woocommerce_email_footer_text'/ );
	assert.doesNotMatch( source, /\{store_address\}|woocommerce_store_address|streetAddress|postalCode/i );
	assert.doesNotMatch( source, /akibara_gp_icon\(\s*'map-pin'\b|data-akibara-icon=["']map-pin["']/i );
	assert.doesNotMatch( source, /(?:maps\.google\.|google\.[^/]+\/maps|maps\.apple\.com|(?:www\.)?waze\.(?:com|to)|(?:www\.)?bing\.com\/maps|maps\.bing\.com|openstreetmap\.org)/i );
	assert.doesNotMatch( source, /<address\b/i );
} );

test( 'the configurable WooCommerce email footer option can never reach a plain-text email either', () => {
	// REAPERTURA S-e9309aea (2026-09-19): el test de arriba sólo mira
	// `email-footer.php` (la mitad HTML). Las tres plantillas de texto plano
	// del tema leen `apply_filters( 'woocommerce_email_footer_text',
	// get_option( 'woocommerce_email_footer_text' ) )` tal cual, así que la
	// mitad plana del MISMO correo podía servir la dirección operativa
	// expandida ({site_title}<br />{store_address}) mientras la mitad HTML no.
	// El cierre no toca esas tres plantillas: engancha un filtro a prioridad
	// 20 (después de que WC expanda los placeholders a prioridad 10) que
	// descarta el valor de la opción sea cual sea, así que también cierra la
	// misma fuga en las ~18 plantillas planas de WooCommerce core que el tema
	// no sobrescribe.
	const source = fs.readFileSync( path.join( themeRoot, 'inc/woocommerce.php' ), 'utf8' );

	// Anclado a inicio de línea (con sangría opcional) para que un `//` de
	// comentario delante no cuele como «registrado».
	assert.match(
		source,
		/^\s*add_filter\(\s*'woocommerce_email_footer_text',\s*'akibara_gp_email_footer_public_line',\s*20\s*\);/m
	);
	assert.match( source, /function akibara_gp_email_footer_public_line\(\)/ );
	// La FUNCIÓN devuelve el literal fijo, sin tocar ninguna variable de la
	// opción — se comprueba por su cuerpo, no por ausencia textual de
	// "{store_address}" en el fichero entero: el docblock de arriba EXPLICA el
	// hallazgo citando ese mismo placeholder a propósito.
	const cuerpo_funcion = source.match( /function akibara_gp_email_footer_public_line\(\):\s*string\s*\{([^}]*)\}/ );
	assert.ok( cuerpo_funcion, 'no se encontró el cuerpo de akibara_gp_email_footer_public_line()' );
	assert.doesNotMatch( cuerpo_funcion[ 1 ], /\{store_address\}|get_option|apply_filters|woocommerce_store_address|streetAddress|postalCode/i );
	// El filtro anterior en el mismo hook quedó sin consumidor: cualquier
	// salto de línea que insertara sobre la opción lo descartaba de todos
	// modos el filtro de prioridad 20. Cero código muerto — puede seguir
	// mencionado en un comentario que explica por qué se retiró, pero ni la
	// función ni su `add_filter()` pueden sobrevivir.
	assert.doesNotMatch( source, /function\s+akibara_gp_email_footer_line_breaks\s*\(/ );
	assert.doesNotMatch( source, /add_filter\(\s*'woocommerce_email_footer_text',\s*'akibara_gp_email_footer_line_breaks'/ );
} );
