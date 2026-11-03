const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const themeRoot = path.resolve(__dirname, '..');
const cartHooks = fs.readFileSync(path.join(themeRoot, 'inc/woocommerce.php'), 'utf8');
const emptyCart = fs.readFileSync(
  path.join(themeRoot, 'woocommerce/cart/cart-empty.php'),
  'utf8'
);
const bottomNav = fs.readFileSync(
  path.join(themeRoot, 'template-parts/footer/bottom-nav.php'),
  'utf8'
);

// Las fuentes del plugin hermano se leen DENTRO del test, no en el cuerpo del
// módulo: leerlas acá arriba mataba el archivo entero con un ENOENT crudo
// cuando el árbol no trae `plugins/` (gates de artefacto), arrastrando incluso
// las aserciones puramente locales del theme. Mismo patrón que ya usa
// visual-polish.test.js para este acople.
const blocksRoot = path.resolve(
  themeRoot,
  '../../plugins/akibara-cart-checkout-blocks/src'
);
const blocksCompositionFile = path.join(blocksRoot, 'Plugin.php');
const blocksShortcodesFile = path.join(blocksRoot, 'Shortcodes.php');

test('the blocks compatibility plugin remains the only cart H1 emitter', (t) => {
  if (
    !fs.existsSync(blocksCompositionFile) ||
    !fs.existsSync(blocksShortcodesFile)
  ) {
    t.skip(
      'árbol sin plugins/ (gate de artefacto): el acople theme↔checkout no es evaluable aquí'
    );
    return;
  }

  const blocksComposition = fs.readFileSync(blocksCompositionFile, 'utf8');
  const blocksShortcodes = fs.readFileSync(blocksShortcodesFile, 'utf8');

  assert.match(
    blocksComposition,
    /\(\s*new\s+Shortcodes\(\)\s*\)->register\(\)/
  );
  assert.match(
    blocksShortcodes,
    /add_shortcode\(\s*'akibara_cart_blocks_title',\s*array\(\s*\$this,\s*'render_cart_title'\s*\)\s*\)/
  );
  assert.match(
    blocksShortcodes,
    /function\s+render_cart_title\(\):\s*string[\s\S]*?<header class="akb-page-title"><h1>%s<\/h1><\/header>/
  );
  assert.doesNotMatch(cartHooks, /function\s+akibara_gp_cart_title/);
  assert.doesNotMatch(cartHooks, /<h1>Tu carrito<\/h1>/);
});

test('the empty-cart message is an H2 below the cart page H1', () => {
  assert.match(
    emptyCart,
    /<h2 id="akb-cart-empty-title" class="akb-empty__title">/
  );
  assert.match(emptyCart, /TU CARRITO ESTÁ VACÍO/);
  assert.doesNotMatch(emptyCart, /<h1[^>]*id="akb-cart-empty-title"/);
  assert.match(
    emptyCart,
    /<section class="akb-empty akb-cart-empty" aria-labelledby="akb-cart-empty-title">/
  );
	assert.match(emptyCart, /Envíos a todo Chile/);
	assert.doesNotMatch(emptyCart, /nacional/i);
  assert.match(emptyCart, /Atención por WhatsApp/);
  assert.doesNotMatch(emptyCart, /Atención personalizada/);
});

// Candado de FUENTE, justificado (fuente única, ficha R4-9c9fffc5): la
// decisión de si `.akb-cart-trigger` anuncia el diálogo modal vive en UNA
// sola función, `akibara_gp_cart_trigger_aria()`. El comportamiento de esa
// función (qué devuelve dentro y fuera de carrito/checkout) lo prueba
// WooCommerceHooksTest.php ejecutando la función de verdad; esto sólo
// verifica que los DOS triggers del tema —icono del header y pestaña del
// bottom-nav— la llaman a ELLA en vez de repetir `is_cart()`/`is_checkout()`
// o el literal `aria-haspopup` cada uno por su lado, que fue exactamente el
// defecto: el bottom-nav nunca copió el patrón que sí tiene `#bottom-nav-search`.
test('the header cart icon and the bottom-nav cart tab share the same ARIA decision', () => {
  assert.match(
    cartHooks,
    /function akibara_gp_cart_trigger_aria\(\): string \{/,
    'debe existir la función única que decide el ARIA del trigger del carrito'
  );

  // `[^>]*` no sirve para cortar en el `>` de cierre del tag: el propio `<?php
  // ... ?>` de adentro trae un `>` que corta el match antes de llegar al real.
  // Se recorta por índice, del inicio del `<a>` al siguiente `</a>` (el tag no
  // tiene hijos con su propio `</a>`, así que el primero SÍ es el de cierre).
  const anchorSlice = (source, startNeedle, label) => {
    const start = source.indexOf(startNeedle);
    assert.ok(start !== -1, `no se encontró ${label}`);
    const end = source.indexOf('</a>', start);
    assert.ok(end !== -1, `no se encontró el cierre </a> de ${label}`);
    return source.slice(start, end);
  };

  const headerCartAnchor = anchorSlice(
    cartHooks,
    '<a href="<?php echo esc_url( $cart_url ); ?>"',
    'el <a> del icono de carrito del header'
  );
  assert.match(
    headerCartAnchor,
    /akibara_gp_cart_trigger_aria\(\)/,
    'el icono de carrito del header debe imprimir akibara_gp_cart_trigger_aria()'
  );

  const bottomNavCartAnchor = anchorSlice(
    bottomNav,
    '<a href="<?php echo esc_url( $akb_cart_url ); ?>"',
    'el <a> de la pestaña Carrito del bottom-nav'
  );
  assert.match(
    bottomNavCartAnchor,
    /akibara_gp_cart_trigger_aria\(\)/,
    'la pestaña Carrito del bottom-nav debe imprimir akibara_gp_cart_trigger_aria(), no repetir la regla'
  );

  // Ningún otro sitio del tema debe volver a escribir aria-haspopup="dialog"
  // a mano sobre .akb-cart-trigger — eso es exactamente lo que se dejaría de
  // sincronizar si mañana alguien "copia y pega" en vez de llamar a la función.
  assert.doesNotMatch(cartHooks, /akb-cart-trigger[^>]*aria-haspopup="dialog"/);
  assert.doesNotMatch(bottomNav, /akb-cart-trigger[^>]*aria-haspopup="dialog"/);
});
