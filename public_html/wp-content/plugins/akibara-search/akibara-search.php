<?php
/**
 * Plugin Name:       Akibara Search
 * Plugin URI:        https://akibara.cl
 * Description:       Buscador y facetas propios de Akibara — índice invertido, autocomplete y filtrado facetado nativos (JS sin jQuery). Reemplazo standalone de FacetWP + Relevanssi. Reusa las tablas akb_search_* del índice.
 * Version:           0.1.85
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Akibara
 * Text Domain:       akibara-search
 *
 * Extraído del módulo Search de akibara-business (v1.60.0) a plugin propio.
 * El precio con descuento editorial llega al índice por el filtro
 * `akb_search_effective_price` (lo llena akibara-business si está activo; sin
 * ese plugin, precio base WC) — sin dependencia dura de código.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'AKIBARA_SEARCH_VERSION', '0.1.85' );
define( 'AKIBARA_SEARCH_FILE', __FILE__ );
define( 'AKIBARA_SEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_SEARCH_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoload: Composer si existe (dev/tests con vendor/), fallback PSR-4 propio
 * para runtime (prod deploya sin vendor/, igual que akibara-business).
 */
$akb_search_autoload = AKIBARA_SEARCH_DIR . 'vendor/autoload.php';
if ( is_readable( $akb_search_autoload ) ) {
	require_once $akb_search_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'Akibara\\Search\\';
			$base_dir = AKIBARA_SEARCH_DIR . 'src/';

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
}
unset( $akb_search_autoload );

/**
 * Bootstrap: instancia el módulo Search como DUEÑO ÚNICO del subsistema de
 * búsqueda/facetas. Corre en `plugins_loaded` prio 15; akibara-business ya no
 * instancia su módulo Search legacy y solo provee `akb_search_effective_price`.
 * No existe fallback automático por `AKIBARA_SEARCH_VERSION`: si este plugin no
 * está activo/desplegado, business no re-cablea el buscador propio.
 *
 * @return void
 */
function akibara_search_bootstrap(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	( new \Akibara\Search\Search() )->register();

	// Render server-first de facetas/chips/empty-state (form GET + pjax vanilla).
	// Las funciones se auto-guardan por `akibara_search_facets_active()`, así que
	// requerirlo siempre (con el plugin activo) es inocuo con el flag OFF.
	require_once AKIBARA_SEARCH_DIR . 'frontend/facets-render.php';
}
add_action( 'plugins_loaded', 'akibara_search_bootstrap', 15 );

/*
 * Contrato de funciones puente: estas NUEVE exponen el estado y el view-model del
 * buscador para que quien IMPRIMA no toque clases internas ni $wpdb. Viven en el
 * plugin dueño (se movieron desde akibara-business.php en el switch). Todos los
 * consumidores las llaman con `function_exists` → si faltan, degradan a mudo,
 * nunca fatal. El guard `function_exists` evita el redeclare si otro componente
 * define compatibilidad, aunque business ya movió estas funciones fuera durante
 * el switch.
 *
 * Quién llama a cada una (verificado por grep sobre el tema, los cuatro plugins
 * propios y los mu-plugins; hasta 0.1.73 este bloque decía «4 funciones puente»
 * y «6 consumidores del theme», y ninguna de las dos cifras era cierta):
 *
 *   akibara_search_facets_active()     tema (woocommerce/loop/pagination.php,
 *                                      inc/flyout-button.php) + frontend/facets-render.php
 *   akibara_search_suggest_active()    tema (template-parts/header/shell.php,
 *                                      template-parts/header/mobile-drawer.php)
 *   akibara_search_facets_fail_open()  frontend/facets-render.php (marca <meta> del fail-open)
 *   akibara_search_facets_view()       frontend/facets-render.php
 *   akibara_search_facets_context()    frontend/facets-render.php
 *   akibara_search_correction()        frontend/facets-render.php
 *   akibara_search_relaxed_notice()    frontend/facets-render.php
 *   akibara_search_empty_suggestions() frontend/facets-render.php (salida del «sin resultados»)
 *   akibara_search_demand_gaps()       akibara-business (AdminDashboard)
 *
 * O sea: el TEMA sólo llama a dos —`facets_active` y `suggest_active`—; las
 * seis de facetas/avisos las consume `frontend/facets-render.php`, que vive en
 * este mismo plugin, y `demand_gaps` la consume akibara-business. Son globales
 * igual porque el render server-first se enganchó como si fuera del tema y
 * porque business no puede hacer `use` de clases del buscador. Si se mueve o se
 * retira una, esta lista es el inventario: mantenerla al día es lo que evita
 * que un despliegue descoordinado del par Business/Search vuelva a dejar un 500.
 */
