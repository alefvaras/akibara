<?php
/**
 * Purge Legacy Preventa, Packs, Editions, GLA, Clarity & Clean Pages/Terms
 *
 * Usage: docker exec akibara-cron-1 wp eval-file ops/purge-legacy-preventa-packs-editions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "=== PASO 1: Trazabilidad de órdenes activas ===\n";
require_once __DIR__ . '/migrar-notas-preventa-ordenes.php';

echo "\n=== PASO 2: Corrección de Slugs Anómalos (Rastros de Sangre) ===\n";
$p28507 = get_post( 28507 );
if ( $p28507 ) {
	$wpdb->update(
		$wpdb->posts,
		array( 'post_name' => 'rastros-de-sangre-1-milky-way' ),
		array( 'ID' => 28507 )
	);
	clean_post_cache( 28507 );
	echo "Post 28507 actualizado a slug 'rastros-de-sangre-1-milky-way'\n";
}

$p28526 = get_post( 28526 );
if ( $p28526 ) {
	$wpdb->update(
		$wpdb->posts,
		array( 'post_name' => 'rastros-de-sangre-10-milky-way' ),
		array( 'ID' => 28526 )
	);
	clean_post_cache( 28526 );
	echo "Post 28526 actualizado a slug 'rastros-de-sangre-10-milky-way'\n";
}

$by_slug_01 = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'rastros-de-sangre-01-milky-way' LIMIT 1" );
if ( $by_slug_01 ) {
	$wpdb->update(
		$wpdb->posts,
		array( 'post_name' => 'rastros-de-sangre-1-milky-way' ),
		array( 'ID' => (int) $by_slug_01 )
	);
	clean_post_cache( (int) $by_slug_01 );
	echo "Post {$by_slug_01} (encontrado por slug) actualizado a 'rastros-de-sangre-1-milky-way'\n";
}

$by_slug_10 = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'rastros-de-sangre-10' AND post_type = 'product' LIMIT 1" );
if ( $by_slug_10 ) {
	$wpdb->update(
		$wpdb->posts,
		array( 'post_name' => 'rastros-de-sangre-10-milky-way' ),
		array( 'ID' => (int) $by_slug_10 )
	);
	clean_post_cache( (int) $by_slug_10 );
	echo "Post {$by_slug_10} (encontrado por slug) actualizado a 'rastros-de-sangre-10-milky-way'\n";
}

echo "\n=== PASO 3: Normalización de inventario en BD ===\n";
$affected_backorders = $wpdb->query( "
	UPDATE {$wpdb->postmeta}
	SET meta_value = 'no'
	WHERE meta_key = '_backorders' AND meta_value != 'no'
" );
echo "Registros _backorders normalizados a 'no': {$affected_backorders}\n";

$wpdb->query( "
	UPDATE {$wpdb->postmeta} pm_status
	LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = pm_status.post_id AND pm_stock.meta_key = '_stock'
	SET pm_status.meta_value = 'outofstock'
	WHERE pm_status.meta_key = '_stock_status'
	AND (pm_stock.meta_value IS NULL OR CAST(pm_stock.meta_value AS SIGNED) <= 0)
	AND pm_status.meta_value != 'outofstock'
" );

$wpdb->query( "
	UPDATE {$wpdb->postmeta} pm_manage
	JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = pm_manage.post_id AND pm_status.meta_key = '_stock_status'
	SET pm_manage.meta_value = 'yes'
	WHERE pm_manage.meta_key = '_manage_stock' AND pm_status.meta_value = 'outofstock'
" );

$wpdb->query( "
	DELETE pm_sale FROM {$wpdb->postmeta} pm_sale
	JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = pm_sale.post_id AND pm_status.meta_key = '_stock_status'
	WHERE pm_sale.meta_key = '_sale_price' AND pm_status.meta_value = 'outofstock'
" );

$wpdb->query( "
	UPDATE {$wpdb->postmeta} pm_price
	JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = pm_price.post_id AND pm_status.meta_key = '_stock_status'
	JOIN {$wpdb->postmeta} pm_reg ON pm_reg.post_id = pm_price.post_id AND pm_reg.meta_key = '_regular_price'
	SET pm_price.meta_value = pm_reg.meta_value
	WHERE pm_price.meta_key = '_price' AND pm_status.meta_value = 'outofstock'
" );

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_product_meta_lookup'" ) ) {
	$lookup_updated = $wpdb->query( "
		UPDATE {$wpdb->prefix}wc_product_meta_lookup l
		LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = l.product_id AND pm_stock.meta_key = '_stock'
		LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = l.product_id AND pm_status.meta_key = '_stock_status'
		LEFT JOIN {$wpdb->postmeta} pm_price ON pm_price.post_id = l.product_id AND pm_price.meta_key = '_price'
		SET l.stock_status = COALESCE(pm_status.meta_value, 'outofstock'),
		    l.stock_quantity = CASE WHEN pm_stock.meta_value IS NULL THEN 0 ELSE CAST(pm_stock.meta_value AS SIGNED) END,
		    l.min_price = CAST(pm_price.meta_value AS DECIMAL(19,4)),
		    l.max_price = CAST(pm_price.meta_value AS DECIMAL(19,4)),
		    l.onsale = 0
		WHERE pm_status.meta_value = 'outofstock'
	" );
	echo "Lookup table actualizada para outofstock: {$lookup_updated}\n";
}

echo "\n=== PASO 4: Limpieza de metadatos obsoletos (Preventa, BYP, GLA, Adult Feed) ===\n";
$legacy_meta_keys = array(
	'_akb_promise_min_ts',
	'_akb_promise_max_ts',
	'_akb_is_tba',
	'_akb_release_date',
	'_akb_release_date_raw',
	'_akb_preventa_ready',
	'_akb_order_has_preventa',
	'_akb_consolidated_shipment_accepted',
	'_akb_mixed_consolidation_required',
	'_akb_bundle_ids',
	'_akb_bundle_discount',
	'_akb_byp_enabled',
	'_akb_byp_discount',
);

$keys_in = "'" . implode( "','", array_map( 'esc_sql', $legacy_meta_keys ) ) . "'";
$del_meta = $wpdb->query( "
	DELETE FROM {$wpdb->postmeta}
	WHERE meta_key IN ({$keys_in})
	   OR meta_key LIKE '_akb_preventa_%'
	   OR meta_key LIKE '_akb_byp_%'
	   OR meta_key LIKE '_wc_gla_%'
" );
echo "Postmeta obsoletas eliminadas: {$del_meta}\n";

$del_termmeta = $wpdb->query( "
	DELETE FROM {$wpdb->termmeta}
	WHERE meta_key LIKE '_akb_eda_%'
	   OR meta_key LIKE '_akb_byp_%'
	   OR meta_key LIKE '_akb_brand_family_%'
	   OR meta_key = '_akb_adult_content'
" );
echo "Termmeta obsoletas eliminadas: {$del_termmeta}\n";

echo "\n=== PASO 5: Limpieza de Tablas GLA, Solicitudes de Buscador, wp_options y Action Scheduler ===\n";
$gla_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}gla_%'" );
foreach ( $gla_tables as $gla_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$gla_table}`" );
	echo "Tabla eliminada: {$gla_table}\n";
}
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}akb_search_requests`" );
echo "Tabla eliminada: {$wpdb->prefix}akb_search_requests\n";

$del_options = $wpdb->query( "
	DELETE FROM {$wpdb->options}
	WHERE option_name LIKE 'akb_preventa_%'
	   OR option_name LIKE 'akb_series_bundles_%'
	   OR option_name LIKE 'akb_byp_%'
	   OR option_name LIKE 'akb_eda_%'
	   OR option_name LIKE 'akb_brand_family_%'
	   OR option_name LIKE 'akb_order_preventa_%'
	   OR option_name LIKE '_transient_akb_byp_%'
	   OR option_name LIKE '_transient_timeout_akb_byp_%'
	   OR option_name LIKE '_transient_akb_preventa_%'
	   OR option_name LIKE '_transient_timeout_akb_preventa_%'
	   OR option_name LIKE 'gla_%'
	   OR option_name LIKE 'wc_gla_%'
	   OR option_name LIKE '_transient_gla_%'
	   OR option_name LIKE '_transient_timeout_gla_%'
	   OR option_name LIKE 'clarity_%'
	   OR option_name LIKE '_transient_clarity_%'
	   OR option_name LIKE '_transient_timeout_clarity_%'
	   OR option_name LIKE 'akb_copy_edition_%'
	   OR option_name LIKE 'akb_copy_editions_%'
	   OR option_name LIKE 'akb_copy_eta_%'
	   OR option_name LIKE 'external_updates-%woo-product-bundle-premium%'
	   OR option_name IN (
		'akb_google_ads_tag',
		'akb_adult_series_seeded',
		'akb_clarity_project_id',
		'akb_editions_backup',
		'akb_copy_series_editions_label',
		'akb_copy_promise_pedido_transit',
		'akb_copy_promise_ready_window',
		'akb_bis_notify_on_backorder',
		'akibara_native_cart_show_preventa_policy',
		'akibara_native_cart_preventa_policy_copy',
		'akb_search_requests_db_version'
	   )
" );
echo "Opciones y transients obsoletos eliminados: {$del_options}\n";

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" ) ) {
	$as_cleaned = $wpdb->query( "
		DELETE a, c, l FROM {$wpdb->prefix}actionscheduler_actions a
		LEFT JOIN {$wpdb->prefix}actionscheduler_claims c ON c.claim_id = a.claim_id
		LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON l.action_id = a.action_id
		WHERE a.hook LIKE '%preventa%'
		   OR a.hook LIKE '%ready_to_ship%'
		   OR a.hook LIKE '%reminders_cron%'
		   OR a.hook LIKE '%send_failure_rearm%'
		   OR a.hook LIKE '%series_bundles%'
		   OR a.hook LIKE 'gla/%'
	" );
	echo "Acciones de Action Scheduler obsoletas eliminadas: {$as_cleaned}\n";
}

echo "\n=== PASO 6: Eliminación de Páginas y Entradas Obsoletas ===\n";
$obsolete_slugs = array( 'ivrea', 'panini', 'communication-preferences' );
foreach ( $obsolete_slugs as $slug ) {
	$pids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name = %s",
		$slug
	) );
	foreach ( $pids as $pid ) {
		wp_delete_post( (int) $pid, true );
		echo "Página eliminada definitivamente: ID {$pid} (slug: {$slug})\n";
	}
}

$obsolete_post_slugs = array(
	'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile',
	'que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber',
);
foreach ( $obsolete_post_slugs as $slug ) {
	$pids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_name = %s",
		$slug
	) );
	foreach ( $pids as $pid ) {
		wp_delete_post( (int) $pid, true );
		echo "Entrada de preventas eliminada definitivamente: ID {$pid} (slug: {$slug})\n";
	}
}

echo "\n=== PASO 7: Normalización Canónica de Descripciones de Editoriales (Regla 20) ===\n";
$brand_terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) );
if ( is_array( $brand_terms ) ) {
	foreach ( $brand_terms as $term ) {
		$canonical_desc = sprintf(
			'%s reúne en esta página los títulos catalogados de esta editorial en Akibara. Revisa el formato, el país de edición, el precio y la disponibilidad de cada producto. Las opciones y el costo de entrega se muestran al ingresar tu dirección en el checkout.',
			$term->name
		);
		if ( trim( (string) $term->description ) !== $canonical_desc ) {
			wp_update_term( $term->term_id, 'product_brand', array( 'description' => $canonical_desc ) );
			echo "Descripción canónica actualizada para marca: {$term->name} ({$term->slug})\n";
		}
	}
}

echo "\n=== PASO 8: Limpieza de Textos Obsoletos en Páginas Publicadas ===\n";
$update_page_by_slug = function( string $slug, callable $transformer ) use ( $wpdb ): void {
	$page_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name = %s LIMIT 1",
		$slug
	) );
	if ( ! $page_id ) {
		return;
	}
	$post = get_post( (int) $page_id );
	if ( ! $post ) {
		return;
	}
	$new_content = $transformer( (string) $post->post_content );
	if ( $new_content !== $post->post_content ) {
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $new_content ),
			array( 'ID' => (int) $page_id )
		);
		clean_post_cache( (int) $page_id );
		echo "Página '{$slug}' (ID {$page_id}) limpiada.\n";
	}
};

// 8.1 FAQ
$update_page_by_slug( 'faq', function( string $c ): string {
	$c = str_replace(
		'<h2 class="akb-page__section-heading">Envíos, stock y preventas</h2>',
		'<h2 class="akb-page__section-heading">Envíos y stock</h2>',
		$c
	);
	$c = preg_replace(
		'#<details class="akb-faq__item">\s*<summary>¿Cómo sé si un tomo está disponible\?</summary>.*?</details>#s',
		"<details class=\"akb-faq__item\">\n<summary>¿Cómo sé si un tomo está disponible?</summary>\n<div><p>Revisa el estado visible en cada ficha: <strong>Disponible</strong> significa que está en stock físico en bodega listo para despacho inmediato; y <strong>Agotado</strong> permite usar “Avísame cuando vuelva” para recibir un correo cuando se reponga.</p></div>\n</details>",
		$c
	);
	$c = preg_replace(
		'#\s*<details class="akb-faq__item">\s*<summary>¿Cómo funciona la preventa\?</summary>.*?</details>#s',
		'',
		$c
	);
	$c = preg_replace(
		'#\s*<details class="akb-faq__item">\s*<summary>¿Cómo sigo mi preventa\?</summary>.*?</details>#s',
		'',
		$c
	);
	return $c;
} );

// 8.2 Envíos
$update_page_by_slug( 'envios', function( string $c ): string {
	$c = str_replace(
		'<li>Agrega los productos al carrito y revisa si están disponibles o en preventa.</li>',
		'<li>Agrega los productos disponibles al carrito.</li>',
		$c
	);
	$c = preg_replace(
		'#\s*<section class="akb-page__section" aria-labelledby="envios-preventas">.*?</section>#s',
		'',
		$c
	);
	return $c;
} );

// 8.3 Devoluciones
$update_page_by_slug( 'devoluciones', function( string $c ): string {
	$c = preg_replace(
		'#\s*<div class="akb-info-card">[^<]*<div class="akb-info-card__icon".*?<p class="akb-info-card__label">Preventas</p>.*?</div>#s',
		'',
		$c
	);
	$c = str_replace(
		' <strong>Las preventas no entran aquí</strong>: un tomo en preventa es un producto de catálogo y conserva su derecho a retracto.',
		'',
		$c
	);
	$c = preg_replace(
		'#\s*<section class="akb-page__section">\s*<h2 class="akb-page__section-heading" id="preventas">6\. Preventas: atrasos y cancelación</h2>.*?</section>#s',
		'',
		$c
	);
	$c = str_replace(
		'<h2 class="akb-page__section-heading" id="garantia-legal">7. Garantía legal</h2>',
		'<h2 class="akb-page__section-heading" id="garantia-legal">6. Garantía legal</h2>',
		$c
	);
	$c = str_replace(
		'<h2 class="akb-page__section-heading" id="gastos-envio">8. Gastos de envío</h2>',
		'<h2 class="akb-page__section-heading" id="gastos-envio">7. Gastos de envío</h2>',
		$c
	);
	return $c;
} );

// 8.4 Términos y Condiciones
$update_page_by_slug( 'terminos-y-condiciones', function( string $c ): string {
	$c = preg_replace( '#\s*<li><a href="\#tc-preventas">Preventas</a></li>#', '', $c );
	$c = preg_replace(
		'#\s*<h2 id="tc-preventas">Preventas</h2>.*?(?=<h2 id="tc-devoluciones">)#s',
		"\n\n",
		$c
	);
	return $c;
} );

// 8.5 Política de Privacidad (Microsoft Clarity)
$update_page_by_slug( 'politica-de-privacidad', function( string $c ): string {
	$c = preg_replace(
		'#\s*<h3>Microsoft Clarity</h3>.*?(?=<h3>Google Analytics 4 y Google Ads</h3>)#s',
		"\n",
		$c
	);
	$c = str_replace(
		'usar los controles de exclusión de Microsoft y Google enlazados en esta política',
		'usar los controles de exclusión de Google enlazados en esta política',
		$c
	);
	$c = preg_replace(
		'#\s*<li><strong>Microsoft:</strong>.*?</li>#s',
		'',
		$c
	);
	$c = preg_replace(
		'#\s*<li><strong>Identificadores de Clarity:</strong>.*?</li>#s',
		'',
		$c
	);
	return $c;
} );

// 8.6 Akibara Puntos
$update_page_by_slug( 'akibara-puntos', function( string $c ): string {
	$c = str_replace( '<td>Sin cupones ni packs</td>', '<td>No acumulable con cupones</td>', $c );
	$c = str_replace(
		'en compras desde $30.000 CLP en mangas y cómics a precio normal o reserva «A pedido» (no acumulable con otros cupones ni con Arma tu Pack).',
		'en compras desde $30.000 CLP en mangas y cómics a precio normal (no acumulable con otros cupones).',
		$c
	);
	$c = str_replace(
		'en compras desde $30.000 en mangas y cómics a precio normal o reserva «A pedido».',
		'en compras desde $30.000 en mangas y cómics a precio normal.',
		$c
	);
	$c = preg_replace(
		'#<li><strong>Elige siempre tu mejor beneficio \(No acumulables con cupones ni packs\):</strong>.*?</li>#s',
		'<li><strong>No acumulables con cupones:</strong> Los puntos no se suman con cupones promocionales en un mismo pedido.</li>',
		$c
	);
	$c = preg_replace(
		'#<li><strong>Acreditación según disponibilidad física:</strong>.*?</li>#s',
		'<li><strong>Acreditación inmediata:</strong> Los puntos se acreditan automáticamente cuando el pedido queda confirmado en «Procesando». La parte del pedido pagada con puntos no genera nuevos puntos.</li>',
		$c
	);
	return $c;
} );

// 8.7 Programa de Referidos
$update_page_by_slug( 'programa-referidos', function( string $c ): string {
	$c = str_replace(
		'Válido en compras desde $30.000 CLP en mangas y cómics a precio normal o reserva «A pedido» (no acumulable con otros cupones ni con Arma tu Pack).',
		'Válido en compras desde $30.000 CLP en mangas y cómics a precio normal (no acumulable con otros cupones).',
		$c
	);
	$c = str_replace(
		'al alcanzar $30.000 en mangas y cómics a precio normal o reserva «A pedido».',
		'al alcanzar $30.000 en mangas y cómics a precio normal.',
		$c
	);
	return $c;
} );

// 8.8 Series (/series/)
$update_page_by_slug( 'series', function( string $c ): string {
	$c = str_replace(
		'con sus tomos disponibles y a pedido.',
		'con sus tomos disponibles en catálogo.',
		$c
	);
	return $c;
} );

// 8.9 Editoriales (/editoriales/)
$update_page_by_slug( 'editoriales', function( string $c ): string {
	$c = preg_replace(
		'#\s*<p><a href="[^"]*/ivrea/">Ivrea</a> y <a href="[^"]*/panini/">Panini</a> cuentan con páginas que separan sus ediciones de Argentina y España\. El país de edición puede cambiar la traducción, el formato, la numeración y el ISBN\.</p>#s',
		"\n<p>Puedes filtrar el directorio por país de edición (Argentina o España), ya que el país de origen puede cambiar la traducción, el formato, la numeración y el ISBN.</p>",
		$c
	);
	return $c;
} );

