<?php
/**
 * Performance helpers.
 *
 * La carga de las portadas la decide ESTE módulo con reglas explícitas (home y
 * catálogo) que dan el mismo HTML con la carga diferida nativa de WordPress
 * encendida o apagada. El lazy de LiteSpeed queda fijado en «apagado» por
 * código en `mu-plugins/akibara-litespeed-guardrails.php`, que es también lo que
 * le devuelve a WordPress su lazy nativo para el resto de imágenes del sitio.
 * El preload del hero vive en akibara-business/HomeHero.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Aplica carga diferida a portadas situadas bajo el hero de portada.
 *
 * Se implementa mediante `wp_get_attachment_image_attributes` para operar
 * independientemente del estado de `wp_lazy_loading_enabled` o configuraciones
 * externas de optimización de medios.
 *
 * El atributo `sizes` se sincroniza con el `loading` final (ver
 * `akibara_gp_align_auto_sizes()`).
 *
 * @internal
 *
 * @param array<string, string> $attr Image attributes.
 * @param mixed                 $attachment Attachment object.
 * @param string|int[]          $size Requested image size.
 * @return array<string, string>
 */
function akibara_gp_handle_home_product_image_loading( array $attr, $attachment, $size ): array {
	if ( ! akibara_gp_is_home_surface() ) {
		return $attr;
	}

	$size_name = is_string( $size ) ? $size : '';
	if ( ! in_array( $size_name, array( 'woocommerce_thumbnail', 'shop_catalog' ), true ) ) {
		return $attr;
	}

	$attr['loading'] = 'lazy';
	unset( $attr['fetchpriority'] );

	return akibara_gp_align_auto_sizes( $attr );
}
add_filter( 'wp_get_attachment_image_attributes', 'akibara_gp_handle_home_product_image_loading', 10, 3 );

/**
 * Ceba la caché de adjuntos de las portadas antes de pintar la rejilla.
 *
 * `wp_get_attachment_image()` necesita el post del adjunto y su
 * `_wp_attachment_metadata` para armar `srcset`/`sizes`. La consulta principal
 * del catálogo ceba los productos, no sus adjuntos, así que cada tarjeta iba a
 * la base por su cuenta: dos consultas por portada, 48 en una página de 24.
 *
 * `update_post_thumbnail_cache()` es la función de core para esto: resuelve los
 * `_thumbnail_id` del loop y ceba post + meta de todos los adjuntos de una vez.
 * Vive aquí, en el módulo dueño de las imágenes del catálogo, y no en el
 * template, porque es una decisión de rendimiento y no de marcado.
 *
 * Corre en el hook que WooCommerce dispara justo antes del loop del archive, con
 * la consulta principal ya resuelta.
 */
function akibara_gp_prime_catalog_thumbnails(): void {
	global $wp_query;

	if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
		return;
	}

	update_post_thumbnail_cache( $wp_query );
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_prime_catalog_thumbnails', 5 );

/**
 * Cebado en lote de metadatos de miniaturas para rejillas del shortcode `[products]`.
 *
 * Previene consultas SQL individuales a `wp_postmeta` por cada miniatura en
 * las vitrinas de la portada cebando posts y metadatos de adjuntos en una única tanda.
 *
 * @param mixed $results Resultado de WC_Shortcode_Products::get_query_results().
 * @return mixed El mismo resultado, intacto.
 */
function akibara_gp_prime_shortcode_thumbnails( $results ) {
	if ( ! is_object( $results ) || empty( $results->ids ) || ! function_exists( '_prime_post_caches' ) ) {
		return $results;
	}

	$ids = array_values( array_filter( array_map( 'intval', (array) $results->ids ) ) );
	if ( array() === $ids ) {
		return $results;
	}

	update_meta_cache( 'post', $ids );

	$thumbs = array();
	foreach ( $ids as $id ) {
		$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
		if ( $thumb > 0 ) {
			$thumbs[ $thumb ] = $thumb;
		}
	}
	if ( array() !== $thumbs ) {
		_prime_post_caches( array_values( $thumbs ), false, true );
	}

	return $results;
}
add_filter( 'woocommerce_shortcode_products_query_results', 'akibara_gp_prime_shortcode_thumbnails' );

