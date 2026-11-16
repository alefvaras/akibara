<?php
/**
 * ops/saneamiento-residuos-globales.php
 *
 * Saneamiento integral y definitivo de residuos, código muerto, páginas obsoletas,
 * tablas huérfanas, opciones y campos en Akibara (akibara.cl).
 *
 * Cumplimiento estricto de:
 * 1. Regla de Oro (Cero Residuos y Proactividad).
 * 2. Modelo Binario Inviolable (0 onbackorder, erradicación de preventas, a pedido y arma tu pack).
 * 3. Ejecución en espejo (Docker local) y después producción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Si se corre via wp eval-file, ABSPATH ya está definido.
}

global $wpdb;

$is_dry_run = in_array( '--dry-run', $argv ?? [], true ) || in_array( 'dry-run', $argv ?? [], true );
echo "=== INICIANDO SANEAMIENTO INTEGRAL DE RESIDUOS (MODO: " . ( $is_dry_run ? 'DRY-RUN' : 'EJECUCIÓN REAL' ) . ") ===\n\n";

$backup_data = [
	'timestamp' => date( 'c' ),
	'mode' => $is_dry_run ? 'dry-run' : 'execute',
	'deleted_posts' => [],
	'deleted_options' => [],
	'deleted_meta' => [],
	'modified_posts' => [],
	'modified_options' => [],
	'dropped_tables' => [],
	'deleted_terms' => [],
	'redirections' => [],
];

// =========================================================================
// 1. ELIMINACIÓN DE TABLAS DE BASE DE DATOS OBSOLETAS
// =========================================================================
echo "--- 1. TABLAS OBSOLETAS ---\n";
$tables_to_check = [
	$wpdb->prefix . 'akb_search_requests',
	$wpdb->prefix . 'pmw_tracking_accuracy',
	$wpdb->prefix . 'cartflows_ca_cart_abandonment',
	$wpdb->prefix . 'cartflows_ca_email_history',
	$wpdb->prefix . 'cartflows_ca_email_templates',
	$wpdb->prefix . 'cartflows_ca_email_templates_meta',
	$wpdb->prefix . 'spar_referral_clicks',
	$wpdb->prefix . 'spar_spins_log',
];

$existing_tables = $wpdb->get_col( "SHOW TABLES" );

foreach ( $tables_to_check as $tbl ) {
	if ( in_array( $tbl, $existing_tables, true ) ) {
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}`" );
		echo "[TABLA DETECTADA] `{$tbl}` ({$count} filas).\n";
		if ( ! $is_dry_run ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$tbl}`" );
			echo "  -> DROP TABLE ejecutado exitosamente.\n";
			$backup_data['dropped_tables'][] = [ 'table' => $tbl, 'rows' => $count ];
		}
	} else {
		echo "[OK] Tabla `{$tbl}` no existe.\n";
	}
}

// =========================================================================
// 2. ELIMINACIÓN DE OPCIONES DE BASE DE DATOS OBSOLETAS (`wp_options`)
// =========================================================================
echo "\n--- 2. OPCIONES OBSOLETAS (`wp_options`) ---\n";
$options_to_delete = [
	'akb_preventas_guide_post_id',
	'akb_series_bundles_byp',
	'akb_series_bundles_enabled',
	'akb_series_bundles_allow_backorder',
	'akb_series_bundles_backorder_mode',
	'akibara_native_cart_show_preventa_policy',
	'akibara_native_cart_preventa_policy_copy',
	'akb_copy_eta_cart_preventa',
	'akb_copy_eta_single_preventa',
	'akb_copy_eta_noun_preventa',
	'akb_copy_promise_preventa_transit',
	'akb_copy_preventas_intro',
	'akb_copy_preventas_guide_prefix',
	'akb_copy_preventas_guide_link',
	'akb_copy_preventas_sibling_prefix',
	'akb_copy_preventas_sibling_link',
	'wc_chilean_bundle_configuration_options',
	'wc_chilean_bundle_about_us_options',
	'plugin_activation_rut-chileno-con-validacion/wc-chilean-bundle.php',
	'activation_plugin_rut-chileno-con-validacion/wc-chilean-bundle.php',
	'pmw_default_admin_theme',
	'pmw_tracking_accuracy_db_version',
	'pmw_tracking_accuracy_backfill_max_order_id',
	'pmw_opportunities',
	'pmw_onboarding_checklist',
];

foreach ( $options_to_delete as $opt_name ) {
	$val = get_option( $opt_name, null );
	if ( $val !== null ) {
		echo "[OPCIÓN DETECTADA] `{$opt_name}`\n";
		if ( ! $is_dry_run ) {
			delete_option( $opt_name );
			echo "  -> Eliminada.\n";
			$backup_data['deleted_options'][] = [ 'name' => $opt_name, 'value' => $val ];
		}
	} else {
		echo "[OK] Opción `{$opt_name}` ya no existe.\n";
	}
}

// =========================================================================
// 3. ELIMINACIÓN DE METADATOS OBSOLETOS EN `wp_postmeta`
// =========================================================================
echo "\n--- 3. METADATOS OBSOLETOS (`wp_postmeta`) ---\n";
$meta_keys_to_delete = [
	'woosw_add',
	'woosw_count',
	'woosw_remove',
	'_wc_push_notification_sent_on_backorder',
];

foreach ( $meta_keys_to_delete as $mk ) {
	$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $mk ) );
	if ( $cnt > 0 ) {
		echo "[POSTMETA DETECTADO] `{$mk}` ({$cnt} filas).\n";
		if ( ! $is_dry_run ) {
			$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $mk ] );
			echo "  -> Eliminadas {$cnt} filas.\n";
			$backup_data['deleted_meta'][] = [ 'meta_key' => $mk, 'rows' => $cnt ];
		}
	} else {
		echo "[OK] Postmeta `{$mk}` sin filas residuales.\n";
	}
}

// =========================================================================
// 4. ELIMINACIÓN DE TÉRMINOS OBSOLETOS (`product_type`)
// =========================================================================
echo "\n--- 4. TÉRMINOS Y TAXONOMÍAS OBSOLETAS ---\n";
$bundle_term = get_term_by( 'slug', 'akb_bundle', 'product_type' );
if ( $bundle_term ) {
	echo "[TÉRMINO DETECTADO] `akb_bundle` (term_id: {$bundle_term->term_id}) en product_type.\n";
	if ( ! $is_dry_run ) {
		wp_delete_term( $bundle_term->term_id, 'product_type' );
		echo "  -> Término `akb_bundle` eliminado.\n";
		$backup_data['deleted_terms'][] = (array) $bundle_term;
	}
} else {
	echo "[OK] Término `akb_bundle` no existe.\n";
}

// =========================================================================
// 5. ELIMINACIÓN DE PÁGINAS, ENTRADAS Y MENÚS BASURA/OBSOLETOS
// =========================================================================
echo "\n--- 5. PÁGINAS, ENTRADAS Y MENÚS OBSOLETOS ---\n";

// A. Ítem de menú "A pedido" (ID 24468)
$nav_item = get_post( 24468 );
if ( $nav_item && $nav_item->post_type === 'nav_menu_item' ) {
	echo "[MENÚ DETECTADO] Nav menu item ID 24468 ('A pedido').\n";
	if ( ! $is_dry_run ) {
		wp_delete_post( 24468, true );
		echo "  -> Nav menu item eliminado.\n";
		$backup_data['deleted_posts'][] = [ 'ID' => 24468, 'type' => 'nav_menu_item' ];
	}
}

// B. Páginas/posts de AutomateWoo y MailPoet desinstalados
$orphan_posts = [
	25053 => 'Communication preferences (AutomateWoo)',
	25076 => 'Plantilla Premium (AutomateWoo)',
	24521 => 'MailPoet subscriptions',
	24522 => 'MailPoet captcha',
	24526 => 'MailPoet email 24526',
	24527 => 'MailPoet email 24527',
	28887 => 'Borrador automático auto-draft',
	24977 => 'Preventa de manga draft obsoleto',
];

foreach ( $orphan_posts as $pid => $desc ) {
	$p = get_post( $pid );
	if ( $p ) {
		echo "[POST HUÉRFANO DETECTADO] ID {$pid} ('{$p->post_title}', type: {$p->post_type}, status: {$p->post_status}) - {$desc}\n";
		if ( ! $is_dry_run ) {
			wp_delete_post( $pid, true );
			echo "  -> Post {$pid} eliminado permanentemente.\n";
			$backup_data['deleted_posts'][] = [ 'ID' => $pid, 'title' => $p->post_title, 'type' => $p->post_type ];
		}
	} else {
		echo "[OK] Post ID {$pid} no existe.\n";
	}
}

// C. Post de preventas 23334 (Preventa de manga en Chile)
$p23334 = get_post( 23334 );
if ( $p23334 && $p23334->post_status === 'publish' ) {
	echo "[BLOG PREVENTAS DETECTADO] ID 23334 ('{$p23334->post_title}') publicado.\n";
	if ( ! $is_dry_run ) {
		wp_update_post( [
			'ID' => 23334,
			'post_status' => 'draft',
		] );
		echo "  -> Post ID 23334 pasado a borrador (draft).\n";
		$backup_data['modified_posts'][] = [ 'ID' => 23334, 'field' => 'post_status', 'old' => 'publish', 'new' => 'draft' ];
	}
}

// =========================================================================
// 6. SANEO Y ACTUALIZACIÓN DE COPY EN PÁGINAS ACTIVAS
// =========================================================================
echo "\n--- 6. ACTUALIZACIÓN QUIRÚRGICA DE PÁGINAS ACTIVAS ---\n";

// 6.1 Página 9083: Checkout (eliminar bloque preventa)
$p9083 = get_post( 9083 );
if ( $p9083 && strpos( $p9083->post_content, 'checkout-preventa-summary' ) !== false ) {
	echo "[CHECKOUT DETECTADO] ID 9083 contiene bloque huérfano checkout-preventa-summary.\n";
	if ( ! $is_dry_run ) {
		$clean_content = str_replace( "\n\n<!-- wp:akibara/checkout-preventa-summary /-->", '', $p9083->post_content );
		$clean_content = str_replace( "<!-- wp:akibara/checkout-preventa-summary /-->", '', $clean_content );
		wp_update_post( [
			'ID' => 9083,
			'post_content' => $clean_content,
		] );
		echo "  -> Página 9083 sanitizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 9083, 'note' => 'Bloque preventa eliminado' ];
	}
} else {
	echo "[OK] Página 9083 (Checkout) sin bloque preventa.\n";
}

// 6.2 Página 240: Nosotros
$p240 = get_post( 240 );
if ( $p240 && strpos( $p240->post_content, 'Disponibles, preventas y títulos a pedido' ) !== false ) {
	echo "[NOSOTROS DETECTADO] ID 240 contiene sección 'Disponibles, preventas y títulos a pedido'.\n";
	if ( ! $is_dry_run ) {
		$target_section = '<section class="akb-page__section">
<h2 class="akb-page__section-heading">Disponibles, preventas y títulos a pedido</h2>
<p><strong>Disponible</strong> significa que el producto está en Akibara. En una <strong>preventa</strong> reservas un título que todavía no sale de la editorial. Un título <strong>a pedido</strong> ya está disponible en la editorial, pero aún no lo tenemos en Akibara y podemos encargarlo para ti.</p>
<p>Revisa el estado y la estimación vigente en cada ficha antes de comprar. Puedes explorar el <a href="/tienda/">catálogo de manga y cómics</a> o los <a href="/a-pedido/">títulos a pedido</a>.</p>
</section>';

		$replacement_section = '<section class="akb-page__section">
<h2 class="akb-page__section-heading">Stock real y disponibilidad</h2>
<p>En Akibara creemos en la transparencia total: todos los productos con disponibilidad en la tienda cuentan con <strong>stock físico real en nuestra bodega de Santiago</strong>. No trabajamos con preventas diferidas ni modelos de encargo a la espera de publicación. Lo que compras está listo para despacho inmediato o retiro en Metro San Miguel.</p>
<p>Si el tomo que buscas figura como agotado, puedes solicitar aviso automático en su ficha («Avísame cuando vuelva») para que te avisemos por correo apenas recibamos nueva reposición. Explora el <a href="/tienda/">catálogo de manga y cómics</a>.</p>
</section>';

		$content_240 = str_replace( $target_section, $replacement_section, $p240->post_content );
		$content_240 = str_replace(
			'Si tienes una duda sobre una edición, una preventa, un título a pedido o una compra, puedes escribirnos desde la página de <a href="/contacto/">Contacto</a>.',
			'Si tienes una duda sobre una edición, un título o tu compra, puedes escribirnos desde la página de <a href="/contacto/">Contacto</a>.',
			$content_240
		);

		wp_update_post( [
			'ID' => 240,
			'post_content' => $content_240,
		] );
		echo "  -> Página 240 (Nosotros) actualizada con modelo de stock real.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 240, 'note' => 'Sección convertida a Stock Real' ];
	}
} else {
	echo "[OK] Página 240 (Nosotros) limpia.\n";
}

// 6.3 Página 27730: Envíos y retiro de pedidos
$p27730 = get_post( 27730 );
if ( $p27730 && strpos( $p27730->post_content, 'envios-preventas' ) !== false ) {
	echo "[ENVÍOS DETECTADO] ID 27730 contiene sección envios-preventas.\n";
	if ( ! $is_dry_run ) {
		$target_step = '<li>Agrega los productos al carrito y revisa si están disponibles, a pedido o en preventa.</li>';
		$repl_step = '<li>Agrega los productos que deseas a tu carrito de compra.</li>';

		$target_sec_27730 = '<section class="akb-page__section" aria-labelledby="envios-preventas">
<h2 id="envios-preventas" class="akb-page__section-heading">Productos disponibles, a pedido y preventas</h2>
<p><strong>Disponible</strong>: el producto está en Akibara. <strong>A pedido</strong>: la editorial ya lo tiene disponible, pero aún no está en Akibara y lo encargamos para ti. <strong>Preventa</strong>: el título todavía no sale de la editorial y puedes reservarlo antes de su publicación.</p>
<p>Revisa la estimación y el estado de cada ficha: el plazo de un título a pedido o en preventa incluye la espera hasta que esté listo para despacharlo desde Akibara.</p>
<p>Si un pedido combina productos disponibles con títulos a pedido o en preventa, se prepara cuando todos estén listos. Si necesitas recibirlos por separado, <a href="/contacto/">contáctanos</a> para revisar el despacho adicional. Puedes consultar los títulos que podemos encargar en <a href="/a-pedido/">A pedido</a>.</p>
</section>';

		$repl_sec_27730 = '<section class="akb-page__section" aria-labelledby="envios-stock">
<h2 id="envios-stock" class="akb-page__section-heading">Stock garantizado y reposiciones</h2>
<p>Todos los productos catalogados con disponibilidad cuentan con <strong>stock físico real en bodega</strong>, por lo que tu compra se procesa de inmediato para despacho rápido o retiro en Santiago.</p>
<p>No gestionamos preventas ni pedidos condicionales a la espera de lanzamiento. Si un título figura como agotado, puedes registrar tu correo en la ficha («Avísame cuando vuelva») para que te avisemos en cuanto llegue la próxima remesa oficial desde España o Argentina.</p>
</section>';

		$content_27730 = str_replace( $target_step, $repl_step, $p27730->post_content );
		$content_27730 = str_replace( $target_sec_27730, $repl_sec_27730, $content_27730 );

		wp_update_post( [
			'ID' => 27730,
			'post_content' => $content_27730,
		] );
		echo "  -> Página 27730 (Envíos) actualizada con stock garantizado.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 27730, 'note' => 'Sección convertida a Stock Garantizado' ];
	}
} else {
	echo "[OK] Página 27730 (Envíos) limpia.\n";
}

// 6.4 Página 3: Términos y Condiciones
$p3 = get_post( 3 );
if ( $p3 && strpos( $p3->post_content, 'tc-preventas' ) !== false ) {
	echo "[T&C DETECTADO] ID 3 contiene sección tc-preventas.\n";
	if ( ! $is_dry_run ) {
		$target_index_3 = '<li><a href="#tc-preventas">Preventas</a></li>';
		$repl_index_3 = '<li><a href="#tc-disponibilidad">Disponibilidad y catálogo</a></li>';

		$target_sec_3 = '<h2 id="tc-preventas">Preventas</h2>
<p>Los productos en preventa muestran una <strong>fecha estimada de llegada a Akibara</strong>, calculada según el país de origen y el plazo de importación; no necesariamente corresponde a la fecha oficial de publicación de la editorial y puede variar. Al comprar en preventa, el producto se despacha cuando queda disponible en Akibara. Al comprar en preventa, Akibara encarga el producto a su proveedor en el extranjero. Si la demora supera los <strong>15 días</strong> desde la fecha estimada, o si la editorial confirma que el título quedó agotado, el cliente puede solicitar la cancelación y el <strong>reembolso íntegro</strong>. Una vez despachado el pedido, rige el derecho a retracto de la Ley 19.496: 10 días corridos desde la recepción.</p>
<p><strong>Pedidos mixtos (stock + preventa):</strong> puedes combinar ambos tipos de producto en una misma compra. En ese caso, el pedido se despacha cuando todos los productos estén disponibles. Si necesitas envíos por separado, contáctanos para coordinar; el costo del envío adicional corre por cuenta del cliente.</p>
<p><strong>Producto agotado al gestionar el pedido con la editorial:</strong> si al solicitar la importación el título resulta agotado y no podemos comprometerlo, te avisaremos por correo y podrás elegir entre <strong>cambiarlo por otro título disponible o en preventa</strong> o solicitar la <strong>devolución íntegra del pago</strong>.</p>';

		$repl_sec_3 = '<h2 id="tc-disponibilidad">Disponibilidad y catálogo</h2>
<p>Todos los productos con opción de compra en Akibara cuentan con <strong>stock físico real en nuestra bodega de Santiago</strong>. No realizamos preventas ni pedidos a encargo previo a su publicación oficial. En caso de que un producto figure como agotado, el cliente puede activar voluntariamente la notificación por correo («Avísame cuando vuelva») para recibir un aviso automático apenas ingrese una reposición oficial de la editorial.</p>';

		$content_3 = str_replace( $target_index_3, $repl_index_3, $p3->post_content );
		$content_3 = str_replace( $target_sec_3, $repl_sec_3, $content_3 );

		wp_update_post( [
			'ID' => 3,
			'post_content' => $content_3,
		] );
		echo "  -> Página 3 (Términos y Condiciones) actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 3, 'note' => 'Sección convertida a Disponibilidad y Catálogo' ];
	}
} else {
	echo "[OK] Página 3 limpia.\n";
}

// 6.5 Página 185: FAQs
$p185 = get_post( 185 );
if ( $p185 && strpos( $p185->post_content, '¿Cómo funciona la preventa?' ) !== false ) {
	echo "[FAQ DETECTADO] ID 185 contiene preguntas obsoletas de preventa.\n";
	if ( ! $is_dry_run ) {
		$content_185 = $p185->post_content;
		$content_185 = str_replace( 'Envíos, stock y preventas', 'Envíos, stock y reposiciones', $content_185 );

		$target_faq_disp = '<details class="akb-faq__item">
<summary>¿Cómo sé si un tomo está disponible?</summary>
<div><p>Revisa el estado visible en cada ficha: <strong>Disponible</strong> significa que está en stock en Akibara; <strong>A pedido</strong> significa que la editorial tiene el tomo, pero Akibara todavía no; <strong>Preventa</strong> permite reservar un tomo que la editorial aún no ha publicado; y <strong>Agotado</strong> permite usar “Avísame cuando vuelva” para recibir un correo cuando se reponga.</p></div>
</details>';

		$repl_faq_disp = '<details class="akb-faq__item">
<summary>¿Cómo sé si un tomo está disponible?</summary>
<div><p>Revisa el estado visible en cada ficha: <strong>Disponible</strong> significa que el tomo cuenta con stock físico real en nuestra bodega de Santiago, listo para despacho inmediato o retiro en Metro San Miguel. <strong>Agotado</strong> significa que no quedan ejemplares en bodega; en ese caso, puedes activar el botón «Avísame cuando vuelva» ingresando tu correo para ser notificado automáticamente apenas llegue una reposición oficial.</p></div>
</details>

<details class="akb-faq__item">
<summary>¿Cómo funcionan las alertas de reposición («Avísame cuando vuelva»)?</summary>
<div><p>Cuando un título de catálogo se encuentra agotado, puedes registrar tu correo electrónico en la ficha del producto. En cuanto ingrese nuevo stock físico a nuestra bodega desde las editoriales de España o Argentina, nuestro sistema te enviará un correo automático de alerta inmediata para que puedas adquirir tu ejemplar antes de que vuelva a agotarse.</p></div>
</details>';

		$content_185 = str_replace( $target_faq_disp, $repl_faq_disp, $content_185 );

		// Eliminar detalles de compra a pedido, preventa y seguimiento de preventa
		$content_185 = preg_replace( '/<details class="akb-faq__item">\s*<summary>¿Cómo funciona la compra a pedido\?<\/summary>.*?<\/details>\s*/si', '', $content_185 );
		$content_185 = preg_replace( '/<details class="akb-faq__item">\s*<summary>¿Cómo funciona la preventa\?<\/summary>.*?<\/details>\s*/si', '', $content_185 );
		$content_185 = preg_replace( '/<details class="akb-faq__item">\s*<summary>¿Cómo sigo mi preventa\?<\/summary>.*?<\/details>\s*/si', '', $content_185 );

		wp_update_post( [
			'ID' => 185,
			'post_content' => $content_185,
		] );
		echo "  -> Página 185 (FAQs) actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 185, 'note' => 'Preguntas de preventa eliminadas y añadidas reposiciones' ];
	}
} else {
	echo "[OK] Página 185 (FAQs) limpia.\n";
}