// 8.10 Nosotros (/nosotros/)
$update_page_by_slug( 'nosotros', function( string $c ): string {
	$c = str_replace(
		'<h2 class="akb-page__section-heading">A pedido, preventas y guías de lectura</h2>',
		'<h2 class="akb-page__section-heading">Catálogo y guías de lectura</h2>',
		$c
	);
	$c = preg_replace(
		'#<p>En <a href="/a-pedido/">A pedido</a> puedes reservar títulos por encargo a la editorial o en preventa antes de su llegada\. Cada ficha muestra la ventana estimada de llegada y el estado del producto\.</p>\s*#s',
		'',
		$c
	);
	$c = str_replace(
		'sobre una edición, una preventa o un pedido,',
		'sobre una edición, el stock o un pedido,',
		$c
	);
	return $c;
} );

// 8.11 Inicio (home-1)
$update_page_by_slug( 'home-1', function( string $c ): string {
	$c = preg_replace(
		'#\s*<!-- wp:group \{"tagName":"section","align":"full","className":"akb-section akb-preventas".*?<!-- /wp:group -->#s',
		'',
		$c
	);
	return $c;
} );

// 8.12 Checkout (checkout)
$update_page_by_slug( 'checkout', function( string $c ): string {
	$c = preg_replace( '#\s*<!-- wp:akibara/checkout-preventa-summary /-->#', '', $c );
	return $c;
} );