/**
 * `sizes` real de las portadas del catálogo.
 *
 * El contexto lo decide `akibara_gp_is_catalog_archive()`, que ya vive en
 * `inc/woocommerce.php` y es la definición de la casa (tienda + taxonomías de
 * producto + búsqueda de productos). No se duplica acá.
 *
 * QUÉ ROMPÍA. WordPress derivaba el `sizes` del TAMAÑO DEL ARCHIVO, no del hueco
 * donde se pinta: servía `(max-width: 350px) 100vw, 350px` para una celda que en
 * móvil mide **184 px**. Con esa declaración, a DPR2 el navegador pedía 700 px y
 * elegía el candidato de 768w donde bastaba el de 350w. Medido sobre las
 * portadas reales de The Climber: **78.555 B contra 24.672 B, o sea 53.883 B de
 * más por imagen**, y cada portada de la primera pantalla la pagaba.
 *
 * Las imágenes diferidas reciben `sizes="auto, …"` (aplicado por core o por
 * `akibara_gp_align_auto_sizes()`), permitiendo a navegadores modernos usar el
 * ancho de render real. En navegadores que no soportan `auto` rige el descriptor
 * de medios calculado.
 *
 * Los anchos derivan de la geometría real de la rejilla (ancho de columna menos
 * los bordes de la tarjeta):
 *
 *   ≤380px  2 columnas, hueco 8   → calc(50vw - 8px)      (360 → 172)
 *   ≤768px  2 columnas, hueco 12  → calc(50vw - 10px)     (390 → 185, 768 → 374)
 *   ≤1024px 3 columnas, hueco 16  → (ancho útil - 32) / 3 - 4
 *   4 columnas, hueco 16          → (ancho útil - 48) / 4 - 4, hasta el
 *           límite de contenedor (`max-width: 1400px`): 334 px (o 248 con barra lateral).
 *
 * El ancho útil considera la presencia de barra lateral en archivos de catálogo
 * según determina `akibara_gp_archive_sidebar_layout()`.
 *
 * En venta cruzada de estados vacíos (404, carrito vacío), las tarjetas
 * se ajustan mediante `akibara_gp_cross_sell_image_sizes()`.
 *
 * @param mixed        $sizes Valor calculado por WordPress.
 * @param string|int[] $size  Tamaño solicitado.
 * @return mixed
 */
function akibara_gp_catalog_image_sizes( $sizes, $size ) {
	$venta_cruzada = akibara_gp_is_cross_sell_loop();

	if ( ( ! $venta_cruzada && ! akibara_gp_is_catalog_archive() ) || ! function_exists( 'wc_get_image_size' ) ) {
		return $sizes;
	}

	// TRAMPA MEDIDA: este filtro NO recibe el nombre del tamaño. `wp_get_attachment_image()`
	// resuelve las dimensiones ANTES y le pasa un array `[350, 525]`, así que
	// un guard `is_string($size) && 'woocommerce_thumbnail' === $size` descarta
	// justo el caso que importa y el filtro no hace nada — se ve pasar el
	// `sizes` viejo intacto. Hay que aceptar las dos formas.
	$objetivo = wc_get_image_size( 'woocommerce_thumbnail' );
	$ancho    = isset( $objetivo['width'] ) ? (int) $objetivo['width'] : 0;

	if ( is_string( $size ) ) {
		if ( 'woocommerce_thumbnail' !== $size ) {
			return $sizes;
		}
	} elseif ( is_array( $size ) && isset( $size[0] ) ) {
		if ( 0 === $ancho || (int) $size[0] !== $ancho ) {
			return $sizes;
		}
	} else {
		return $sizes;
	}

	if ( $venta_cruzada ) {
		return akibara_gp_cross_sell_image_sizes();
	}

	$movil = '(max-width: 380px) calc(50vw - 8px), (max-width: 768px) calc(50vw - 10px), ';

	if ( 'left-sidebar' === akibara_gp_archive_sidebar_layout( '' ) ) {
		return $movil . '(max-width: 1024px) calc((100vw - 376px) / 3 - 4px), (max-width: 1400px) calc((100vw - 392px) / 4 - 4px), 248px';
	}

	// Sin barra (serie, autor): la columna deja 20 px por lado desde 769 px
	// (woocommerce.css, «Canalón de las landings sin barra de filtros») y llega al
	// contenedor de 1400 px a 1440 de ventana.
	return $movil . '(max-width: 1024px) calc((100vw - 72px) / 3 - 4px), (max-width: 1440px) calc((100vw - 88px) / 4 - 4px), 334px';
}
add_filter( 'wp_calculate_image_sizes', 'akibara_gp_catalog_image_sizes', 10, 2 );