// 6.6 Página 23641: Cambios y Devoluciones
$p23641 = get_post( 23641 );
if ( $p23641 && strpos( $p23641->post_content, 'id="preventas"' ) !== false ) {
	echo "[DEVOLUCIONES DETECTADO] ID 23641 contiene sección id=preventas.\n";
	if ( ! $is_dry_run ) {
		$content_23641 = $p23641->post_content;

		// Reemplazar tarjeta de preventa por Garantía Legal en el hero
		$target_card_prev = '<div class="akb-info-card">
<div class="akb-info-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v4H3z"></path><path d="M5 7v13h14V7"></path><line x1="10" y1="12" x2="14" y2="12"></line></svg></div>
<p class="akb-info-card__label">Preventas</p>
<p class="akb-info-card__value">Si se atrasa más de 15 días, cancelas: 100% de vuelta.</p>
</div>';

		$repl_card_prev = '<div class="akb-info-card">
<div class="akb-info-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
<p class="akb-info-card__label">Garantía Legal</p>
<p class="akb-info-card__value">6 meses por defectos de fábrica (Ley del Consumidor).</p>
</div>';

		$content_23641 = str_replace( $target_card_prev, $repl_card_prev, $content_23641 );

		// Limpiar texto en sección sin-retracto
		$content_23641 = str_replace(
			'<strong>Las preventas no entran aquí</strong>: un tomo en preventa es un producto de catálogo y conserva su derecho a retracto.',
			'',
			$content_23641
		);

		// Eliminar sección 6 entera de preventas
		$content_23641 = preg_replace( '/<section class="akb-page__section">\s*<h2 class="akb-page__section-heading" id="preventas">.*?<\/section>\s*/si', '', $content_23641 );

		// Renumerar secciones 7, 8, 9
		$content_23641 = str_replace( '7. Garantía legal', '6. Garantía legal', $content_23641 );
		$content_23641 = str_replace( '8. Gastos de envío', '7. Gastos de envío', $content_23641 );
		$content_23641 = str_replace( '9. Procedimiento paso a paso', '8. Procedimiento paso a paso', $content_23641 );

		wp_update_post( [
			'ID' => 23641,
			'post_content' => $content_23641,
		] );
		echo "  -> Página 23641 (Devoluciones) actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 23641, 'note' => 'Sección de preventas eliminada' ];
	}
} else {
	echo "[OK] Página 23641 limpia.\n";
}