if ( ! function_exists( 'akibara_search_facets_active' ) ) {
	/**
	 * @return bool True si el filtrado propio está activo este request.
	 */
	function akibara_search_facets_active(): bool {
		return class_exists( '\\Akibara\\Search\\FacetQueryIntegration' )
			&& null !== \Akibara\Search\FacetQueryIntegration::current();
	}
}
if ( ! function_exists( 'akibara_search_facets_fail_open' ) ) {
	/**
	 * ¿Este request sirve el catálogo en fail-open porque el gate de publicación
	 * está cerrado? Distinto de «sin facetas»: un archivo vacío sin filtros omite
	 * el form a propósito y NO es fail-open.
	 *
	 * @return bool
	 */
	function akibara_search_facets_fail_open(): bool {
		return class_exists( '\\Akibara\\Search\\FacetQueryIntegration' )
			&& \Akibara\Search\FacetQueryIntegration::fail_open_por_gate();
	}
}
if ( ! function_exists( 'akibara_search_facets_view' ) ) {
	/**
	 * @param int|null $total Total del main query si el renderer lo tiene a mano.
	 * @return array<string, mixed>|null View-model de facetas, o null si el sistema está OFF.
	 */
	function akibara_search_facets_view( ?int $total = null ): ?array {
		$current = akibara_search_facets_active()
			? \Akibara\Search\FacetQueryIntegration::current()
			: null;
		return $current ? $current->view( $total ) : null;
	}
}
if ( ! function_exists( 'akibara_search_facets_context' ) ) {
	/**
	 * @return array{facet: string, value: string}|null Contexto implícito del archive (término → faceta), o null.
	 */
	function akibara_search_facets_context(): ?array {
		$current = akibara_search_facets_active()
			? \Akibara\Search\FacetQueryIntegration::current()
			: null;
		return $current ? $current->archive_context() : null;
	}
}
if ( ! function_exists( 'akibara_search_suggest_active' ) ) {
	/**
	 * @return bool True si el autocomplete propio (REST suggest) está activo.
	 */
	function akibara_search_suggest_active(): bool {
		return class_exists( '\\Akibara\\Search\\Search' )
			&& \Akibara\Search\Search::suggest_enabled();
	}
}
if ( ! function_exists( 'akibara_search_correction' ) ) {
	/**
	 * Corrección fuzzy aplicada en la búsqueda de esta request (para la etiqueta
	 * "¿quisiste decir?"). Devuelve el primer par typo→corregido, o null.
	 *
	 * @return array{typo: string, fix: string}|null
	 */
	function akibara_search_correction(): ?array {
		if ( ! class_exists( '\\Akibara\\Search\\Fuzzy' ) ) {
			return null;
		}
		$map = \Akibara\Search\Fuzzy::last_correction();
		if ( empty( $map ) ) {
			return null;
		}
		$typo = (string) array_key_first( $map );
		return array( 'typo' => $typo, 'fix' => (string) $map[ $typo ] );
	}
}

if ( ! function_exists( 'akibara_search_relaxed_notice' ) ) {
	/**
	 * Aviso de la búsqueda que tuvo que soltar el nombre de una editorial para
	 * no devolver cero («no tenemos X de Ivrea; esto es lo que tenemos de X»).
	 * Devuelve la frase ya escrita y escapada, con las dos piezas en negrita,
	 * o null.
	 *
	 * @return string|null
	 */
	function akibara_search_relaxed_notice(): ?string {
		if ( ! class_exists( '\\Akibara\\Search\\BrandRelax' ) ) {
			return null;
		}
		$relaxed = \Akibara\Search\BrandRelax::last();
		if ( ! is_array( $relaxed ) ) {
			return null;
		}
		$frase = \Akibara\Search\BrandRelax::sentence_html( (array) ( $relaxed['ignored'] ?? array() ), (string) ( $relaxed['kept'] ?? '' ) );

		return '' !== $frase ? $frase : null;
	}
}