/**
 * Nombre con que `template-parts/empty/cross-sell.php` marca su loop.
 *
 * Es el mecanismo de WooCommerce para sus propias rejillas fuera del archive
 * (`related`, `up-sells`, `cross-sells`, wc-template-functions.php).
 */
const AKIBARA_GP_CROSS_SELL_LOOP = 'akb-cross-sell';

/**
 * ¿Se está pintando la venta cruzada de un estado vacío?
 *
 * Se lee el global y NO `wc_get_loop_prop( 'name' )`: esa función llama antes a
 * `wc_setup_loop()`, que CREA `$GLOBALS['woocommerce_loop']` donde no lo había,
 * y quien pregunta aquí es un filtro que corre en cada imagen de cada página
 * (portada, blog, correos). Preguntar no puede cambiar el estado del loop.
 *
 * @return bool
 */
function akibara_gp_is_cross_sell_loop(): bool {
	return isset( $GLOBALS['woocommerce_loop']['name'] )
		&& AKIBARA_GP_CROSS_SELL_LOOP === $GLOBALS['woocommerce_loop']['name'];
}

/**
 * `sizes` de las portadas de la venta cruzada de los estados vacíos.
 *
 * Aplica la geometría del catálogo adaptada al contenedor de cada contexto:
 *   - 404 y sin resultados: contenedor `.akb-empty` con `max-width: 1400px`.
 *   - Carrito vacío: contenedor del bloque de carrito de WooCommerce.
 *   - Favoritos vacíos: columna de cuenta de usuario.
 *   - Búsqueda vacía con barra lateral: descuenta el ancho de facetas.
 *
 * @return string
 */
function akibara_gp_cross_sell_image_sizes(): string {
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return '(max-width: 380px) calc(50vw - 52px), (max-width: 768px) calc(50vw - 54px), (max-width: 1024px) calc((100vw - 96px) / 3 - 4px), (max-width: 1432px) calc((100vw - 112px) / 4 - 4px), 326px';
	}

	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return '(max-width: 380px) calc(50vw - 40px), (max-width: 768px) calc(50vw - 42px), (max-width: 1024px) calc((100vw - 360px) / 3 - 4px), (max-width: 1728px) calc((100vw - 376px) / 4 - 4px), 334px';
	}

	if ( akibara_gp_is_catalog_archive() && 'left-sidebar' === akibara_gp_archive_sidebar_layout( '' ) ) {
		return '(max-width: 380px) calc(50vw - 24px), (max-width: 768px) calc(50vw - 26px), (max-width: 1024px) calc((100vw - 410px) / 3 - 4px), (max-width: 1400px) calc((100vw - 426px) / 4 - 4px), 240px';
	}

	return '(max-width: 380px) calc(50vw - 24px), (max-width: 768px) calc(50vw - 26px), (max-width: 1024px) calc((100vw - 64px) / 3 - 4px), (max-width: 1432px) calc((100vw - 80px) / 4 - 4px), 334px';
}