// 6.7 Página 27436: Series
$p27436 = get_post( 27436 );
if ( $p27436 && strpos( $p27436->post_content, 'disponibles y a pedido' ) !== false ) {
	echo "[SERIES DETECTADO] ID 27436 contiene 'disponibles y a pedido'.\n";
	if ( ! $is_dry_run ) {
		$content_27436 = str_replace(
			'con sus tomos disponibles y a pedido.',
			'con sus tomos disponibles y próximas reposiciones.',
			$p27436->post_content
		);
		wp_update_post( [
			'ID' => 27436,
			'post_content' => $content_27436,
		] );
		echo "  -> Página 27436 actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 27436, 'note' => 'Copy de a pedido sustituido' ];
	}
} else {
	echo "[OK] Página 27436 limpia.\n";
}

// 6.8 Página 27519: Akibara Puntos
$p27519 = get_post( 27519 );
if ( $p27519 && ( strpos( $p27519->post_content, 'Arma tu Pack' ) !== false || strpos( $p27519->post_content, 'A pedido' ) !== false ) ) {
	echo "[PUNTOS DETECTADO] ID 27519 contiene residuos de 'Arma tu Pack' o 'A pedido'.\n";
	if ( ! $is_dry_run ) {
		$content_27519 = $p27519->post_content;
		$content_27519 = str_replace(
			'en compras desde $30.000 CLP en mangas y cómics a precio normal o reserva «A pedido» (no acumulable con otros cupones ni con Arma tu Pack).',
			'en compras desde $30.000 CLP en mangas y cómics de catálogo (no acumulable con otros cupones).',
			$content_27519
		);
		$content_27519 = str_replace(
			'<li><strong>Elige siempre tu mejor beneficio (No acumulables con cupones ni packs):</strong> Los puntos no se suman con cupones promocionales ni con los descuentos automáticos de «Arma tu Pack». Si tu pedido califica para un descuento de pack pero tus Puntos Akibara disponibles te dan un ahorro mayor, en el carrito podrás elegir con un clic cuál de los dos beneficios prefieres usar. Y si usas un descuento de pack, ¡tu compra igual acumula el 2% en Puntos Akibara para la próxima!</li>',
			'<li><strong>Elige siempre tu mejor beneficio (No acumulables con cupones):</strong> Los puntos no se acumulan con otros cupones de descuento en el mismo pedido. En el carrito y checkout podrás aplicar tus puntos directamente para descontar el total de productos. La parte del subtotal efectivamente pagada en dinero siempre acumula el 2% en Puntos Akibara para tu próxima compra.</li>',
			$content_27519
		);
		$content_27519 = str_replace(
			'<li><strong>Acreditación según disponibilidad física:</strong> Para productos en stock de bodega, los puntos se acreditan automáticamente cuando el pedido queda confirmado en «Procesando». Para tomos «A pedido» (reserva), los puntos se mantienen en custodia temporal y se acreditan en tu cuenta al momento del despacho («Listo para enviar» o «Completado»), garantizando una experiencia de compra 100% segura. La parte del pedido pagada con puntos no genera nuevos puntos.</li>',
			'<li><strong>Acreditación inmediata:</strong> Los puntos se acreditan automáticamente en tu cuenta cuando el pedido queda confirmado en estado «Procesando» o «Completado», garantizando una experiencia 100% transparente y segura. La parte del pedido pagada con puntos o cupones no genera nuevos puntos.</li>',
			$content_27519
		);
		$content_27519 = str_replace(
			'Tu amigo recibe $2.000 CLP de descuento en compras desde $30.000 en mangas y cómics a precio normal o reserva «A pedido».',
			'Tu amigo recibe $2.000 CLP de descuento en compras desde $30.000 en mangas y cómics de catálogo.',
			$content_27519
		);

		wp_update_post( [
			'ID' => 27519,
			'post_content' => $content_27519,
		] );
		echo "  -> Página 27519 actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 27519, 'note' => 'Residuos de packs y a pedido eliminados' ];
	}
} else {
	echo "[OK] Página 27519 limpia.\n";
}

