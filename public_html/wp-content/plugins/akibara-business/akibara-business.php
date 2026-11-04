<?php
/**
 * Plugin Name:       Akibara Business
 * Plugin URI:        https://akibara.cl
 * Description:       Lógica de negocio Chile-específica para akibara.cl: validación RUT, fechas estimadas de llegada para preventas, identificación de pedidos con preventa, visibilidad cliente (email + mi-cuenta), directorio de editoriales, barra de anuncios y panel admin consolidado. Theme-agnostic.
 * Version:           1.120.6
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * WC requires at least: 10.0
 * WC tested up to:      11.0
 * Author:            Akibara
 * Author URI:        https://akibara.cl
 * License:           Proprietary
 * Update URI:        false
 * Requires Plugins:  woocommerce
 *
 * La tienda es monolingüe es-CL por política del dueño. Este plugin no carga
 * un dominio de traducción; los textos propios se escapan directamente.
 * I18nPolicyTest mantiene este contrato.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------

define( 'AKIBARA_BUSINESS_VERSION', '1.120.6' );
define( 'AKIBARA_BUSINESS_FILE', __FILE__ );
define( 'AKIBARA_BUSINESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_BUSINESS_URL', plugin_dir_url( __FILE__ ) );
define( 'AKIBARA_BUSINESS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AKIBARA_BUSINESS_MIN_WC', '10.0' );
define( 'AKIBARA_BUSINESS_MIN_PHP', '8.1' );

// ---------------------------------------------------------------------------
// HPOS + WC Blocks compatibility (declared before WC init).
// ---------------------------------------------------------------------------

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AKIBARA_BUSINESS_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				AKIBARA_BUSINESS_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------------
// Etiqueta de Google Ads de Google for WooCommerce.
// ---------------------------------------------------------------------------

// Ese plugin decide si imprime su etiqueta al registrar sus servicios, en
// `plugins_loaded:20` (GoogleListingsAndAdsPlugin::register). El arranque de
// éste corre en la MISMA prioridad, y a igual prioridad manda el orden de carga
// de los plugins: engancharse ahí sería una apuesta. Por eso la clase se carga a
// mano y se engancha en la 0 (ver `GoogleAdsTag`).
require_once AKIBARA_BUSINESS_DIR . 'src/Modules/SiteSpeed/GoogleAdsTag.php';
add_action( 'plugins_loaded', array( \Akibara\Business\Modules\SiteSpeed\GoogleAdsTag::class, 'register_early' ), 0 );

// ---------------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------------

/**
 * Verifica que WooCommerce esté activo y en la versión mínima soportada. Si no,
 * muestra admin notice y aborta el bootstrap.
 *
 * Akibara Business es theme-agnostic — solo usa hooks WC core. Funciona con
 * GeneratePress + child `akibara-gp` (recomendado, no enforced) o con cualquier
 * otro theme compatible con WC.
 *
 * @return bool True si todas las dependencias están disponibles.
 */
function akibara_business_check_dependencies(): bool {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo '<strong>Akibara Business</strong> requiere WooCommerce activo. ';
				echo 'Activa WooCommerce primero o desactiva Akibara Business.';
				echo '</p></div>';
			}
		);
		return false;
	}

	// El bootstrap exige AKIBARA_BUSINESS_MIN_WC: Requires Plugins no admite
	// versiones y la cabecera WC requires at least no sustituye este gate.
	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, AKIBARA_BUSINESS_MIN_WC, '<' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo '<strong>Akibara Business</strong> requiere WooCommerce ' . esc_html( AKIBARA_BUSINESS_MIN_WC ) . ' o superior. ';
				echo 'Versión activa: ' . esc_html( WC_VERSION ) . '. ';
				echo 'Actualiza WooCommerce o desactiva Akibara Business.';
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Inicializa el plugin después de que WordPress cargue todos los plugins.
 *
 * @return void
 */
function akibara_business_bootstrap(): void {
	if ( ! akibara_business_check_dependencies() ) {
		return;
	}

	// Autoload PSR-4 propio. NO hay rama `vendor/autoload.php`: el plugin no
	// declara dependencias de runtime y `.distignore` excluye `/vendor/` del
	// paquete, así que ese directorio no existe ni en producción ni en el árbol
	// local (composer instala en `tools/akibara-business/vendor`, sólo para las
	// pruebas). La rama que lo buscaba era inalcanzable y su comentario decía lo
	// contrario de la verdad: llamaba «fallback de dev» al único camino real.
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'Akibara\\Business\\';
			$base_dir = AKIBARA_BUSINESS_DIR . 'src/';

			if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);

	\Akibara\Business\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'akibara_business_bootstrap', 20 );

