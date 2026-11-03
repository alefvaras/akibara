<?php
/**
 * Registra Favoritos como endpoint de la cuenta de WooCommerce.
 *
 * El endpoint se añade a WC()->query->get_query_vars() además de la regla global
 * de rewrite. Así la detección de archivos y listados de otros componentes sigue
 * el mismo contrato que orders, downloads y los endpoints nativos de la cuenta.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

	public const SLUG = 'favoritos';
	public const QUERY_VAR = 'favoritos';

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::SLUG . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::SLUG . '_endpoint', array( $this, 'render' ) );

		// El flush de reglas NO se guarda acá. Lo hace `Plugin::run_upgrade_tasks()`
		// una vez por bump de versión, con las reglas de todos los módulos ya
		// registradas (corre en `init` 999). La guarda propia que vivía aquí
		// comparaba contra `Install::DB_VERSION`, que sólo cambia dentro de un
		// release —o sea justo cuando el flush global ya corrió—, así que no podía
		// volver a dispararse nunca: sólo cobraba una lectura de option en CADA
		// petición del sitio, para siempre.
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::SLUG, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( array $vars ): array {
		$vars[ self::QUERY_VAR ] = self::SLUG;
		return $vars;
	}

	public function menu_item( array $items ): array {
		// Insertar "Favoritos" después de "Dashboard" y antes de "Pedidos".
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::SLUG ] = 'Favoritos';
			}
		}
		// Si por algún motivo no había "dashboard", insertar al inicio.
		if ( ! isset( $new[ self::SLUG ] ) ) {
			$new = array( self::SLUG => 'Favoritos' ) + $items;
		}
		return $new;
	}

	public function endpoint_title( string $title ): string {
		return 'Favoritos';
	}

	public function render(): void {
		$render = new Render();
		echo $render->account_endpoint_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render::account_endpoint_html() devuelve marcado ya escapado (esc_url, esc_attr y esc_html en cada dato); escaparlo aquí imprimiría las etiquetas como texto.
	}

	public static function url(): string {
		$account = function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'myaccount' )
			: home_url( '/mi-cuenta/' );
		return trailingslashit( $account . self::SLUG );
	}
}