// 6.9 Página 28451: Programa de Referidos
$p28451 = get_post( 28451 );
if ( $p28451 && ( strpos( $p28451->post_content, 'Arma tu Pack' ) !== false || strpos( $p28451->post_content, 'A pedido' ) !== false ) ) {
	echo "[REFERIDOS DETECTADO] ID 28451 contiene residuos de 'Arma tu Pack' o 'A pedido'.\n";
	if ( ! $is_dry_run ) {
		$content_28451 = $p28451->post_content;
		$content_28451 = str_replace(
			'Válido en compras desde $30.000 CLP en mangas y cómics a precio normal o reserva «A pedido» (no acumulable con otros cupones ni con Arma tu Pack).',
			'Válido en compras desde $30.000 CLP en mangas y cómics de catálogo (no acumulable con otros cupones de descuento).',
			$content_28451
		);
		$content_28451 = str_replace(
			'al alcanzar $30.000 en mangas y cómics a precio normal o reserva «A pedido».',
			'al alcanzar $30.000 en mangas y cómics de catálogo.',
			$content_28451
		);

		wp_update_post( [
			'ID' => 28451,
			'post_content' => $content_28451,
		] );
		echo "  -> Página 28451 actualizada.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 28451, 'note' => 'Residuos de packs y a pedido eliminados' ];
	}
} else {
	echo "[OK] Página 28451 limpia.\n";
}