/**
 * `sizes` de la portada de la ficha: calcula el ancho de render efectivo.
 *
 * Tramos geométricos:
 *   ≤768   min(100vw - 32, 50vh × ratio)        (considera padding de contenedor y altura máxima)
 *   ≤768   100vw - 32                           respaldo para navegadores sin soporte de `min()` en sizes
 *   ≤1073  (100vw - 32) × 0,48                  ancho porcentual de WooCommerce
 *   >1073  500px                                techo de ancho máximo en ficha de producto
 *
 * @param mixed        $sizes         Valor calculado por WordPress.
 * @param mixed        $size          Medidas que se pintan (`[ancho, alto]`).
 * @param mixed        $image_src     URL de la imagen (sin uso).
 * @param mixed        $image_meta    Metadatos del adjunto (sin uso).
 * @param int|string   $attachment_id Adjunto.
 * @return mixed
 */
function akibara_gp_single_cover_sizes( $sizes, $size, $image_src = null, $image_meta = null, $attachment_id = 0 ) {
	unset( $image_src, $image_meta );

	if ( ! is_array( $size ) || ! isset( $size[0], $size[1] ) || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $sizes;
	}

	$attachment_id = (int) $attachment_id;
	if ( $attachment_id < 1 || ! in_array( $attachment_id, akibara_gp_queried_product_image_ids(), true ) ) {
		return $sizes;
	}

	$pintada = wp_get_attachment_image_src( $attachment_id, 'woocommerce_single' );
	if ( ! is_array( $pintada ) || (int) $pintada[1] !== (int) $size[0] || (int) $pintada[2] !== (int) $size[1] ) {
		return $sizes;
	}

	return akibara_gp_single_cover_sizes_value( (int) $size[0], (int) $size[1] );
}
add_filter( 'wp_calculate_image_sizes', 'akibara_gp_single_cover_sizes', 10, 5 );

/**
 * El `sizes` de la portada de la ficha para unas medidas dadas.
 *
 * @param int $ancho Ancho del derivado que se pinta.
 * @param int $alto  Alto del derivado que se pinta.
 * @return string
 */
function akibara_gp_single_cover_sizes_value( int $ancho, int $alto ): string {
	$movil = '(max-width: 768px) calc(100vw - 32px)';

	if ( $ancho > 0 && $alto > 0 ) {
		$proporcion = ceil( $ancho / $alto * 10000 ) / 10000;
		$movil      = '(max-width: 768px) min(calc(100vw - 32px), calc(50vh * ' . $proporcion . ')), ' . $movil;
	}

	return $movil . ', (max-width: 1073px) calc((100vw - 32px) * 0.48), 500px';
}

/**
 * Adjuntos de la ficha que se está sirviendo: portada y galería.
 *
 * Se guarda por producto: el filtro que pregunta corre en cada imagen de la ficha
 * (relacionados, armador, logos) y levantar el producto cada vez no aporta nada.
 *
 * @return int[]
 */
function akibara_gp_queried_product_image_ids(): array {
	static $por_producto = array();

	$producto_id = (int) get_queried_object_id();
	if ( ! isset( $por_producto[ $producto_id ] ) ) {
		$producto                     = function_exists( 'wc_get_product' ) ? wc_get_product( $producto_id ) : null;
		$por_producto[ $producto_id ] = $producto instanceof WC_Product
			? array_values( array_filter( array_map( 'intval', array_merge( array( $producto->get_image_id() ), $producto->get_gallery_image_ids() ) ) ) )
			: array();
	}

	return $por_producto[ $producto_id ];
}