/**
 * Avisa al administrador cuando una tabla propia no se creó al activar.
 * El aviso se consume una sola vez y exige capacidad para actuar. Sin sello
 * de versión, el módulo reintenta la instalación; su log conserva los fallos.
 *
 * @return void
 */
function akibara_business_aviso_tablas_fallidas(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$fallidas = get_transient( 'akibara_business_tablas_fallidas' );
	if ( ! is_array( $fallidas ) || array() === $fallidas ) {
		return;
	}

	delete_transient( 'akibara_business_tablas_fallidas' );
	printf(
		'<div class="notice notice-error"><p><strong>Akibara Business</strong>: no se pudieron crear las tablas de %s. '
		. 'Esos módulos no funcionan hasta que se resuelva (permisos de la base de datos, normalmente). '
		. 'Se reintenta solo en cada visita y se arregla en cuanto se corrija la causa; el detalle del error queda en '
		. 'WooCommerce → Estado → Registros (fuentes akibara-wishlist, akibara-bis y akibara-series-follow).</p></div>',
		esc_html( implode( ', ', array_map( 'strval', $fallidas ) ) )
	);
}

add_action( 'admin_notices', 'akibara_business_aviso_tablas_fallidas' );

// ---------------------------------------------------------------------------
// Contrato del buscador: las funciones puente VIVEN en el plugin
// `akibara-search` (dueño del subsistema). Se movieron ahí en el switch para
// que el theme dependa del plugin, no de business. Son DIEZ, declaradas en
// `akibara-search.php`: `akibara_search_facets_active`, `_facets_fail_open`,
// `_facets_view`, `_facets_context`, `_suggest_active`, `_correction`,
// `_relaxed_notice`, `_empty_suggestions`, `_demand_gaps` y `_title_requests`.
//
// De esas diez, business consume DOS: `akibara_search_demand_gaps()` y
// `akibara_search_title_requests()`, tras un `function_exists()`, para el
// ranking de demanda y pedidos por encargo del Centro de Mando
// (`Modules/AdminDashboard/AdminDashboard.php`). Las demás las consumen el
// frontend del propio buscador y el theme.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Activation / Deactivation hooks.
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( version_compare( PHP_VERSION, AKIBARA_BUSINESS_MIN_PHP, '<' ) ) {
			deactivate_plugins( AKIBARA_BUSINESS_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						'Akibara Business requiere PHP %s o superior. Versión actual: %s.',
						AKIBARA_BUSINESS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( AKIBARA_BUSINESS_BASENAME );
			wp_die( 'Akibara Business requiere WooCommerce activo antes de la activación.' );
		}
		// Mismo contrato que el gate de runtime: activar sobre una rama de WC
		// anterior al mínimo dejaría el plugin instalado pero abortando en cada
		// request (check_dependencies retorna false), sin explicar por qué.
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, AKIBARA_BUSINESS_MIN_WC, '<' ) ) {
			deactivate_plugins( AKIBARA_BUSINESS_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						'Akibara Business requiere WooCommerce %s o superior. Versión activa: %s.',
						AKIBARA_BUSINESS_MIN_WC,
						WC_VERSION
					)
				)
			);
		}

		// Bootstrap del módulo Wishlist: schema + flush rewrites en activación.
		// El boot regular también hace maybe_install lazy en cada init, pero
		// crear acá garantiza que la tabla exista antes del primer hit.
		if ( ! class_exists( '\\Akibara\\Business\\Modules\\Wishlist\\Install' ) ) {
			require_once AKIBARA_BUSINESS_DIR . 'src/Modules/Wishlist/Install.php';
		}
		if ( ! class_exists( '\\Akibara\\Business\\Modules\\BackInStock\\Install' ) ) {
			require_once AKIBARA_BUSINESS_DIR . 'src/Modules/BackInStock/Install.php';
		}
		if ( ! class_exists( '\\Akibara\\Business\\Modules\\SeriesFollow\\Install' ) ) {
			require_once AKIBARA_BUSINESS_DIR . 'src/Modules/SeriesFollow/Install.php';
		}
		// Sella la versión únicamente tras comprobar que existe la tabla. dbDelta
		// puede fallar sin lanzar una excepción; sin sello, el módulo reintenta
		// cuando se corrige la causa de instalación.
		$akibara_business_fallidas = array();
		foreach (
			array(
				'Favoritos'       => '\Akibara\Business\Modules\Wishlist\Install',
				'Lista de espera' => '\Akibara\Business\Modules\BackInStock\Install',
				'Sigue tu serie'  => '\Akibara\Business\Modules\SeriesFollow\Install',
			) as $akibara_business_etiqueta => $akibara_business_instalador
		) {
			$akibara_business_instalador::install();
			if ( $akibara_business_instalador::schema_ready() ) {
				update_option( $akibara_business_instalador::OPTION_KEY, $akibara_business_instalador::DB_VERSION, false );
				continue;
			}
			delete_option( $akibara_business_instalador::OPTION_KEY );
			$akibara_business_fallidas[] = $akibara_business_etiqueta;
		}
		if ( array() !== $akibara_business_fallidas ) {
			set_transient( 'akibara_business_tablas_fallidas', $akibara_business_fallidas, HOUR_IN_SECONDS );
		}
		// Plugin::cleanup_orphan_options() retira akb_wishlist_flushed durante el
		// upgrade. El flush de reglas se centraliza en run_upgrade_tasks().

		// EstimatedDelivery refactor pa_pais 1.14.0: inicializa term-meta de
		// lead times en cada término pa_pais (argentina, espana, ...) desde
		// options legacy o DEFAULT_LEAD_TIMES. Idempotente — re-activación no
		// sobrescribe valores existentes.
		if ( ! class_exists( '\\Akibara\\Business\\Modules\\EstimatedDelivery\\Migration' ) ) {
			require_once AKIBARA_BUSINESS_DIR . 'src/Modules/EstimatedDelivery/Settings.php';
			require_once AKIBARA_BUSINESS_DIR . 'src/Modules/EstimatedDelivery/Migration.php';
		}
		\Akibara\Business\Modules\EstimatedDelivery\Migration::on_activation();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		// La desactivación conserva datos y retira los eventos y grupos propios.
		// Las listas se comparten con desinstalación y salud del panel. Se cargan
		// sin autoloader para funcionar incluso si WooCommerce está inactivo;
		// los módulos reprograman sus tareas al reactivarse.
		require_once AKIBARA_BUSINESS_DIR . 'src/Utilities/OwnCrons.php';
		\Akibara\Business\Utilities\OwnCrons::unschedule_all();

		$manifest_class = '\\Akibara\\Business\\Modules\\LlmsManifest\\LlmsManifest';
		if ( ! class_exists( $manifest_class ) ) {
			$manifest_file = AKIBARA_BUSINESS_DIR . 'src/Modules/LlmsManifest/LlmsManifest.php';
			if ( is_readable( $manifest_file ) ) {
				require_once $manifest_file;
			}
		}
		if ( class_exists( $manifest_class ) ) {
			$manifest_class::remove_manifests();
		}
	}
);