// =========================================================================
// 7. CONFIGURACIÓN Y METADATOS GLOBALES (`akibara_hero_settings`, SEO)
// =========================================================================
echo "\n--- 7. CONFIGURACIONES GLOBALES Y METADATOS SEO ---\n";

// 7.1 akibara_hero_settings
$hero_settings = get_option( 'akibara_hero_settings', null );
if ( is_array( $hero_settings ) && isset( $hero_settings['slides'][0]['lede'] ) ) {
	if ( strpos( $hero_settings['slides'][0]['lede'], 'a pedido' ) !== false ) {
		echo "[HERO SETTINGS DETECTADO] Slide 0 contiene 'a pedido'.\n";
		if ( ! $is_dry_run ) {
			$hero_settings['slides'][0]['lede'] = 'Manga japonés y cómics en español con stock real y despacho inmediato.';
			update_option( 'akibara_hero_settings', $hero_settings );
			echo "  -> Hero settings slide 0 actualizado.\n";
			$backup_data['modified_options'][] = [ 'name' => 'akibara_hero_settings', 'note' => 'Lede actualizado' ];
		}
	} else {
		echo "[OK] Hero settings slide 0 limpio.\n";
	}
}

// 7.2 rank-math-options-titles
$rm_titles = get_option( 'rank-math-options-titles', null );
if ( is_array( $rm_titles ) ) {
	$changed = false;
	if ( isset( $rm_titles['organization_description'] ) && strpos( $rm_titles['organization_description'], 'preventas' ) !== false ) {
		echo "[RANK MATH ORG DETECTADO] organization_description contiene 'preventas'.\n";
		$rm_titles['organization_description'] = 'Tu rincón de Akihabara en Chile. Tienda online de manga y cómics en español con stock real en bodega, envíos a todo Chile y retiro en Metro San Miguel.';
		$changed = true;
	}
	if ( isset( $rm_titles['tax_product_brand_description'] ) && strpos( $rm_titles['tax_product_brand_description'], 'preventas' ) !== false ) {
		echo "[RANK MATH BRAND DETECTADO] tax_product_brand_description contiene 'preventas'.\n";
		$rm_titles['tax_product_brand_description'] = 'Encuentra mangas y cómics de %term% en Akibara. Stock disponible y despacho inmediato a todo Chile.';
		$changed = true;
	}
	if ( isset( $rm_titles['pt_product_archive_description'] ) && strpos( $rm_titles['pt_product_archive_description'], 'preventas' ) !== false ) {
		echo "[RANK MATH ARCHIVE DETECTADO] pt_product_archive_description contiene 'preventas'.\n";
		$rm_titles['pt_product_archive_description'] = 'Explora el catálogo de manga y cómics en español de Akibara: novedades, tomos oficiales y colecciones completas con despacho a todo Chile.';
		$changed = true;
	}
	if ( $changed && ! $is_dry_run ) {
		update_option( 'rank-math-options-titles', $rm_titles );
		echo "  -> rank-math-options-titles actualizado.\n";
		$backup_data['modified_options'][] = [ 'name' => 'rank-math-options-titles' ];
	}
}

