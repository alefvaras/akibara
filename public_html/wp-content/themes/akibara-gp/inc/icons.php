<?php
/**
 * Icon library — SVGs inline.
 *
 * Single source for ALL SVG icons usados en templates. Cero requests
 * externos, cero queries, output presentational puro.
 *
 * Si querés agregar/cambiar un icono: editás SOLO acá. Todos los lugares
 * que usen `akibara_gp_icon('cart')` reflejan el cambio inmediato.
 *
 * Uso:
 *   <?php echo akibara_gp_icon( 'cart', 22 ); ?>
 *   <?php echo akibara_gp_icon( 'instagram', 18 ); ?>
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renderea SVG icon inline.
 *
 * @param string $name Nombre del icono. Ver lista abajo.
 * @param int    $size Tamaño en px (width = height). Default 20.
 * @return string SVG markup. Empty si el nombre no existe.
 */
function akibara_gp_icon( string $name, int $size = 20 ): string {
	// Stroke-style: outline icons. Fill-style: brand logos (IG, FB, WhatsApp).
	$open_stroke = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">',
		$size
	);
	$open_fill = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">',
		$size
	);

	$icons = array(

		// ─── Outline (stroke) icons ────────────────────────────────
		'cart' => $open_stroke
			. '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>'
			. '<line x1="3" y1="6" x2="21" y2="6"/>'
			. '<path d="M16 10a4 4 0 0 1-8 0"/>'
			. '</svg>',

		'package' => $open_stroke
			. '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
			. '<polyline points="3.29 7 12 12 20.71 7"/>'
			. '<line x1="12" y1="22" x2="12" y2="12"/>'
			. '</svg>',

		'heart' => $open_stroke
			. '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'
			. '</svg>',

		'user' => $open_stroke
			. '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>'
			. '<circle cx="12" cy="7" r="4"/>'
			. '</svg>',

		'home' => $open_stroke
			. '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
			. '<polyline points="9 22 9 12 15 12 15 22"/>'
			. '</svg>',

		'search' => $open_stroke
			. '<circle cx="11" cy="11" r="8"/>'
			. '<line x1="21" y1="21" x2="16.65" y2="16.65"/>'
			. '</svg>',

		// Storefront — para "Catálogo" en bottom-nav. Awning (techo inclinado)
		// + paredes + puerta. Distinto de `cart` (bolsa) para evitar confusión.
		'shop' => $open_stroke
			. '<path d="M3 9l1.5-5h15L21 9"/>'
			. '<path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/>'
			. '<path d="M9 21v-6h6v6"/>'
			. '</svg>',

		'close' => $open_stroke
			. '<line x1="18" y1="6" x2="6" y2="18"/>'
			. '<line x1="6" y1="6" x2="18" y2="18"/>'
			. '</svg>',

		// Información pública de soporte y contacto.
		'clock' => $open_stroke
			. '<circle cx="12" cy="12" r="10"/>'
			. '<polyline points="12 6 12 12 16 14"/>'
			. '</svg>',

		'phone' => $open_stroke
			. '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'
			. '</svg>',

		// ─── Filled brand icons ────────────────────────────────────
		'instagram' => $open_fill
			. '<path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.81.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.81-.41 2.23a3.7 3.7 0 0 1-.9 1.38 3.7 3.7 0 0 1-1.38.9c-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.81-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.81.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.34 4.14.63a5.86 5.86 0 0 0-2.13 1.38A5.86 5.86 0 0 0 .63 4.14C.34 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.27 2.15.56 2.91.3.79.7 1.46 1.38 2.13a5.86 5.86 0 0 0 2.13 1.38c.76.29 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.27 2.91-.56a5.86 5.86 0 0 0 2.13-1.38 5.86 5.86 0 0 0 1.38-2.13c.29-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.27-2.15-.56-2.91a5.86 5.86 0 0 0-1.38-2.13A5.86 5.86 0 0 0 19.86.63c-.76-.29-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.41-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z"/>'
			. '</svg>',

		'facebook' => $open_fill
			. '<path d="M24 12.07a12 12 0 1 0-13.88 11.86v-8.39H7.08v-3.47h3.04V9.43c0-3 1.79-4.66 4.53-4.66 1.31 0 2.68.23 2.68.23v2.95h-1.51c-1.49 0-1.95.92-1.95 1.87v2.25h3.32l-.53 3.47h-2.79v8.39A12 12 0 0 0 24 12.07z"/>'
			. '</svg>',

		'whatsapp' => $open_fill
			// Glifo oficial: burbuja + auricular interno (antes era solo el contorno
			// de la burbuja, leía como ícono de "mensaje" genérico → AI-slop).
			. '<path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.15 1.6 5.96L2 22l4.25-1.11a9.86 9.86 0 0 0 5.79 1.85h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 1.67c2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.42 5.82c0 4.54-3.7 8.24-8.24 8.24h-.01a8.2 8.2 0 0 1-4.18-1.14l-.3-.18-2.52.66.67-2.46-.2-.31a8.2 8.2 0 0 1-1.26-4.37c0-4.54 3.7-8.24 8.24-8.24zm-4.6 4.52c-.22 0-.57.08-.87.41-.3.33-1.14 1.11-1.14 2.71s1.17 3.15 1.33 3.37c.16.22 2.3 3.51 5.57 4.79.78.3 1.38.48 1.86.62.78.25 1.49.21 2.05.13.63-.09 1.93-.79 2.2-1.55.27-.76.27-1.41.19-1.55-.08-.13-.3-.21-.63-.37-.33-.16-1.93-.95-2.23-1.06-.3-.11-.52-.16-.74.16-.22.33-.85 1.06-1.04 1.28-.19.22-.38.25-.71.08-.33-.16-1.38-.51-2.63-1.62-.97-.87-1.63-1.94-1.82-2.27-.19-.33-.02-.5.15-.66.15-.15.33-.38.49-.58.16-.19.22-.33.33-.55.11-.22.05-.41-.03-.58-.08-.16-.74-1.78-1.01-2.43-.27-.64-.54-.55-.74-.56z"/>'
			. '</svg>',
	);

	$icon = $icons[ $name ] ?? '';
	if ( '' === $icon ) {
		return '';
	}

	// Identificador semántico: el nombre proviene de la lista de claves
	// permitidas de $icons, garantizando valores seguros en el atributo.
	return (string) preg_replace(
		'/^<svg\b/',
		sprintf( '<svg data-akibara-icon="%s"', $name ),
		$icon,
		1
	);
}
