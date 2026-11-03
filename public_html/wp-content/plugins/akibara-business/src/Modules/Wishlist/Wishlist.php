<?php
/**
 * Wishlist — orchestrator del módulo de favoritos propio Akibara.
 *
 * Reemplaza al plugin externo WPC Smart Wishlist. Login required, storage
 * propio (`wp_akb_wishlist`), endpoint `/mi-cuenta/favoritos/` registrado
 * correctamente en `WC()->query`.
 *
 * Compone: Install (schema) + Storage (CRUD) + Ajax (toggle/count) + Endpoint
 * (/mi-cuenta/favoritos/) + Render (HTML) + Button (corazón loop/single).
 *
 * Sesión 1: coexiste con WishlistEmpty + plugin WPC. Sesión 2 elimina el
 * plugin viejo y los archivos legacy.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class Wishlist {

	private Ajax $ajax;
	private Endpoint $endpoint;
	private Button $button;

	public function __construct() {
		$this->ajax     = new Ajax();
		$this->endpoint = new Endpoint();
		$this->button   = new Button();
	}

	public function register(): void {
		// dbDelta lazy si la versión guardada difiere (auto-migrate sin
		// reactivar plugin tras git pull).
		Install::maybe_install();
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );

		$this->ajax->register();
		$this->endpoint->register();

		if ( ! is_admin() ) {
			$this->button->register();
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		// Privacidad: la tabla guarda datos de una persona identificada, así que
		// las herramientas nativas de WP (Exportar/Borrar datos personales) deben
		// verla. Mismo contrato que BackInStock.
		( new PrivacyHandler() )->register();

		// Cleanup: borrar entradas huérfanas cuando se elimina un producto.
		add_action( 'before_delete_post', array( Storage::class, 'on_product_deleted' ) );

		// Cleanup: la wishlist muere con la cuenta (wp_delete_user() no toca
		// tablas custom). `deleted_user` y no `delete_user`: si el borrado se
		// aborta a mitad, no queremos haber tirado los favoritos antes.
		add_action( 'deleted_user', array( Storage::class, 'on_user_deleted' ) );
	}

	/**
	 * Keep the cover anchor rules when LiteSpeed generates page-specific UCSS.
	 *
	 * @param array<int, string> $selectors Existing UCSS selector allowlist.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-product-cover-wrap';
		// Shim de anclaje del corazón: si el UCSS lo purga, el pointer-events:none
		// se pierde y el shim taparía el click de TODO el cover en prod.
		$selectors[] = '.akb-wl-anchor';
		// El aviso del invitado (`.akb-wl-login`) ya no está aquí: sus reglas
		// viven en su propia hoja, que wishlist.js pide y LiteSpeed no procesa.

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Registra assets siempre y los encola temprano donde se imprimen corazones.
	 *
	 * El CSS debe estar en head para mantener la posición de los botones y evitar
	 * saltos de layout. ensure_assets() cubre superficies no previstas mediante
	 * encolado tardío: una extensión puede añadir corazones sin dejarlos sin estilo.
	 */
	public function enqueue_assets(): void {
		$this->register_assets();

		if ( $this->surface_renders_hearts() ) {
			self::ensure_assets();
		}
	}

	/**
	 * Encola lo ya registrado si aún no está en la cola.
	 *
	 * Se llama desde `Button::html()` y desde el endpoint de favoritos, así que
	 * puede ejecutarse con `wp_head` ya impreso: WordPress imprime los estilos
	 * tardíos en el footer (`print_late_styles`) y el script ya iba `in_footer`.
	 */
	public static function ensure_assets(): void {
		if ( ! function_exists( 'wp_style_is' ) || ! did_action( 'wp_enqueue_scripts' ) ) {
			return;
		}
		if ( ! wp_style_is( 'akb-wishlist', 'registered' ) ) {
			return;
		}
		wp_enqueue_style( 'akb-wishlist' );
		wp_enqueue_script( 'akb-wishlist' );
	}

	/**
	 * Superficies que imprimen corazones en el HTML servido.
	 *
	 * Verificado en Docker el  contando `akb-wl-toggle`: portada 96,
	 * /tienda/ 72, /?s=naruto&post_type=product 48, /carrito/ 12 (cross-sells
	 * en PHP), ficha de producto 1. El endpoint de favoritos entra aunque la
	 * lista esté vacía: el estado vacío usa `.akb-wl-empty__icon`, que vive en
	 * la misma hoja.
	 */
	private function surface_renders_hearts(): bool {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}

		if ( function_exists( 'is_shop' )
			&& ( is_shop() || is_product() || is_product_taxonomy() || is_cart() ) ) {
			return true;
		}

		if ( is_search() || is_front_page() ) {
			return true;
		}

		// Sólo el endpoint de favoritos, no toda Mi cuenta: /mi-cuenta/ (el
		// formulario de acceso) tiene 0 corazones y es página de tráfico.
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( Endpoint::SLUG );
	}

	private function register_assets(): void {
		wp_register_style(
			'akb-wishlist',
			AKIBARA_BUSINESS_URL . 'assets/css/wishlist.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/wishlist.css' )
		);

		wp_register_script(
			'akb-wishlist',
			AKIBARA_BUSINESS_URL . 'assets/js/wishlist.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/wishlist.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'akb-wishlist',
			'akbWishlist',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax::NONCE_ACTION ),
				'isLogged'   => is_user_logged_in(),
				'isLoggedIn' => is_user_logged_in(),
				'savedIds'  => is_user_logged_in() ? Storage::product_ids() : array(),
				//: con el aviso encendido en el panel (apagado de fábrica),
				// el invitado ve un aviso en la página en vez de salir de ella
				// (GuestPrompt). Apagado, navega al acceso como siempre.
				'guestPrompt' => GuestPrompt::enabled(),
				// La hoja del aviso de invitado se carga bajo demanda cuando se pulsa un
				// corazón y aplica GuestPrompt, sin bloquear el render del resto de visitantes.

				'guestStyle'  => AKIBARA_BUSINESS_URL . 'assets/css/wishlist-guest-prompt.css?ver=' . PluginAssetVersion::for_path( 'assets/css/wishlist-guest-prompt.css' ),
				'loginUrl'  => function_exists( 'wc_get_page_permalink' )
					? (string) wc_get_page_permalink( 'myaccount' )
					: wp_login_url(),
				'labels'    => array(
					'save'     => 'Guardar',
					'saved'    => 'Guardado',
					'addAria'  => 'Guardar en favoritos',
					'savedAria' => 'Quitar de favoritos',
					// Variantes con nombre de producto (%s ← data-name del botón):
					// nombres accesibles únicos por card (N corazones idénticos en
					// la grilla no se distinguen en el rotor del lector de pantalla).
					'addAriaNamed'           => 'Guardar «%s» en favoritos',
					'savedAriaNamed'         => 'Quitar «%s» de favoritos',
					'announceAdded'          => '«%s» guardado en favoritos',
					'announceRemoved'        => '«%s» quitado de favoritos',
					'announceAddedGeneric'   => 'Guardado en favoritos',
					'announceRemovedGeneric' => 'Quitado de favoritos',
					'guestClose'             => 'Cerrar',
				) + GuestPrompt::labels(),
			)
		);
	}
}