echo "\n=== PASO 8.13: Limpieza de Metadatos SEO (Rank Math) en Páginas y Términos ===\n";
$rm_post_replacements = array(
	'disponibles y a pedido' => 'disponibles en stock',
	'Envíos, stock y preventas: preguntas frecuentes | Akibara' => 'Envíos, stock y preguntas frecuentes | Akibara',
	'pagos, preventas, seguimiento' => 'pagos, seguimiento',
	'Akibara | Preventas, novedades y tomos originales' => 'Akibara | Novedades, manga y cómics originales',
	'manga, cómics, tomos a pedido, preventas y soporte de envíos' => 'manga, cómics, catálogo en stock y soporte de envíos',
);
foreach ( $rm_post_replacements as $search => $replace ) {
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key LIKE 'rank_math_%' AND meta_value LIKE %s",
		$search,
		$replace,
		'%' . $wpdb->esc_like( $search ) . '%'
	) );
}

$rm_term_replacements = array(
	'disponibilidad, preventas, despacho' => 'disponibilidad, despacho',
	'disponibilidad y preventas en Akibara' => 'disponibilidad en Akibara',
	'Stock y preventa de la serie' => 'Stock disponible de la serie',
	'Stock disponible y preventas con envío' => 'Stock disponible con envío',
	'Compra a pedido con envíos protegidos' => 'Compra online con envíos protegidos',
	'Disponible a pedido en Akibara Chile' => 'Disponible en Akibara Chile',
);
foreach ( $rm_term_replacements as $search => $replace ) {
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->termmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key LIKE 'rank_math_%' AND meta_value LIKE %s",
		$search,
		$replace,
		'%' . $wpdb->esc_like( $search ) . '%'
	) );
}