if ( ! function_exists( 'akibara_search_empty_suggestions' ) ) {
	/**
	 * Salida del aviso de «sin resultados»: la serie que más se parece a lo
	 * escrito y las búsquedas más frecuentes (ver `EmptyStateSuggestions`).
	 * Vacía con el interruptor apagado o ante cualquier fallo de lectura: el
	 * aviso nunca depende de esto.
	 *
	 * @param string $query Lo que escribió el cliente.
	 * @return array{series: array<int, array{name: string, url: string, meta: string}>, popular: array<int, string>}
	 */
	function akibara_search_empty_suggestions( string $query ): array {
		if ( ! class_exists( '\\Akibara\\Search\\EmptyStateSuggestions' ) ) {
			return array(
				'series'  => array(),
				'popular' => array(),
			);
		}
		global $wpdb;

		return \Akibara\Search\EmptyStateSuggestions::from_runtime( $wpdb )->for_query( $query );
	}
}

if ( ! function_exists( 'akibara_search_demand_gaps' ) ) {
	/**
	 * Puente para el ranking «Lo más solicitado» de akibara-business: títulos
	 * BUSCADOS SIN NINGÚN RESULTADO en la ventana dada, ya descartados los
	 * errores de tipeo que el corrector rescata (un typo de Ranma no es un
	 * título que falte). Mismo criterio que el bloque de la pestaña Búsquedas.
	 *
	 * Contrato igual que las funciones puente del theme: business consume con
	 * `function_exists()` y sin `use` de clases del buscador; sin buscador
	 * activo el ranking simplemente no lista huecos. Fail-safe a array vacío
	 * ante cualquier problema de lectura (nunca inventa demanda).
	 *
	 * @param int $days  Ventana en días (default 90).
	 * @param int $limit Máximo de filas (default 10).
	 * @return array<int, array{query: string, s90: int, s30: int}>
	 */
	function akibara_search_demand_gaps( int $days = 90, int $limit = 10 ): array {
		if ( ! class_exists( '\\Akibara\\Search\\DemandGaps' ) ) {
			return array();
		}

		try {
			global $wpdb;

			$out = array();
			foreach ( \Akibara\Search\DemandGaps::rows( $wpdb, $days, $limit ) as $row ) {
				$out[] = array(
					'query' => (string) $row->query_norm,
					's90'   => (int) $row->s,
					's30'   => (int) ( $row->s30 ?? 0 ),
				);
			}

			return $out;
		} catch ( \Throwable $exception ) {
			return array();
		}
	}
}

if ( ! function_exists( 'akibara_search_title_requests' ) ) {
	/**
	 * Puente para el ranking «Lo más solicitado» de akibara-business: títulos
	 * SOLICITADOS POR CLIENTES en la ventana dada.
	 *
	 * @param int $days Ventana en días (default 90).
	 * @return array<string, array{title: string, editorial: string, intent: string, count: int}>
	 */
	function akibara_search_title_requests( int $days = 90 ): array {
		if ( ! class_exists( '\\Akibara\\Search\\TitleRequests' ) ) {
			return array();
		}

		try {
			return \Akibara\Search\TitleRequests::demand_signals( $days );
		} catch ( \Throwable $exception ) {
			return array();
		}
	}
}

/**
 * Activación: asegura el schema del índice (idempotente — si las tablas
 * akb_search_* ya existen con la versión vigente, no hace nada). NO dropea ni
 * migra datos: el plugin adopta las tablas existentes.
 *
 * @return void
 */
function akibara_search_activate(): void {
	if ( class_exists( '\\Akibara\\Search\\Install' ) ) {
		\Akibara\Search\Install::maybe_install();
	}
	// Buckets de precio: sembrar desde el catálogo si ya hay índice y el modo es
	// AUTO (sin índice no persiste nada y la faceta queda oculta hasta el primer
	// reindex; nunca pisa una curaduría MANUAL) [gate DISEÑO v3.1 §1].
	if ( class_exists( '\\Akibara\\Search\\PriceBuckets' ) ) {
		\Akibara\Search\PriceBuckets::maybe_refresh_auto();
	}
}
register_activation_hook( __FILE__, 'akibara_search_activate' );
