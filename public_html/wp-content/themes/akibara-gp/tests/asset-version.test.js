import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const enqueue = readFileSync(
	new URL( '../inc/enqueue.php', import.meta.url ),
	'utf8'
);
const stylesheet = readFileSync(
	new URL( '../style.css', import.meta.url ),
	'utf8'
);
const functions = readFileSync(
	new URL( '../functions.php', import.meta.url ),
	'utf8'
);
// Antes esto clavaba un literal ('2.1.96'), así que el candado quedaba ROJO
// desde el primer bump y dejaba de frenar nada — el mismo defecto que ya dejó
// pasar dos releases del buscador. Lo que importa no es QUÉ versión declara el
// tema, sino que declare una con forma semver y que enqueue derive el `?ver=`
// de ella más el mtime del archivo. Se puede seguir exigiendo una versión
// concreta pasando AKIBARA_EXPECTED_THEME_VERSION.
const pinnedThemeVersion = process.env.AKIBARA_EXPECTED_THEME_VERSION || '';

test( 'asset versions follow the child-theme UI version and individual file mtime', () => {
	const declared = stylesheet.match( /^Version:\s+(\S+)$/m );
	assert.ok( declared, 'style.css debe declarar una cabecera Version.' );
	assert.match( declared[ 1 ], /^\d+\.\d+\.\d+$/ );
	if ( pinnedThemeVersion ) {
		assert.equal( declared[ 1 ], pinnedThemeVersion );
	}
	assert.match( enqueue, /wp_get_theme\(\)->get\( 'Version' \)/ );
	assert.match( enqueue, /is_file\( \$asset_path \) \? filemtime\( \$asset_path \) : false/ );
	assert.match( enqueue, /\$theme_version \. '\.' \. \$modified/ );
	// functions.php encola por el mismo camino que enqueue.php: la copia servida
	// (`.min` salvo SCRIPT_DEBUG) con el `?ver` de ESA copia.
	assert.match( functions, /akibara_gp_asset\( 'assets\/css\/reviews-p5-v4\.css' \)/ );
	assert.match( enqueue, /akibara_gp_asset_ver\( \$servido \)/ );
	assert.doesNotMatch( functions, /\bfilemtime\(/ );
	// Aquí había un `doesNotMatch( enqueue, /2\.1\.30\./ )`: una versión de 2026
	// que el tema dejó atrás hace ~80 releases y que, por tanto, no podía volver
	// a fallar — exactamente el defecto que este mismo archivo ya había corregido
	// unas líneas más arriba al desclavar la versión esperada. La CONDUCTA (el
	// token sale de la versión más el mtime, cambia cuando cambia el fichero y
	// degrada a la versión pelada si el fichero falta) se ejecuta de verdad en
	// tests/AssetVersionTest.php.
});