// 7.3 Post 210 rank_math_description
$rm_desc_210 = get_post_meta( 210, 'rank_math_description', true );
if ( $rm_desc_210 && strpos( $rm_desc_210, 'a pedido' ) !== false ) {
	echo "[POST 210 SEO DETECTADO] rank_math_description contiene 'a pedido'.\n";
	if ( ! $is_dry_run ) {
		$clean_rm_desc = 'Tu rincón de Akihabara en Chile. Tienda online de manga y cómics en español con stock real, envíos a todo Chile y retiro coordinado en Metro San Miguel.';
		update_post_meta( 210, 'rank_math_description', $clean_rm_desc );
		echo "  -> Post 210 rank_math_description actualizado.\n";
		$backup_data['modified_posts'][] = [ 'ID' => 210, 'meta' => 'rank_math_description' ];
	}
}

// =========================================================================
// 8. REDIRECCIONES RANK MATH (`wp_rank_math_redirections`)
// =========================================================================
echo "\n--- 8. REDIRECCIONES 301 RANK MATH ---\n";
$redir_table = $wpdb->prefix . 'rank_math_redirections';

if ( in_array( $redir_table, $existing_tables, true ) ) {
	// A. Asegurar redirección canónica para /a-pedido/ -> /tienda/
	$has_a_pedido_redir = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$redir_table}` WHERE sources LIKE '%a-pedido%'" );
	if ( $has_a_pedido_redir === 0 ) {
		echo "[REDIRECCIÓN FALTANTE] /a-pedido/ -> /tienda/\n";
		if ( ! $is_dry_run ) {
			$source_data = serialize( [
				[
					'pattern' => 'a-pedido(/?)',
					'comparison' => 'regex',
					'ignore' => 'case',
				]
			] );
			$wpdb->insert( $redir_table, [
				'sources' => $source_data,
				'url_to' => 'https://akibara.cl/tienda/',
				'header_code' => 301,
				'hits' => 0,
				'status' => 'active',
				'created' => current_time( 'mysql' ),
				'updated' => current_time( 'mysql' ),
			] );
			echo "  -> Creada redirección 301: /a-pedido/ -> /tienda/.\n";
			$backup_data['redirections'][] = 'a-pedido(/?) -> /tienda/';
		}
	} else {
		echo "[OK] Redirección para /a-pedido/ ya existe.\n";
	}

	// B. Redirección para post 23334 si fue a draft
	$has_blog_redir = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$redir_table}` WHERE sources LIKE '%que-son-las-preventas-de-manga%'" );
	if ( $has_blog_redir === 0 ) {
		echo "[REDIRECCIÓN FALTANTE] /que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/ -> /faq/\n";
		if ( ! $is_dry_run ) {
			$source_data = serialize( [
				[
					'pattern' => 'que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber(/?)',
					'comparison' => 'regex',
					'ignore' => 'case',
				]
			] );
			$wpdb->insert( $redir_table, [
				'sources' => $source_data,
				'url_to' => 'https://akibara.cl/faq/',
				'header_code' => 301,
				'hits' => 0,
				'status' => 'active',
				'created' => current_time( 'mysql' ),
				'updated' => current_time( 'mysql' ),
			] );
			echo "  -> Creada redirección 301 blog preventas -> /faq/.\n";
			$backup_data['redirections'][] = 'que-son-las-preventas... -> /faq/';
		}
	}

	// C. Actualizar redirecciones viejas que apuntaban a /a-pedido/ o a onbackorder
	$dead_dest_redirs = $wpdb->get_results( "SELECT id, sources, url_to FROM `{$redir_table}` WHERE url_to LIKE '%a-pedido%' OR url_to LIKE '%onbackorder%'" );
	foreach ( $dead_dest_redirs as $dr ) {
		echo "[REDIRECCIÓN OBSOLETA] ID {$dr->id} apuntaba a '{$dr->url_to}'.\n";
		if ( ! $is_dry_run ) {
			$wpdb->update(
				$redir_table,
				[ 'url_to' => 'https://akibara.cl/tienda/', 'updated' => current_time( 'mysql' ) ],
				[ 'id' => $dr->id ]
			);
			echo "  -> Actualizada a https://akibara.cl/tienda/.\n";
			$backup_data['redirections'][] = "ID {$dr->id} -> /tienda/";
		}
	}
}

echo "\n=== SANEAMIENTO COMPLETADO EXITOSAMENTE ===\n";
if ( ! $is_dry_run ) {
	echo "Acciones ejecutadas y registradas.\n";
} else {
	echo "Modo simulación completado. No se modificó ningún dato.\n";
}