// Alineación de metadatos de edición en pa_serie con estado canónico de producción:
$series_edition_fixes = array(
	'saga-integral'                      => array(
		'_akb_serie_estado_edicion' => 'en_pausa',
		'_akb_serie_estado'         => 'en_pausa',
	),
	'made-in-abyss'                      => array(
		'_akb_serie_estado_edicion' => 'al_dia',
	),
	'one-piece-no'                       => array(
		'_akb_serie_estado_edicion' => 'en_publicacion',
		'_akb_serie_tomos_edicion'  => '0',
	),
	'el-chico-que-me-gusta-no-es-un-chico' => array(
		'_akb_serie_estado_edicion' => 'en_publicacion',
		'_akb_serie_tomos_edicion'  => '0',
	),
);
foreach ( $series_edition_fixes as $serie_slug => $meta_updates ) {
	$serie_term = get_term_by( 'slug', $serie_slug, 'pa_serie' );
	if ( $serie_term && ! is_wp_error( $serie_term ) ) {
		foreach ( $meta_updates as $m_key => $m_val ) {
			if ( '0' === $m_val ) {
				delete_term_meta( (int) $serie_term->term_id, $m_key );
			} else {
				update_term_meta( (int) $serie_term->term_id, $m_key, $m_val );
			}
		}
	}
}
delete_transient( 'akb_series_directory_v4' );

// Limpieza de mención editorial redundante en post_excerpt de Saga Integral 2 (ID 17149):
$p17149 = get_post( 17149 );
if ( $p17149 && str_contains( (string) $p17149->post_excerpt, ' - Planeta España' ) ) {
	$wpdb->update(
		$wpdb->posts,
		array( 'post_excerpt' => str_replace( ' - Planeta España', '', (string) $p17149->post_excerpt ) ),
		array( 'ID' => 17149 )
	);
	clean_post_cache( 17149 );
	echo "post_excerpt de Saga Integral 2 (ID 17149) limpiado.\n";
}

echo "\n=== PASO 9: Reconstruir índice de búsqueda y purgar caché ===\n";
if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::runcommand( 'akb-search rebuild' );
	\WP_CLI::runcommand( 'rewrite flush' );
	\WP_CLI::runcommand( 'cache flush' );
}

echo "\n=== FIN DEL PURGADO ===\n";