/**
 * Cuántas portadas de la rejilla se cargan de entrada: **2**, en todo el
 * catálogo.
 *
 * Un solo número para los dos que lo consultan:
 *   - WordPress, vía `wp_omit_loading_attr_threshold`, para decidir qué imágenes
 *     del loop difiere cuando su carga diferida nativa está encendida;
 *   - `akibara_gp_catalog_cover_loading()`, la regla explícita del tema, que da
 *     el mismo resultado aunque core esté apagado.
 *
 * POR QUÉ 2. Rejilla de /tienda/ servida a 390x844 en producción:
 *
 *   fila 1 → #1 y #2 en `top=303`   (las únicas portadas dentro del pliegue)
 *   fila 2 → #3 y #4 en `top=848`   (ya fuera: 848 ≥ 844)
 *   fila 3 → #5 y #6 en `top=1391`
 *
/**
 * Umbral de carga anticipada en rejillas de catálogo (N = 2).
 *
 * En viewports móviles (2 columnas), las portadas #1 y #2 pertenecen a la primera fila
 * visible above-the-fold. A partir de #3 (segunda fila) se difiere la carga completa
 * para asegurar un LCP óptimo y evitar descargas innecesarias fuera de pantalla.
 *
 * @param mixed $threshold Umbral original de WordPress.
 * @return int
 */
function akibara_gp_catalog_lazy_threshold( $threshold ) {
	if ( ! akibara_gp_is_catalog_archive() ) {
		return $threshold;
	}

	return 2;
}
add_filter( 'wp_omit_loading_attr_threshold', 'akibara_gp_catalog_lazy_threshold' );

/**
 * Empieza la rejilla principal del catálogo: desde qué celda se cuenta.
 *
 * SÓLO LA PRIMERA de la petición. `woocommerce_before_shop_loop` también lo
 * dispara un `[products paginate="true"]` (class-wc-shortcode-products.php:651),
 * y la descripción de /tienda/ se imprime AL PIE del archive
 * (SeoEnhancements.php:224): si ahí hubiera ese shortcode, reiniciar la cuenta
 * dejaría portadas del pie sin diferir y con `fetchpriority`. Las rejillas que
 * vienen después siguen contando, así que salen todas diferidas.
 *
 * La cuenta es de CELDAS (`woocommerce_before_shop_loop_item`, una por producto,
 * content-product.php:34) y no de imágenes: un producto sin portada cuyo
 * placeholder no es un adjunto se pinta con un `<img>` a mano que no pasa por
 * `wp_get_attachment_image_attributes` (wc-product-functions.php:471-481), pero
 * ocupa su celda igual.
 */