// ---------------------------------------------------------------------------
// Home "Recién llegados" stock filter.
// ---------------------------------------------------------------------------
//
// El shortcode [products] de WC no tiene attribute para filtrar por stock_status.
// Aplicamos meta_query solo al shortcode del home con orderby=date para excluir
// onbackorder + outofstock. Quirúrgico — no afecta otras secciones del home ni
// archives.
//
add_filter(
	'woocommerce_shortcode_products_query',
	static function ( array $args, array $atts ): array {
		if ( ! is_front_page() ) {
			return $args;
		}
		if ( ( $atts['orderby'] ?? '' ) !== 'date' ) {
			return $args;
		}
		// El filtro instock sólo corresponde a Recién llegados, sin category.
		// Las vitrinas reservables usan onbackorder y no deben recibirlo.
		if ( ! empty( $atts['category'] ?? '' ) ) {
			return $args;
		}
		$args['meta_query'][] = array(
			'key'   => '_stock_status',
			'value' => 'instock',
		);
		return $args;
	},
	10,
	2
);

// ---------------------------------------------------------------------------
// 📦 ORDENAMIENTO DEL CATÁLOGO
// ---------------------------------------------------------------------------
// WooCommerce no expone los labels del selector en su UI. La lógica de orden se
// mantiene aquí, pero los textos se editan sin código desde Akibara → Textos y
// Copys → Ordenamiento del catálogo.

/**
 * Resuelve un texto configurable del ordenamiento con fallback seguro.
 */
function akibara_business_catalog_order_label( string $option_id, string $fallback ): string {
	$value = sanitize_text_field( (string) get_option( $option_id, $fallback ) );
	return '' !== trim( $value ) ? $value : $fallback;
}

