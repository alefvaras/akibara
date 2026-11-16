<?php
/**
 * DisponiblesRoute — alias histórico /disponibles/ → 301 hacia la tienda.
 *
 * Decisión vigente (ADR-010): el catálogo opera 100 % con stock físico en
 * bodega, así que «disponible» ya no es un segmento aparte: todo lo comprable
 * está en la tienda general. La ruta `/disponibles/` sobrevive únicamente para
 * que la URL histórica (enlaces externos, índices de buscadores) siga
 * resolviendo con un `301 Moved Permanently` en lugar de un 404.
 *
 * Mecánica:
 *   1. `add_rewrite_rule` hace que WordPress reconozca `/disponibles/` y
 *      `/disponibles/page/N/` (sin ellas caerían en 404 antes de llegar a
 *      `template_redirect`).
 *   2. `template_redirect` prioridad 0 emite el 301 hacia el permalink de la
 *      tienda y termina la petición.
 *
 * Esta clase NO sirve contenido en la ruta: no filtra el `WP_Query`, no
 * reescribe título, canonical, breadcrumb ni JSON-LD, y no inyecta la URL en el
 * sitemap de Rank Math (una URL con 301 permanente no debe listarse ahí).
 *
 * Prerequisitos operacionales:
 *   - No debe existir una página estática publicada con el slug `disponibles`.
 *   - Las reglas se registran acá y las flushea `UpgradeManager::run_upgrade_tasks()`
 *     una vez por bump, en `init` 999.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\DisponiblesRoute;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress RedundantCast
 */
final class DisponiblesRoute {

	private const QUERY_VAR  = 'akb_disponibles_route';
	private const ROUTE_PATH = '/disponibles';

	public function register(): void {
		// Las reglas se registran acá y las flushea `UpgradeManager::run_upgrade_tasks()`
		// una vez por bump, en `init` 999 y con las de todos los módulos ya
		// puestas. La guarda propia que vivía aquí (`akb_disponibles_rewrite_version`,
		// autoloaded) comparaba una constante del código contra una option: sólo
		// podía dispararse dentro de un release, que es justo cuando el flush
		// global ya corrió. No podía volver a hacer nada y se cobraba en cada
		// petición del sitio.
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		// Redirección 301 limpia hacia la tienda / catálogo general. Todo el catálogo comprable es stock disponible.
		add_action( 'template_redirect', array( $this, 'redirect_to_shop' ), 0 );
	}

	public function add_rewrite(): void {
		// Regla paginada (más específica) primero: `/disponibles/page/N/` mapea al
		// query var público nativo `paged`. Sin esta regla, `^disponibles/?$`
		// (anclado con `$`) sólo matchea la página 1; `/disponibles/page/2/`…
		// caerían en redirect_canonical antes de llegar al 301 hacia la tienda.
		add_rewrite_rule(
			'^disponibles/page/([0-9]{1,})/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^disponibles/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * @param array<int|string, mixed> $vars
	 * @return array<int|string, mixed>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Redirección 301 limpia hacia la tienda/catálogo general.
	 *
	 * Al operar la tienda 100% con stock físico real, la ruta de disponibles
	 * se consolida en el catálogo general sin perder el SEO acumulado.
	 *
	 * @return void
	 */
	public function redirect_to_shop(): void {
		if ( $this->is_disponibles_route() || isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$shop_id  = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
			$shop_url = $shop_id > 0 ? get_permalink( $shop_id ) : home_url( '/catalogo/' );
			if ( ! is_string( $shop_url ) || '' === $shop_url ) {
				$shop_url = home_url( '/' );
			}
			wp_safe_redirect( $shop_url, 301 );
			exit;
		}
	}

	/**
	 * Reconoce /disponibles/ y /disponibles/page/N/.
	 */
	private function is_disponibles_route(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el URI no se emite, no se guarda y no entra en SQL: sólo se le extrae el path con wp_parse_url() para compararlo contra literales y contra la regex de /page/N.
		$path = rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( self::ROUTE_PATH === $path ) {
			return true;
		}
		return 1 === preg_match( '#^' . preg_quote( self::ROUTE_PATH, '#' ) . '/page/[0-9]+$#', $path );
	}
}