function akibara_gp_catalog_grid_start(): void {
	if ( isset( $GLOBALS['akibara_gp_rejilla'] ) || ! akibara_gp_is_catalog_archive() ) {
		return;
	}

	// UNA REJILLA DE SHORTCODE NO ES LA PRINCIPAL, ni aunque llegue primero.
	// `woocommerce_archive_description` corre ANTES que `woocommerce_before_shop_loop`,
	// y la descripción de un término pasa por `wc_format_content()`, que ejecuta
	// shortcodes: un `[products paginate="true"]` escrito por el dueño en esa
	// descripción dispara el hook antes que la rejilla de verdad
	// (class-wc-shortcode-products.php:646-652) y se quedaría con el reinicio,
	// dejando la rejilla real entera diferida y sin `fetchpriority` — en silencio.
	// WooCommerce marca esas rejillas en `wc_setup_loop()` ANTES de disparar el
	// hook (`:625-638`), así que la marca ya está puesta cuando se lee aquí.
	if ( wc_get_loop_prop( 'is_shortcode' ) ) {
		return;
	}

	$GLOBALS['akibara_gp_rejilla'] = array(
		'base' => (int) did_action( 'woocommerce_before_shop_loop_item' ),
	);
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_catalog_grid_start', 1 );

/**
 * Celda de la rejilla principal que se está pintando, desde 1. 0 si la imagen no
 * está dentro de una celda (algo pintado encima de la rejilla).
 *
 * @return int
 */
function akibara_gp_catalog_cover_position(): int {
	$base = isset( $GLOBALS['akibara_gp_rejilla']['base'] ) ? (int) $GLOBALS['akibara_gp_rejilla']['base'] : 0;

	return max( 0, (int) did_action( 'woocommerce_before_shop_loop_item' ) - $base );
}

/**
 * Atributos de carga de portadas en archivos de catálogo:
 *   #1 … #N  → sin `loading` (petición inmediata)
 *   #1       → además `fetchpriority="high"`, si N ≥ 1
 *   #N+1 …   → `loading="lazy"` + `decoding="async"`, sin `fetchpriority`
 *
 * N se determina mediante `akibara_gp_catalog_lazy_threshold()`.
 *
 * @param array<string, string> $attr       Atributos de la imagen.
 * @param mixed                 $attachment Adjunto.
 * @param string|int[]          $size       Tamaño solicitado.
 * @return array<string, string>
 */
function akibara_gp_catalog_cover_loading( array $attr, $attachment, $size ): array {
	// Aquí SÍ llega el nombre (a diferencia de `wp_calculate_image_sizes`):
	// WooCommerce pide la portada del loop con la cadena 'woocommerce_thumbnail'.
	if ( ! is_string( $size ) || 'woocommerce_thumbnail' !== $size ) {
		return $attr;
	}
	if ( akibara_gp_is_home_surface() || ! akibara_gp_is_catalog_archive() ) {
		return $attr;
	}

	$posicion = akibara_gp_catalog_cover_position();
	if ( $posicion < 1 ) {
		return $attr;
	}

	$de_entrada = (int) akibara_gp_catalog_lazy_threshold( 3 );

	if ( $posicion > $de_entrada ) {
		$attr['loading']  = 'lazy';
		$attr['decoding'] = 'async';
		unset( $attr['fetchpriority'] );
	} else {
		unset( $attr['loading'] );

		if ( 1 === $posicion ) {
			$attr['fetchpriority'] = 'high';
		} else {
			unset( $attr['fetchpriority'] );
		}
	}

	return akibara_gp_align_auto_sizes( $attr );
}
add_filter( 'wp_get_attachment_image_attributes', 'akibara_gp_catalog_cover_loading', 11, 3 );

/**
 * Deja `sizes="auto, …"` sólo en las imágenes que salen con `loading="lazy"`.
 *
 * Core antepone `auto,` en `wp_get_attachment_image()` ANTES de aplicar
 * `wp_get_attachment_image_attributes`, y sólo a lo que su heurística difirió.
 * Las reglas de este módulo cambian `loading` después, así que sin realinear:
 *   - una portada que se difiere aquí saldría sin `auto`, y el navegador elegiría
 *     el candidato por el `sizes` declarado y no por el hueco realmente pintado;
 *   - una que se carga de entrada podría quedarse con un `auto` que el HTML sólo
 *     admite junto a `loading="lazy"`.
 *
 * NUNCA deja `srcset` sin `sizes`: si detrás de `auto` no hay nada, no se quita
 * (sin `sizes` el navegador supone 100vw; con un `auto` que no le vale, también,
 * así que quitarlo no arregla nada y rompería la pareja).
 *
 * Respeta `wp_img_tag_add_auto_sizes`, el interruptor de core para esto. La
 * regla CSS que core imprime para `sizes=auto` (`contain-intrinsic-size`) no
 * infla las portadas: `.akb-product-cover-wrap` las enjaula en 2:3 (loop.css).
 *
 * @param array<string, string> $attr Atributos de la imagen.
 * @return array<string, string>
 */
function akibara_gp_align_auto_sizes( array $attr ): array {
	if ( empty( $attr['sizes'] ) || ! is_string( $attr['sizes'] ) ) {
		return $attr;
	}

	$diferida = isset( $attr['loading'] ) && 'lazy' === $attr['loading'];
	$con_auto = wp_sizes_attribute_includes_valid_auto( $attr['sizes'] );

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de core: se LEE su interruptor (`wp_img_tag_add_auto_sizes`), no se inventa un hook.
	if ( $diferida && ! $con_auto && apply_filters( 'wp_img_tag_add_auto_sizes', true ) ) {
		$attr['sizes'] = 'auto, ' . $attr['sizes'];
	} elseif ( ! $diferida && $con_auto ) {
		$resto = explode( ',', $attr['sizes'], 2 );
		$resto = isset( $resto[1] ) ? trim( $resto[1] ) : '';

		if ( '' !== $resto ) {
			$attr['sizes'] = $resto;
		}
	}

	return $attr;
}

/**
 * Las imágenes de los correos de WooCommerce salen como han salido siempre: sin
 * `loading="lazy"` y, por tanto, sin `sizes="auto"`.
 *
 * Con la baranda de LiteSpeed el lazy nativo vuelve a todo el sitio, y los
 * correos que se arman fuera de una plantilla de página (checkout por la Store
 * API, REST, cron) no están ni en el loop ni en la cabecera: core los difiere y
 * les antepone `auto,` (wp-includes/media.php:1260-1268). En un cliente de correo
 * no hay CSS de core que dé tamaño a un `sizes=auto`, y el soporte de `loading`
 * en Gmail, Outlook y Apple Mail es desigual. Producción mandó hasta hoy estas
 * imágenes sin ninguno de los dos atributos, que es lo único probado en bandejas
 * reales.
 *
 * SE PREGUNTA POR EL CONTEXTO, NO SE ABRE UNA VENTANA. La versión anterior
 * encendía una marca en `woocommerce_email_order_items_args` y la apagaba en
 * `woocommerce_email_order_items_table`. Dos agujeros: los artículos de los
 * correos de preparación de pedido (WooCommerce 11) usan OTRO par de filtros
 * (`woocommerce_email_fulfillment_items_args`, wc-template-functions.php:4078-4090),
 * y si la plantilla lanzaba una excepción —que `WC_Emails::send_transactional_email()`
 * captura y registra— la marca se quedaba encendida y el RESTO de la petición
 * (por ejemplo la página de «gracias por tu compra», que se pinta después de
 * mandar los correos) se quedaba sin carga diferida.
 *
 * `doing_action()` no tiene ese problema porque no hay nada que cerrar: mira la
 * pila de acciones en curso de WordPress. Las dos tablas de artículos se pintan
 * dentro de estas acciones —`WC_Emails::order_details()` y su gemela de
 * preparación son callbacks suyos—, así que cubre los dos pares de filtros y
 * cualquier otra imagen del cuerpo del correo.
 *
 * Los correos propios de akibara-business (aviso de stock, resumen de series) no
 * pasan por aquí porque ya pintan la portada como URL (`wp_get_attachment_image_url`).
 *
 * @param mixed $enabled Lo que diga core o quien haya filtrado antes.
 * @return mixed
 */
function akibara_gp_email_images_without_lazy( $enabled ) {
	if ( doing_action( 'woocommerce_email_order_details' ) || doing_action( 'woocommerce_email_fulfillment_details' ) ) {
		return false;
	}

	return $enabled;
}
add_filter( 'wp_lazy_loading_enabled', 'akibara_gp_email_images_without_lazy', 99 );

/**
 * Defer home styles that cannot affect the first viewport.
 *
 * LiteSpeed's "Load CSS Asynchronously" switch is intentionally not used:
 * it is site-wide and needs generated Critical CSS from QUIC.cloud. Docker
 * cannot generate/validate that Critical CSS and a global switch would also
 * affect checkout, account and product routes. The home has a stable,
 * measurable split instead: announcement, header, hero, trust strip and the
 * fixed mobile navigation remain render-blocking; hidden controls and content
 * that starts below the first viewport may load without blocking first paint.
 *
 * The normal stylesheet is kept inside <noscript>, so the page remains fully
 * styled when JavaScript is disabled. Keeping this list here (rather than in
 * LiteSpeed exclusions) also makes the route-specific decision reviewable and
 * portable between Docker, QA and production.
 *
 * Tres entradas se retiraron en 2.1.139 por inalcanzables (regla del dueño:
 * el pegamento a un tercero muere CON el tercero):
 *
 * - `cr-frontend-css` era la hoja de «Customer Reviews for WooCommerce»
 *   (CusRev), tercero retirado: no aparece en `wp plugin list` de producción
 *   ni activo ni inactivo, y nadie más registra ese handle. Es el mismo barrido
 *   que ya cerró `customer-reviews-woocommerce/form.css` y su carpeta en 2.1.133.
 * - `photoswipe` y `photoswipe-default-skin` sólo los encola WooCommerce para
 *   la galería de la ficha, y esta lista sólo corre bajo
 *   `akibara_gp_is_home_surface()`. La otra puerta de WooCommerce
 *   (`[product_page` en el contenido) tampoco aplica: medido en producción, la
 *   portada (page 210) lleva siete shortcodes propios y ninguno es ése.
 *
 * `wc-blocks-style` SÍ se queda: la portada es una página editable por bloques,
 * así que el dueño puede insertar un bloque de WooCommerce sin tocar código y
 * esa hoja aparecería. No es pegamento muerto, es cobertura de una superficie
 * que se opera desde el editor.
 *
 * `akibara-woocommerce` y `akibara-loop` se mantienen bloqueantes para evitar
 * destellos de contenido sin formato (FOUC) y cambios acumulativos de diseño (CLS)
 * en las tarjetas de producto durante el renderizado inicial.
 *
 * @return string[]
 */
function akibara_gp_home_deferred_style_handles(): array {
	return array(
		'wc-blocks-style',
		'akb-search-suggest',
		'akb-wishlist',
		'akb-estimated-delivery',
		'akibara-native-cart',
		'akibara-brands-home',
		'akb-fsp',
	);
}

/**
 * Change non-critical home styles to the print-media loading pattern.
 *
 * A print stylesheet downloads without blocking the screen render. Its
 * onload handler restores the original media as soon as the file arrives.
 *
 * @param string $html   Original stylesheet tag.
 * @param string $handle WordPress style handle.
 * @param string $href   Stylesheet URL.
 * @param string $media  Original media attribute.
 * @return string
 */
function akibara_gp_defer_noncritical_home_style(
	string $html,
	string $handle,
	string $href,
	string $media
): string {
	if (
		is_admin()
		|| ! akibara_gp_is_home_surface()
		|| ! in_array( $handle, akibara_gp_home_deferred_style_handles(), true )
		|| 'all' !== strtolower( trim( $media ) )
	) {
		return $html;
	}

	$deferred = sprintf(
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- reescribe la etiqueta de una hoja que WordPress ya encoló.
		"<link rel='stylesheet' id='%s-css' href='%s' media='print' data-akb-deferred-style='1' onload=\"this.onload=null;this.media='all'\" />\n",
		esc_attr( $handle ),
		esc_url( $href )
	);

	return $deferred . '<noscript>' . $html . '</noscript>';
}
add_filter( 'style_loader_tag', 'akibara_gp_defer_noncritical_home_style', 20, 4 );

/**
 * IDs de los 4 productos más vendidos para los cross-sells (404, carrito vacío,
 * sin resultados). La query vive en el plugin akibara-business (módulo
 * PopularProducts) — el theme es solo UI. Fallback a [] vía el filtro si el
 * plugin está inactivo (degradación elegante).
 *
 * @return int[]
 */
function akibara_get_popular_cross_sell_ids(): array {
	return array_map( 'intval', (array) apply_filters( 'akibara/popular_product_ids', array(), 4 ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- nombre publicado por akibara-business (PopularProducts).
}