add_filter(
	'woocommerce_catalog_orderby',
	static function ( array $options ): array {
		// El sitio aún no tiene productos con valoraciones. Exponer ese criterio
		// produciría un orden aparentemente arbitrario, por lo que se omite hasta
		// que las reseñas sean una señal real del catálogo.
		unset( $options['rating'] );

		// Cuando hay búsqueda y el usuario no eligió un criterio, akibara-search
		// conserva el orden de su scorer. El valor técnico sigue siendo
		// menu_order, pero la etiqueta debe describir el resultado real.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() es el saneador de WooCommerce (sanitize_text_field recursivo); el sniff sólo conoce la lista de WP core.
		$search_value = isset( $_GET['_buscar'] ) ? wc_clean( wp_unslash( $_GET['_buscar'] ) ) : '';
		// Durante una búsqueda, menu_order representa Relevancia y devuelve el
		// orden del buscador. Conserva esa etiqueta aunque esté seleccionado otro
		// orden; FacetQueryIntegration::user_chose_orderby() comparte el contrato.
		$is_relevance = '' !== trim( (string) $search_value );

		// Usa etiquetas breves es-CL: el glifo y el aria-label ya anuncian la acción
		// de ordenar y el selector móvil necesita evitar prefijos redundantes.
		$labels = array(
			'menu_order' => $is_relevance
				? akibara_business_catalog_order_label( 'akb_copy_order_relevance', 'Relevancia' )
				: akibara_business_catalog_order_label( 'akb_copy_order_featured', 'Destacados' ),
			'title'      => akibara_business_catalog_order_label( 'akb_copy_order_title', 'Título: A-Z' ),
			'popularity' => akibara_business_catalog_order_label( 'akb_copy_order_popularity', 'Más vendidos' ),
			'date'       => akibara_business_catalog_order_label( 'akb_copy_order_date', 'Más recientes' ),
			'price'      => akibara_business_catalog_order_label( 'akb_copy_order_price', 'Menor precio' ),
			'price-desc' => akibara_business_catalog_order_label( 'akb_copy_order_price_desc', 'Mayor precio' ),
		);

		$priority = array( 'menu_order', 'date', 'popularity', 'price', 'price-desc', 'title' );
		$out = array();
		foreach ( $priority as $key ) {
			if ( 'title' === $key || isset( $options[ $key ] ) ) {
				$out[ $key ] = $labels[ $key ] ?? $options[ $key ];
			}
		}
		foreach ( $options as $key => $label ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $labels[ $key ] ?? $label;
			}
		}
		return $out;
	}
);

add_filter(
	'woocommerce_get_catalog_ordering_args',
	static function ( array $args ): array {
		// Replica el lookup que hace WC_Query::get_catalog_ordering_args() para
		// resolver el orderby_value desde GET o el default option.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura del orden del catálogo en un GET público que sólo pinta: no muta nada, así que no hay formulario ni nonce que verificar, y WC_Query hace este mismo lookup sin él.
		$orderby_value = isset( $_GET['orderby'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() es el saneador de WooCommerce (sanitize_text_field recursivo); el sniff sólo conoce la lista de WP core.
			? wc_clean( wp_unslash( $_GET['orderby'] ) )
			: apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.

		if ( 'title' === $orderby_value ) {
			$args['orderby']  = 'title';
			$args['order']    = 'ASC';
			$args['meta_key'] = '';
		}
		return $args;
	}
);

// Normaliza al guardar los números de tomo de un dígito mediante
// Services\VolumeTitle::pad_single_digit(). Exige espacio antes del número
// y un sufijo editorial, un paréntesis final no anidado o fin de título.
// Conserva decimales, fracciones, packs compuestos, nombres con cifras y tomos
// ya rellenados. No cambia el slug ni las URLs.
// Series que superan 99 tomos requieren mantenimiento excepcional probado
// en Docker, con plan reversible conservado localmente; no se distribuyen
// SQL ni migraciones históricas dentro del plugin.
add_filter(
	'wp_insert_post_data',
	static function ( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== 'product' ) {
			return $data;
		}
		if ( ! isset( $data['post_title'] ) || ! is_string( $data['post_title'] ) || '' === $data['post_title'] ) {
			return $data;
		}

		// La regla vive en `VolumeTitle` —con el resto de lo que sabe leer un
		// número de tomo— y no en este cierre: acá no se puede probar, y una
		// regla de catálogo sin prueba es la que se rompe en la siguiente
		// variante que el dueño cargue.
		$data['post_title'] = \Akibara\Business\Services\VolumeTitle::pad_single_digit( $data['post_title'] );

		return $data;
	},
	10,
	2
);
